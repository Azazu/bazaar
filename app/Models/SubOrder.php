<?php

namespace App\Models;

use App\States\SubOrder\SubOrderState;
use Database\Factories\SubOrderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\ModelStates\HasStates;

/**
 * @property SubOrderState $status spatie/model-states cast — declared so static analysis sees the state object, not a string
 */
class SubOrder extends Model
{
    /** @use HasFactory<SubOrderFactory> */
    use HasFactory, HasStates;

    protected $fillable = ['order_id', 'store_id', 'status', 'subtotal_cents'];

    protected function casts(): array
    {
        return ['status' => SubOrderState::class];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * The store this belongs to — including one that has since been archived, because
     * this row is history and must stay readable.
     *
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class)->withTrashed();
    }

    /** @return HasMany<OrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /** @return HasOne<Payout, $this> */
    public function payout(): HasOne
    {
        return $this->hasOne(Payout::class);
    }
}
