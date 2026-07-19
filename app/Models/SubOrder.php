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

class SubOrder extends Model
{
    /** @use HasFactory<SubOrderFactory> */
    use HasFactory, HasStates;

    protected $fillable = ['order_id', 'store_id', 'status', 'subtotal_cents'];

    protected function casts(): array
    {
        return ['status' => SubOrderState::class];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function payout(): HasOne
    {
        return $this->hasOne(Payout::class);
    }
}
