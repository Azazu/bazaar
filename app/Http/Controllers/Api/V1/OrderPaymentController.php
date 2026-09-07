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
     * Start a payment. With Stripe the response carries the client secret and the order
     * stays pending until Stripe's webhook confirms it — the client confirms with the Stripe
     * SDK and then polls the order. With the sandbox gateway there is nothing for the client
     * to do, so the provider's "succeeded" callback is simulated right here.
     */
    public function __invoke(Order $order, PaymentService $payments): OrderResource
    {
        Gate::authorize('pay', $order);

        $started = $payments->start($order);

        if (! $started->requiresClientAction) {
            $payments->confirm('evt_'.Str::uuid(), $started->payment->transaction_id);
        }

        return OrderResource::make($order->refresh()->load(['items', 'subOrders.store', 'coupon']))
            ->additional(['payment' => [
                'id' => $started->payment->id,
                'gateway' => $started->payment->gateway,
                'status' => $started->payment->refresh()->status,
                'client_secret' => $started->clientSecret,
            ]]);
    }
}
