<?php

namespace App\Models;

use App\States\Order\OrderState;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\ModelStates\HasStates;

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

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
