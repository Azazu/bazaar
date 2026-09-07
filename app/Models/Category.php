<?php

namespace App\Models;

use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;

class Category extends Model
{
    /** @use HasFactory<CategoryFactory> */
    use HasFactory;

    private const CACHE_KEY = 'categories:all';

    protected $fillable = ['parent_id', 'name', 'slug'];

    /** Categories change rarely and are read on every catalog page: cache, invalidate on write. */
    protected static function booted(): void
    {
        static::saved(fn () => static::flushCache());
        static::deleted(fn () => static::flushCache());
    }

    /**
     * Flat, name-sorted list (filter dropdowns).
     *
     * @return EloquentCollection<int, Category>
     */
    public static function cachedList(): EloquentCollection
    {
        /** @var EloquentCollection<int, Category> $categories */
        $categories = static::hydrate(self::cachedRows());

        return $categories;
    }

    /**
     * Top-level categories with their `children` relation set (the public tree).
     *
     * @return EloquentCollection<int, Category>
     */
    public static function cachedTree(): EloquentCollection
    {
        $all = static::cachedList();
        $byParent = $all->groupBy('parent_id');

        return $all
            ->whereNull('parent_id')
            ->each(fn (self $category) => $category->setRelation(
                'children',
                new EloquentCollection($byParent->get($category->id, [])),
            ))
            ->values();
    }

    /** Also for seeders, which run without model events. */
    public static function flushCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * The cache holds plain rows, never model objects: Laravel refuses to unserialize
     * classes out of the cache by default (config/cache.php `serializable_classes`),
     * so a cached Eloquent collection would write fine and blow up on read.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function cachedRows(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, fn () => static::query()
            ->orderBy('name')
            ->get(['id', 'parent_id', 'name', 'slug'])
            ->toArray());
    }

    /** @return BelongsTo<Category, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    /** @return HasMany<Category, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id');
    }

    /** @return BelongsToMany<Product, $this> */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class);
    }
}
