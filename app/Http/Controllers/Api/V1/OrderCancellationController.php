<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\OrderResource;
use App\Models\Order;
use App\Services\Order\OrderService;
use Illuminate\Support\Facades\Gate;

class OrderCancellationController extends Controller
{
    /** Buyer cancels their own order before fulfilment; a paid one is refunded and restocked. */
    public function __invoke(Order $order, OrderService $orders): OrderResource
    {
        Gate::authorize('cancel', $order);

        return OrderResource::make($orders->cancel($order)->load(['items', 'subOrders.store', 'coupon']));
    }
}
