<?php

use App\Models\SubOrder;
use App\Services\Order\SubOrderService;
use App\States\SubOrder\Delivered;
use App\States\SubOrder\Paid;
use App\States\SubOrder\Processing;
use App\States\SubOrder\Shipped;

use Spatie\ModelStates\Exceptions\CouldNotPerformTransition;

use function Livewire\Volt\{computed, state};

state(['error' => null]);

$subOrders = computed(function () {
    $store = auth()->user()->store;

    return $store
        ? $store->subOrders()->with('items', 'order')->latest()->get()
        : collect();
});

// Advance a sub-order to its next state. Policy-gated: only the store owner may do it.
// SubOrderService decides on a locked copy, so a buyer cancelling at the same moment can't
// be overwritten; the vendor is told the order went away instead.
$advance = function (SubOrder $subOrder) {
    $this->authorize('update', $subOrder);
    $this->error = null;

    $next = match (true) {
        $subOrder->status instanceof Paid => Processing::class,
        $subOrder->status instanceof Processing => Shipped::class,
        $subOrder->status instanceof Shipped => Delivered::class,
        default => null,
    };

    $cancelledUnderneath = fn () => $this->error = __('Order #:id can no longer be advanced — it was cancelled or refunded in the meantime.', ['id' => $subOrder->order_id]);

    // The list the vendor clicked on may be stale: the sub-order is re-read here (route binding),
    // so a reversal that already landed shows up as "no next step" rather than as a refused move.
    if ($next === null) {
        if (! $subOrder->status instanceof Delivered) {
            $cancelledUnderneath();
        }

        return;
    }

    try {
        app(SubOrderService::class)->advance($subOrder, $next);
    } catch (CouldNotPerformTransition) {
        $cancelledUnderneath(); // the reversal won the race by a hair
    }
};

?>

<div class="max-w-5xl mx-auto p-6">
    <x-vendor-nav :title="__('Incoming orders')" />

    @if ($error)
        <p class="mb-4 rounded bg-amber-50 border border-amber-200 text-amber-800 px-4 py-2 text-sm">{{ $error }}</p>
    @endif

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
                            <li>{{ $item->product_title }} — {{ $item->variant_name }} × {{ $item->qty }}@if ($item->sku) <span class="text-gray-400">({{ $item->sku }})</span>@endif</li>
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
