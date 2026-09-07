<?php

namespace App\Models;

use App\Enums\StoreStatus;
use App\Jobs\ProcessImage;
use App\Services\Media\ImageProcessor;
use Database\Factories\StoreFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Store extends Model
{
    /** @use HasFactory<StoreFactory> */
    use HasFactory;

    protected $fillable = ['owner_id', 'name', 'slug', 'description', 'logo', 'status'];

    protected function casts(): array
    {
        return ['status' => StoreStatus::class];
    }

    protected static function booted(): void
    {
        // Same pipeline as product images: resize in the background, keep the disk tidy.
        static::saved(function (self $store) {
            if (! $store->wasChanged('logo')) {
                return;
            }

            if ($previous = $store->getOriginal('logo')) {
                app(ImageProcessor::class)->deletePath($previous);
            }

            if ($store->logo) {
                ProcessImage::dispatch($store->logo);
            }
        });

        static::deleted(function (self $store) {
            if ($store->logo) {
                app(ImageProcessor::class)->deletePath($store->logo);
            }
        });
    }

    /** Logo URL for the given size, or null when the store has none. */
    public function logoUrl(string $size = 'thumb'): ?string
    {
        return $this->logo ? ImageProcessor::urlFor($this->logo, $size) : null;
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return HasMany<Product, $this> */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /** @return HasMany<SubOrder, $this> */
    public function subOrders(): HasMany
    {
        return $this->hasMany(SubOrder::class);
    }

    /** @return HasMany<Payout, $this> */
    public function payouts(): HasMany
    {
        return $this->hasMany(Payout::class);
    }

    /** @param  Builder<Store>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', StoreStatus::Active);
    }
}
