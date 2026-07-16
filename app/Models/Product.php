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

class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    protected $fillable = ['store_id', 'title', 'slug', 'description', 'price_cents', 'currency', 'status'];

    protected function casts(): array
    {
        return ['status' => ProductStatus::class];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class);
    }

    public function scopePublished(Builder $query): void
    {
        $query->where('status', ProductStatus::Published);
    }

    public function reviews(): MorphMany
    {
        return $this->morphMany(Review::class, 'reviewable');
    }

    /** Average of approved review ratings (0.0 if none). */
    public function averageRating(): float
    {
        return (float) round($this->reviews()->approved()->avg('rating') ?? 0, 1);
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
