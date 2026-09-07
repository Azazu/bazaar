<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CheckoutRequest;
use App\Http\Resources\V1\OrderResource;
use App\Services\Checkout\CheckoutService;
use Illuminate\Http\JsonResponse;

class CheckoutController extends Controller
{
    /** Turn the user's cart into a pending order (split into per-store sub-orders). */
    public function __invoke(CheckoutRequest $request, CheckoutService $checkout): JsonResponse
    {
        $order = $checkout->place(
            $request->user() ?? abort(401),
            $request->shippingAddress(),
            $request->shippingMethod(),
            $request->coupon(),
        );

        return OrderResource::make($order->load(['items', 'subOrders.store', 'coupon']))
            ->response()
            ->setStatusCode(201);
    }
}
