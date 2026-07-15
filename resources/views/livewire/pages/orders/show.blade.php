<?php

use App\Models\Order;

use function Livewire\Volt\{mount, state};

state(['order']);

mount(function (Order $order) {
    abort_unless($order->buyer_id === auth()->id(), 403); // a buyer sees only their own orders

    $this->order = $order->load('items');
});

?>

<div class="max-w-3xl mx-auto p-6">
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
        <div><dt class="inline text-gray-500">{{ __('Shipping') }}:</dt> <dd class="inline font-medium">{{ money($order->shipping_cents) }}</dd></div>
        <div class="text-lg"><dt class="inline text-gray-500">{{ __('Total') }}:</dt> <dd class="inline font-bold">{{ money($order->total_cents) }}</dd></div>
    </dl>

    <div class="mt-6 text-sm text-gray-500">
        {{ __('Shipping to') }}: {{ $order->shipping_address['name'] }}, {{ $order->shipping_address['line1'] }},
        {{ $order->shipping_address['city'] }} {{ $order->shipping_address['postcode'] }}, {{ $order->shipping_address['country'] }}
    </div>

    <a href="{{ route('catalog.index') }}" class="inline-block mt-6 text-indigo-600 underline">{{ __('Continue shopping') }}</a>
</div>
