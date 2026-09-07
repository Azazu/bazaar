<?php

namespace App\Models;

use App\States\Order\OrderState;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\ModelStates\HasStates;

/**
 * @property OrderState $status spatie/model-states cast — declared so static analysis sees the state object, not a string
 */
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory, HasStates;

    protected $fillable = [
        'buyer_id',
        'status',
        'currency',
        'subtotal_cents',
        'shipping_cents',
        'discount_cents',
        'coupon_id',
        'total_cents',
        'shipping_address',
        'shipping_method',
    ];

    protected function casts(): array
    {
        return [
            'shipping_address' => 'array',
            'status' => OrderState::class,
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    /** @return BelongsTo<Coupon, $this> */
    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    /** @return HasMany<OrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /** @return HasMany<SubOrder, $this> */
    public function subOrders(): HasMany
    {
        return $this->hasMany(SubOrder::class);
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
