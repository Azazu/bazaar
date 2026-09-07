<?php

namespace App\Models;

use App\Enums\StoreStatus;
use App\Exceptions\DeletionBlockedException;
use App\Jobs\ProcessImage;
use App\Services\Media\ImageProcessor;
use Database\Factories\StoreFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Stores are archived (soft-deleted), never removed: their sub-orders and payouts are
 * financial history and keep pointing at the archived row. An archived store disappears
 * from the catalog and from its owner's dashboard; the database refuses a hard delete
 * while orders reference it.
 */
class Store extends Model
{
    /** @use HasFactory<StoreFactory> */
    use HasFactory, SoftDeletes;

    /** Sub-order states in which the vendor still has work or money outstanding. */
    private const array OPEN_SUB_ORDER_STATES = ['pending', 'paid', 'processing', 'shipped'];

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

        // Archiving must not strand a buyer: a store with orders in flight stays until they are done.
        static::deleting(function (self $store) {
            if (! $store->isForceDeleting() && $store->hasOpenSubOrders()) {
                throw new DeletionBlockedException('This store still has orders in progress; they must be finished first.');
            }
        });

        // Archived stores keep their logo (history is still shown); only a hard delete removes files.
        static::forceDeleted(function (self $store) {
            if ($store->logo) {
                app(ImageProcessor::class)->deletePath($store->logo);
            }
        });
    }

    public function hasOpenSubOrders(): bool
    {
        return $this->subOrders()->whereIn('status', self::OPEN_SUB_ORDER_STATES)->exists();
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
