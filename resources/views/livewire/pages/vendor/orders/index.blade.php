<?php

use App\Models\SubOrder;
use App\States\SubOrder\Delivered;
use App\States\SubOrder\Paid;
use App\States\SubOrder\Processing;
use App\States\SubOrder\Shipped;

use function Livewire\Volt\{computed};

$subOrders = computed(function () {
    $store = auth()->user()->store;

    return $store
        ? $store->subOrders()->with('items', 'order')->latest()->get()
        : collect();
});

// Advance a sub-order to its next state. Policy-gated: only the store owner may do it.
$advance = function (SubOrder $subOrder) {
    $this->authorize('update', $subOrder);

    $next = match (true) {
        $subOrder->status instanceof Paid => Processing::class,
        $subOrder->status instanceof Processing => Shipped::class,
        $subOrder->status instanceof Shipped => Delivered::class,
        default => null,
    };

    if ($next !== null) {
        $subOrder->status->transitionTo($next);
    }
};

?>

<div class="max-w-5xl mx-auto p-6">
    <h1 class="text-2xl font-bold mb-6">{{ __('Incoming orders') }}</h1>

    @if ($this->subOrders->isEmpty())
        <p class="text-gray-500">{{ __('No orders yet.') }}</p>
    @else
        <ul class="space-y-3">
            @foreach ($this->subOrders as $sub)
                <li class="border rounded-lg p-4 bg-white" wire:key="sub-{{ $sub->id }}">
                    <div class="flex items-center justify-between">
                        <span class="font-medium">{{ __('Order') }} #{{ $sub->order_id }}</span>
                        <span class="text-sm text-gray-500">{{ money($sub->subtotal_cents) }} · {{ $sub->status->label() }}</span>
                    </div>
                    <ul class="mt-2 text-sm text-gray-600">
                        @foreach ($sub->items as $item)
                            <li>{{ $item->product_title }} — {{ $item->variant_name }} × {{ $item->qty }}</li>
                        @endforeach
                    </ul>
                    <div class="mt-3">
                        @if ($sub->status instanceof Paid)
                            <button wire:click="advance({{ $sub->id }})" class="text-sm px-3 py-1 rounded bg-indigo-600 text-white hover:bg-indigo-700">{{ __('Start processing') }}</button>
                        @elseif ($sub->status instanceof Processing)
                            <button wire:click="advance({{ $sub->id }})" class="text-sm px-3 py-1 rounded bg-indigo-600 text-white hover:bg-indigo-700">{{ __('Mark shipped') }}</button>
                        @elseif ($sub->status instanceof Shipped)
                            <button wire:click="advance({{ $sub->id }})" class="text-sm px-3 py-1 rounded bg-indigo-600 text-white hover:bg-indigo-700">{{ __('Mark delivered') }}</button>
                        @else
                            <span class="text-sm text-gray-400">{{ __('No action') }}</span>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</div>
