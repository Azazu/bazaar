<?php

use function Livewire\Volt\{computed};

$products = computed(function () {
    $store = auth()->user()->store;

    return $store
        ? $store->products()->with('variants')->latest()->get()
        : collect();
});

?>

<div class="max-w-5xl mx-auto p-6">
    <h1 class="text-2xl font-bold mb-4">{{ __('My products') }}</h1>

    <nav class="text-sm mb-6 space-x-4">
        <a href="{{ route('vendor.orders') }}" class="text-indigo-600 hover:underline">{{ __('Orders') }}</a>
        <a href="{{ route('vendor.products') }}" class="font-medium">{{ __('Products') }}</a>
        <a href="{{ route('vendor.payouts') }}" class="text-indigo-600 hover:underline">{{ __('Payouts') }}</a>
    </nav>

    @if ($this->products->isEmpty())
        <p class="text-gray-500">{{ __('No products yet.') }}</p>
    @else
        <ul class="divide-y border rounded-lg bg-white">
            @foreach ($this->products as $product)
                <li class="flex items-center justify-between p-3" wire:key="product-{{ $product->id }}">
                    <div>
                        <p class="font-medium">{{ $product->title }}</p>
                        <p class="text-sm text-gray-500">
                            {{ $product->variants->count() }} {{ __('variants') }} ·
                            {{ __('stock') }}: {{ $product->variants->sum('stock') }} ·
                            {{ ucfirst($product->status->value) }}
                        </p>
                    </div>
                    <span>{{ money($product->price_cents) }}</span>
                </li>
            @endforeach
        </ul>
    @endif

    <p class="text-xs text-gray-400 mt-4">{{ __('Product editing lives in the admin panel (coming in the admin block).') }}</p>
</div>
