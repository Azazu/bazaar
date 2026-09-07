<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Exceptions\DeletionBlockedException;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable, SoftDeletes;

    /** Order states in which the buyer still owes or is owed something. */
    private const array OPEN_ORDER_STATES = ['pending', 'paid', 'processing', 'shipped'];

    /**
     * Deleting an account never deletes its history. The row is soft-deleted (which is what
     * logs the person out for good and hides them from every query) and personal data on it
     * is scrubbed; orders, payments and payouts keep pointing at the anonymised row, and the
     * database refuses a hard delete while they exist. An account with an order still in
     * flight, or a store with one, can't go yet. Close accounts through AccountService: it
     * archives the store first and holds the row locks that make this check race-free.
     */
    protected static function booted(): void
    {
        static::deleting(function (self $user) {
            if ($user->isForceDeleting()) {
                return; // let the database's restrict FKs have the last word
            }

            if ($user->hasOpenOrders()) {
                throw new DeletionBlockedException('This account still has orders in progress; finish or cancel them first.');
            }

            if ($user->store?->hasOpenSubOrders()) {
                throw new DeletionBlockedException('This account\'s store still has orders in progress; finish them first.');
            }

            $user->anonymise();
        });
    }

    public function hasOpenOrders(): bool
    {
        return $this->orders()->whereIn('status', self::OPEN_ORDER_STATES)->exists();
    }

    /** Strip everything that identifies the person; the id stays as the anchor for their history. */
    private function anonymise(): void
    {
        $this->forceFill([
            'name' => 'Deleted user',
            'email' => "deleted-{$this->id}@users.invalid", // keeps the unique index happy
            'email_verified_at' => null,
            'password' => Str::random(64),
            'remember_token' => null,
        ])->saveQuietly();

        $this->tokens()->delete();
        $this->syncRoles([]);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /** @return HasMany<Order, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'buyer_id');
    }

    /** @return HasOne<Store, $this> */
    public function store(): HasOne
    {
        return $this->hasOne(Store::class, 'owner_id');
    }

    /** Only admins may enter the Filament admin panel. */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->hasRole('admin');
    }
}
