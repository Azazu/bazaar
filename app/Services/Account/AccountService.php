<?php

namespace App\Services\Account;

use App\Exceptions\DeletionBlockedException;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Closing an account and archiving a store — the only two ways a person or a shop leaves
 * the marketplace. Neither deletes anything financial (see the models); both are
 * check-then-act, so both run as one transaction with the rows locked: a checkout that
 * would add an order to this buyer or a sub-order to this store has to wait for the lock
 * (InnoDB shares-locks the parent row on insert) and the check is made on that stable
 * snapshot. The order of steps matters too — the store is archived *before* the person
 * is anonymised, so a refusal at any point rolls the whole thing back and never leaves
 * an active store with a faceless owner, or a scrubbed account that is still live.
 */
class AccountService
{
    /**
     * Archive the person's store (if any), scrub their personal data and soft-delete them.
     *
     * @throws DeletionBlockedException while they, or their store, have orders in progress
     */
    public function close(User $user): void
    {
        DB::transaction(function () use ($user) {
            $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            $store = Store::query()->where('owner_id', $locked->id)->lockForUpdate()->first();
            $store?->delete();   // Store::booted(): refuses while sub-orders are open

            $locked->delete();   // User::booted(): refuses while orders are open, anonymises
        });
    }

    /**
     * Take a store off the marketplace for good, keeping its order history.
     *
     * @throws DeletionBlockedException while it has orders in progress
     */
    public function archiveStore(Store $store): void
    {
        DB::transaction(function () use ($store) {
            Store::query()->whereKey($store->getKey())->lockForUpdate()->firstOrFail()->delete();
        });
    }
}
