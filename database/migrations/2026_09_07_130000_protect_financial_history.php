<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Orders, their lines, sub-orders, payments and payouts are financial history: nothing may
 * cascade into them. Accounts and stores are soft-deleted (anonymised / archived) instead of
 * removed, and every foreign key that used to cascade now restricts, so even a raw delete
 * cannot take the history with it. Products get the mandatory store FK they always implied.
 *
 * MySQL DDL is not transactional, so this migration is written to be safe on a live schema:
 * the one thing that can fail on existing data (making products.store_id mandatory) is
 * checked *before* any statement runs, and every step is skipped when it has already been
 * applied, so an interrupted run can simply be repeated.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $this->assertNoOrphanProducts();

        if (! Schema::hasColumn('users', 'deleted_at')) {
            Schema::table('users', fn (Blueprint $table) => $table->softDeletes());
        }

        if (! Schema::hasColumn('stores', 'deleted_at')) {
            Schema::table('stores', fn (Blueprint $table) => $table->softDeletes());
        }

        $this->restrictOnDelete('stores', 'owner_id', 'users');

        if ($this->foreignKey('products', 'store_id') === null) {
            Schema::table('products', function (Blueprint $table) {
                $table->foreignId('store_id')->nullable(false)->change();
                $table->foreign('store_id')->references('id')->on('stores')->restrictOnDelete();
            });
        }

        $this->restrictOnDelete('orders', 'buyer_id', 'users');
        $this->restrictOnDelete('order_items', 'order_id', 'orders');
        $this->restrictOnDelete('order_items', 'sub_order_id', 'sub_orders');
        $this->restrictOnDelete('sub_orders', 'order_id', 'orders');
        $this->restrictOnDelete('sub_orders', 'store_id', 'stores');
        $this->restrictOnDelete('payments', 'order_id', 'orders');
        $this->restrictOnDelete('payouts', 'store_id', 'stores');
        $this->restrictOnDelete('payouts', 'sub_order_id', 'sub_orders');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $this->cascadeOnDelete('payouts', 'store_id', 'stores');
        $this->cascadeOnDelete('payouts', 'sub_order_id', 'sub_orders');
        $this->cascadeOnDelete('payments', 'order_id', 'orders');
        $this->cascadeOnDelete('sub_orders', 'order_id', 'orders');
        $this->cascadeOnDelete('sub_orders', 'store_id', 'stores');
        $this->cascadeOnDelete('order_items', 'order_id', 'orders');
        $this->cascadeOnDelete('order_items', 'sub_order_id', 'sub_orders');
        $this->cascadeOnDelete('orders', 'buyer_id', 'users');

        if ($this->foreignKey('products', 'store_id') !== null) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropForeign(['store_id']);
                $table->foreignId('store_id')->nullable()->change();
            });
        }

        $this->cascadeOnDelete('stores', 'owner_id', 'users');

        if (Schema::hasColumn('stores', 'deleted_at')) {
            Schema::table('stores', fn (Blueprint $table) => $table->dropSoftDeletes());
        }

        if (Schema::hasColumn('users', 'deleted_at')) {
            Schema::table('users', fn (Blueprint $table) => $table->dropSoftDeletes());
        }
    }

    /**
     * Preflight: products.store_id becomes NOT NULL below; a product without a store, or
     * pointing at a store that no longer exists, would make that statement fail half-way
     * through the migration. Refuse up front, before anything has changed.
     */
    private function assertNoOrphanProducts(): void
    {
        $orphans = DB::table('products')
            ->where(fn ($query) => $query
                ->whereNull('store_id')
                ->orWhereNotIn('store_id', DB::table('stores')->select('id')))
            ->count();

        if ($orphans > 0) {
            throw new RuntimeException(
                "Cannot make products.store_id mandatory: {$orphans} product(s) have no store or reference a missing one. "
                .'Assign them to a store or delete them, then run the migration again. Nothing has been changed.'
            );
        }
    }

    /** Re-point an existing foreign key at ON DELETE RESTRICT (no-op if it already is). */
    private function restrictOnDelete(string $table, string $column, string $references): void
    {
        $this->setOnDelete($table, $column, $references, 'restrict');
    }

    private function cascadeOnDelete(string $table, string $column, string $references): void
    {
        $this->setOnDelete($table, $column, $references, 'cascade');
    }

    private function setOnDelete(string $table, string $column, string $references, string $onDelete): void
    {
        $current = $this->foreignKey($table, $column);
        $currentRule = strtolower((string) ($current['on_delete'] ?? ''));

        // MySQL and SQLite both report RESTRICT; "no action" is the same thing in SQL terms.
        if ($current !== null && ($currentRule === $onDelete || ($onDelete === 'restrict' && $currentRule === 'no action'))) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($column, $references, $onDelete, $current) {
            if ($current !== null) {
                $blueprint->dropForeign([$column]);
            }

            $blueprint->foreign($column)->references('id')->on($references)->onDelete($onDelete);
        });
    }

    /** @return array<string, mixed>|null the foreign key on $table.$column as the schema reports it */
    private function foreignKey(string $table, string $column): ?array
    {
        foreach (Schema::getForeignKeys($table) as $foreignKey) {
            if ($foreignKey['columns'] === [$column]) {
                return $foreignKey;
            }
        }

        return null;
    }
};
