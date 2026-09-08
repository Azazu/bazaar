<?php

namespace App\Models;

use App\Exceptions\DeletionBlockedException;
use Database\Factories\ProductVariantFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductVariant extends Model
{
    /** @use HasFactory<ProductVariantFactory> */
    use HasFactory;

    protected $fillable = ['product_id', 'sku', 'name', 'price_cents', 'stock'];

    /** Order states in which a line still has to be shipped (or paid for). */
    public const array OPEN_ORDER_STATES = ['pending', 'paid', 'processing', 'shipped'];

    /**
     * A variant somebody has ordered but not yet received can't be removed: the order needs it
     * to be shipped and, on payment, to be taken off the shelf. Finished orders keep their
     * own snapshot of the line (title, name, SKU, price), so removal is fine after that.
     */
    protected static function booted(): void
    {
        static::deleting(function (self $variant) {
            if ($variant->isInOpenOrders()) {
                throw new DeletionBlockedException("Variant {$variant->sku} is part of an order that is still in progress and cannot be removed yet.");
            }
        });
    }

    public function isInOpenOrders(): bool
    {
        return $this->orderItems()
            ->whereHas('order', fn (Builder $order) => $order->whereIn('status', self::OPEN_ORDER_STATES))
            ->exists();
    }

    /** @return HasMany<OrderItem, $this> */
    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
