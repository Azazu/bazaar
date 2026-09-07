<?php

namespace App\Models;

use App\Enums\ProductStatus;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Laravel\Scout\Searchable;

/**
 * @property-read float|string|null $reviews_avg_rating  present after withRating()
 * @property-read int $reviews_count                     present after withRating()
 */
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory, Searchable;

    protected $fillable = ['store_id', 'title', 'slug', 'description', 'price_cents', 'currency', 'status'];

    protected function casts(): array
    {
        return ['status' => ProductStatus::class];
    }

    /** @return BelongsTo<Store, $this> */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /** @return HasMany<ProductVariant, $this> */
    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    /** @return BelongsToMany<Category, $this> */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class);
    }

    /** @param  Builder<Product>  $query */
    public function scopePublished(Builder $query): void
    {
        $query->where('status', ProductStatus::Published);
    }

    /**
     * What the search index knows about a product. With the database driver these keys are
     * the columns matched with LIKE; with Meilisearch they become the indexed document.
     *
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        return [
            'title' => $this->title,
            'description' => $this->description,
        ];
    }

    /** Drafts and archived products never enter the index (matters for Meilisearch, not LIKE). */
    public function shouldBeSearchable(): bool
    {
        return $this->status === ProductStatus::Published;
    }

    /**
     * Eager-load the public rating (average + count of approved reviews) as
     * `reviews_avg_rating` / `reviews_count` — one aggregate query, no N+1 on lists.
     *
     * @param  Builder<Product>  $query
     */
    public function scopeWithRating(Builder $query): void
    {
        $query
            ->withAvg(['reviews as reviews_avg_rating' => fn (Builder $q) => $q->where('approved', true)], 'rating')
            ->withCount(['reviews as reviews_count' => fn (Builder $q) => $q->where('approved', true)]);
    }

    /**
     * Catalog filters. Expects already-validated, normalized input
     * (see ProductIndexRequest::filters()).
     *
     * @param  Builder<Product>  $query
     * @param  array{category?: ?string, min_price?: ?int, max_price?: ?int, in_stock?: bool, min_rating?: ?int, sort?: ?string}  $filters
     */
    public function scopeFilter(Builder $query, array $filters): void
    {
        $query
            ->when($filters['category'] ?? null, fn (Builder $q, string $slug) => $q->whereRelation('categories', 'slug', $slug))
            ->when(isset($filters['min_price']), fn (Builder $q) => $q->where('price_cents', '>=', $filters['min_price']))
            ->when(isset($filters['max_price']), fn (Builder $q) => $q->where('price_cents', '<=', $filters['max_price']))
            ->when($filters['in_stock'] ?? false, fn (Builder $q) => $q->whereHas('variants', fn (Builder $v) => $v->where('stock', '>', 0)))
            // Average of approved reviews >= N. A grouped subquery rather than HAVING on the
            // withRating() alias: portable (SQLite needs GROUP BY before HAVING) and independent of it.
            ->when($filters['min_rating'] ?? null, fn (Builder $q, int $min) => $q->whereIn('id', Review::query()
                ->select('reviewable_id')
                ->where('reviewable_type', $q->getModel()->getMorphClass())
                ->approved()
                ->groupBy('reviewable_id')
                ->havingRaw('AVG(rating) >= ?', [$min])));

        match ($filters['sort'] ?? 'newest') {
            'price_asc' => $query->orderBy('price_cents'),
            'price_desc' => $query->orderByDesc('price_cents'),
            default => $query->latest(),
        };
    }

    /** @return MorphMany<Review, $this> */
    public function reviews(): MorphMany
    {
        return $this->morphMany(Review::class, 'reviewable');
    }

    /** Average of approved review ratings (0.0 if none). */
    public function averageRating(): float
    {
        return round((float) ($this->reviews()->approved()->avg('rating') ?? 0), 1);
    }

    /** Has this user bought this product (in a paid order)? Gates who may review. */
    public function purchasedBy(User $user): bool
    {
        return OrderItem::query()
            ->whereIn('product_variant_id', $this->variants()->select('id'))
            ->whereHas('order', fn (Builder $q) => $q->where('buyer_id', $user->id)->where('status', 'paid'))
            ->exists();
    }
}
