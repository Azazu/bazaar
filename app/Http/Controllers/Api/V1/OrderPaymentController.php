<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\OrderResource;
use App\Models\Order;
use App\Services\Payment\PaymentService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class OrderPaymentController extends Controller
{
    /**
     * Sandbox payment: start the intent and immediately simulate the provider's
     * "succeeded" callback. With a real gateway the second step is the webhook,
     * not this request — the client would only call start and then poll the order.
     */
    public function __invoke(Order $order, PaymentService $payments): OrderResource
    {
        Gate::authorize('pay', $order);

        $payment = $payments->start($order);
        $payments->confirm('evt_'.Str::uuid(), $payment->transaction_id);

        return OrderResource::make($order->refresh()->load(['items', 'subOrders.store', 'coupon']));
    }
}
