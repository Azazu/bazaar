<?php

namespace App\Models;

use App\Enums\StoreStatus;
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

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('status', StoreStatus::Active);
    }
}
