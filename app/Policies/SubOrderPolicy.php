<?php

namespace App\Policies;

use App\Models\SubOrder;
use App\Models\User;

class SubOrderPolicy
{
    /** A vendor may act on a sub-order only if it belongs to their store. */
    public function view(User $user, SubOrder $subOrder): bool
    {
        return $this->owns($user, $subOrder);
    }

    public function update(User $user, SubOrder $subOrder): bool
    {
        return $this->owns($user, $subOrder);
    }

    private function owns(User $user, SubOrder $subOrder): bool
    {
        return $subOrder->store->owner_id === $user->id;
    }
}
