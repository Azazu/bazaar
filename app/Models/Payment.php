<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    protected $fillable = [
        'order_id',
        'gateway',
        'transaction_id',
        'status',
        'amount_cents',
        'currency',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
