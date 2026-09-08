<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The HI-001 migration runs over a live database, not only migrate:fresh. MySQL DDL isn't
 * transactional, so it must refuse *before* touching anything when the data can't take the
 * new constraint, and it must be repeatable once the data is fixed.
 */

const PROTECT_HISTORY = '2026_09_07_130000_protect_financial_history';

/** Roll back to the schema as it was before the migration under test (and anything newer). */
function schemaBeforeHistoryProtection(): void
{
    $newer = DB::table('migrations')->where('migration', '>', PROTECT_HISTORY)->count();
    Artisan::call('migrate:rollback', ['--step' => $newer + 1]);

    expect(DB::table('migrations')->where('migration', PROTECT_HISTORY)->exists())->toBeFalse()
        ->and(Schema::hasColumn('users', 'deleted_at'))->toBeFalse()
        ->and(Schema::hasColumn('stores', 'deleted_at'))->toBeFalse();
}

/** @return int the id of a product with the given store_id, inserted the way the old schema allowed */
function legacyProduct(?int $storeId): int
{
    return DB::table('products')->insertGetId([
        'store_id' => $storeId,
        'title' => 'Legacy',
        'slug' => 'legacy-'.uniqid(),
        'price_cents' => 100,
        'currency' => 'USD',
        'status' => 'draft',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('refuses to run over orphan products and changes nothing', function () {
    schemaBeforeHistoryProtection();
    $orphan = legacyProduct(null);

    expect(fn () => Artisan::call('migrate'))->toThrow(RuntimeException::class, 'Nothing has been changed');

    expect(DB::table('migrations')->where('migration', PROTECT_HISTORY)->exists())->toBeFalse()
        ->and(Schema::hasColumn('users', 'deleted_at'))->toBeFalse()      // the first DDL statement never ran
        ->and(Schema::hasColumn('stores', 'deleted_at'))->toBeFalse()
        ->and(DB::table('products')->where('id', $orphan)->exists())->toBeTrue();
});

it('upgrades an existing schema once the data is fixed, and is a no-op when run again', function () {
    schemaBeforeHistoryProtection();
    $storeId = DB::table('stores')->insertGetId([
        'owner_id' => DB::table('users')->insertGetId(['name' => 'Owner', 'email' => 'owner@example.test', 'password' => 'x', 'created_at' => now(), 'updated_at' => now()]),
        'name' => 'Shop', 'slug' => 'shop', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $orphan = legacyProduct(null);
    $kept = legacyProduct($storeId);

    // Operator fixes the data the way the error message says, then simply runs migrate again.
    DB::table('products')->where('id', $orphan)->update(['store_id' => $storeId]);
    Artisan::call('migrate');

    expect(DB::table('migrations')->where('migration', PROTECT_HISTORY)->exists())->toBeTrue()
        ->and(Schema::hasColumn('users', 'deleted_at'))->toBeTrue()
        ->and(Schema::hasColumn('stores', 'deleted_at'))->toBeTrue()
        ->and(DB::table('products')->whereIn('id', [$orphan, $kept])->count())->toBe(2)
        ->and(collect(Schema::getForeignKeys('orders'))->firstWhere('columns', ['buyer_id'])['on_delete'])->toMatch('/restrict|no action/i')
        ->and(collect(Schema::getForeignKeys('products'))->firstWhere('columns', ['store_id']))->not->toBeNull();

    // Every step is guarded, so running the body again is harmless (an interrupted run can be repeated).
    $migration = require database_path('migrations/'.PROTECT_HISTORY.'.php');
    $migration->up();
    expect(collect(Schema::getForeignKeys('payments'))->where('columns', ['order_id'])->count())->toBe(1);
});
