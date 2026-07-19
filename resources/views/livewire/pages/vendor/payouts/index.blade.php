<?php

use function Livewire\Volt\{computed};

$payouts = computed(function () {
    $store = auth()->user()->store;

    return $store
        ? $store->payouts()->with('subOrder')->latest()->get()
        : collect();
});

$pendingTotal = computed(fn () => $this->payouts->where('status', 'pending')->sum('amount_cents'));

?>

<div class="max-w-5xl mx-auto p-6">
    <h1 class="text-2xl font-bold mb-4">{{ __('Payouts') }}</h1>

    <nav class="text-sm mb-6 space-x-4">
        <a href="{{ route('vendor.orders') }}" class="text-indigo-600 hover:underline">{{ __('Orders') }}</a>
        <a href="{{ route('vendor.products') }}" class="text-indigo-600 hover:underline">{{ __('Products') }}</a>
        <a href="{{ route('vendor.payouts') }}" class="font-medium">{{ __('Payouts') }}</a>
    </nav>

    <p class="mb-4 text-gray-600">{{ __('Pending payout total') }}: <span class="font-semibold">{{ money($this->pendingTotal) }}</span></p>

    @if ($this->payouts->isEmpty())
        <p class="text-gray-500">{{ __('No payouts yet.') }}</p>
    @else
        <ul class="divide-y border rounded-lg bg-white">
            @foreach ($this->payouts as $payout)
                <li class="flex items-center justify-between p-3" wire:key="payout-{{ $payout->id }}">
                    <span>{{ __('Order') }} #{{ $payout->subOrder->order_id }}
                        <span class="text-gray-400 text-sm">({{ __('commission') }} {{ money($payout->commission_cents) }})</span></span>
                    <span>{{ money($payout->amount_cents) }} · {{ ucfirst($payout->status) }}</span>
                </li>
            @endforeach
        </ul>
    @endif
</div>
