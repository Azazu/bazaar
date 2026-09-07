<?php

use App\Models\Order;
use App\Services\Order\OrderService;
use App\Services\Payment\PaymentService;
use Illuminate\Support\Str;

use function Livewire\Volt\{mount, state};

state(['order', 'clientSecret' => null, 'awaitingConfirmation' => false]);

mount(function (Order $order) {
    $this->authorize('view', $order); // OrderPolicy: a buyer sees only their own orders

    $this->order = $order->load('items', 'coupon');

    // Back from Stripe's redirect: the charge went through, the webhook will flip the order.
    $this->awaitingConfirmation = request('redirect_status') === 'succeeded';
});

// Buyer-side cancellation; OrderService refunds and restocks a paid order.
$cancel = function () {
    $this->authorize('cancel', $this->order);

    $this->order = app(OrderService::class)->cancel($this->order)->load('items', 'coupon');
};

// Start a payment. Stripe: hand the client secret to the Payment Element and wait for the
// webhook. Sandbox: nothing to confirm client-side, so simulate the provider's callback now.
$pay = function () {
    $started = app(PaymentService::class)->start($this->order);

    if ($started->requiresClientAction) {
        $this->clientSecret = $started->clientSecret;

        return;
    }

    app(PaymentService::class)->confirm('evt_'.Str::uuid(), $started->payment->transaction_id);
    $this->order->refresh();
};

?>

<div class="max-w-3xl mx-auto p-6">
    @if (config('bazaar.payment_gateway') === 'stripe')
        @push('head')
            <script src="https://js.stripe.com/v3/"></script>
        @endpush
    @endif

    <h1 class="text-2xl font-bold">{{ __('Order') }} #{{ $order->id }}</h1>
    <p class="text-gray-500 mb-6">{{ __('Status') }}: <span class="font-medium">{{ $order->status->label() }}</span></p>

    <ul class="divide-y border rounded-lg bg-white">
        @foreach ($order->items as $item)
            <li class="flex justify-between p-3" wire:key="item-{{ $item->id }}">
                <span>{{ $item->product_title }} — {{ $item->variant_name }} × {{ $item->qty }}</span>
                <span>{{ money($item->unit_price_cents * $item->qty) }}</span>
            </li>
        @endforeach
    </ul>

    <dl class="mt-6 space-y-1 text-right">
        <div><dt class="inline text-gray-500">{{ __('Subtotal') }}:</dt> <dd class="inline font-medium">{{ money($order->subtotal_cents) }}</dd></div>
        @if ($order->discount_cents > 0)
            <div class="text-green-700"><dt class="inline">{{ __('Discount') }}@if ($order->coupon) ({{ $order->coupon->code }})@endif:</dt> <dd class="inline font-medium">−{{ money($order->discount_cents) }}</dd></div>
        @endif
        <div><dt class="inline text-gray-500">{{ __('Shipping') }}:</dt> <dd class="inline font-medium">{{ money($order->shipping_cents) }}</dd></div>
        <div class="text-lg"><dt class="inline text-gray-500">{{ __('Total') }}:</dt> <dd class="inline font-bold">{{ money($order->total_cents) }}</dd></div>
    </dl>

    <div class="mt-6 text-sm text-gray-500">
        {{ __('Shipping to') }}: {{ $order->shipping_address['name'] }}, {{ $order->shipping_address['line1'] }},
        {{ $order->shipping_address['city'] }} {{ $order->shipping_address['postcode'] }}, {{ $order->shipping_address['country'] }}
    </div>

    @if ($order->status instanceof \App\States\Order\Pending)
        @if ($awaitingConfirmation)
            <div wire:poll.3s class="mt-6 rounded border border-indigo-200 bg-indigo-50 p-4 text-sm text-indigo-800">
                {{ __('Payment received — confirming with the provider…') }}
            </div>
        @elseif ($clientSecret)
            <div class="mt-6 max-w-md" wire:ignore
                 x-data="stripePayment(@js($clientSecret), @js(config('services.stripe.key')), @js(route('orders.show', $order)))"
                 x-init="mount()">
                <form @submit.prevent="submit" class="space-y-4">
                    <div x-ref="element"></div>
                    <p x-show="error" x-text="error" class="text-sm text-red-600"></p>
                    <button type="submit" :disabled="busy"
                            class="px-4 py-2 bg-indigo-600 text-white rounded hover:bg-indigo-700 disabled:opacity-50">
                        {{ __('Pay') }} {{ money($order->total_cents) }}
                    </button>
                </form>
                <p class="text-xs text-gray-400 mt-2">{{ __('Stripe test mode — use card 4242 4242 4242 4242.') }}</p>
            </div>
        @else
            <div class="mt-6">
                <button wire:click="pay" class="px-4 py-2 bg-indigo-600 text-white rounded hover:bg-indigo-700">
                    {{ __('Pay') }} {{ money($order->total_cents) }}
                </button>
                <p class="text-xs text-gray-400 mt-1">{{ __('Test mode — no real charge.') }}</p>
            </div>
        @endif
    @endif

    @can('cancel', $order)
        <div class="mt-6">
            <button wire:click="cancel" wire:confirm="{{ __('Cancel this order?') }}"
                    class="text-sm text-red-600 hover:underline">{{ __('Cancel order') }}</button>
        </div>
    @endcan

    <a href="{{ route('catalog.index') }}" class="inline-block mt-6 text-indigo-600 underline">{{ __('Continue shopping') }}</a>
</div>
