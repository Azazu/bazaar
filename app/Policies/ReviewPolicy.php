<?php

namespace App\Policies;

use App\Models\Product;
use App\Models\User;

class ReviewPolicy
{
    /**
     * Only verified buyers of a product may review it. "One review per buyer" is a
     * validation concern (StoreReviewRequest), not an authorization one.
     */
    public function create(User $user, Product $product): bool
    {
        return $product->purchasedBy($user);
    }
}
