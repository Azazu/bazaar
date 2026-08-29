<?php

use App\Models\Category;
use Illuminate\Support\Facades\Cache;

it('caches the category list and invalidates it when a category changes', function () {
    Category::factory()->create(['name' => 'Bags']);

    expect(Category::cachedList()->pluck('name')->all())->toBe(['Bags'])
        ->and(Cache::has('categories:all'))->toBeTrue();

    Category::factory()->create(['name' => 'Shoes']); // saved → flush

    expect(Cache::has('categories:all'))->toBeFalse()
        ->and(Category::cachedList()->pluck('name')->all())->toBe(['Bags', 'Shoes']);
});

it('caches the category tree and invalidates it on delete', function () {
    $parent = Category::factory()->create(['name' => 'Home']);
    $child = Category::factory()->create(['name' => 'Kitchen', 'parent_id' => $parent->id]);

    $tree = Category::cachedTree();

    expect($tree)->toHaveCount(1)
        ->and($tree->first()->children)->toHaveCount(1)
        ->and(Cache::has('categories:all'))->toBeTrue();

    $child->delete();

    expect(Cache::has('categories:all'))->toBeFalse()
        ->and(Category::cachedTree()->first()->children)->toHaveCount(0);
});

it('caches plain rows, never model objects', function () {
    // Laravel refuses to unserialize classes out of the cache (config/cache.php
    // `serializable_classes` = false), so an Eloquent collection in there would only
    // fail on a real cache driver — the array driver used in tests never serializes.
    Category::factory()->create();
    Category::cachedList();

    $cached = Cache::get('categories:all');

    expect($cached)->toBeArray()
        ->and($cached[0])->toBeArray()
        ->and(collect($cached)->flatten()->filter(fn ($v) => is_object($v)))->toBeEmpty();
});
