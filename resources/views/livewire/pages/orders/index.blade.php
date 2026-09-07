<?php

use App\States\Order\Pending;

use function Livewire\Volt\{usesPagination, with};

usesPagination();

with(fn () => [
    'orders' => auth()->user()->orders()->withCount('items')->latest()->paginate(10),
]);

?>

<div class="max-w-4xl mx-auto p-6">
    <h1 class="text-2xl font-bold mb-6">{{ __('My orders') }}</h1>

    @if ($orders->isEmpty())
        <p class="text-gray-500">{{ __('No orders yet.') }}
            <a href="{{ route('catalog.index') }}" class="text-indigo-600 underline">{{ __('Browse the catalog') }}</a>.
        </p>
    @else
        <ul class="divide-y border rounded-lg bg-white">
            @foreach ($orders as $order)
                <li class="flex items-center justify-between gap-4 p-4" wire:key="order-{{ $order->id }}">
                    <div>
                        <a href="{{ route('orders.show', $order) }}" class="font-medium hover:underline">{{ __('Order') }} #{{ $order->id }}</a>
                        <p class="text-sm text-gray-500">
                            {{ $order->created_at->format('M j, Y') }} ·
                            {{ trans_choice(':count item|:count items', $order->items_count) }}
                        </p>
                    </div>
                    <div class="text-right">
                        <p class="font-medium">{{ money($order->total_cents, $order->currency) }}</p>
                        <p class="text-sm {{ $order->status instanceof Pending ? 'text-amber-600' : 'text-gray-500' }}">
                            {{ $order->status->label() }}
                            @if ($order->status instanceof Pending)
                                · <a href="{{ route('orders.show', $order) }}" class="underline">{{ __('Pay now') }}</a>
                            @endif
                        </p>
                    </div>
                </li>
            @endforeach
        </ul>

        <div class="mt-6">{{ $orders->links() }}</div>
    @endif

    <a href="{{ route('catalog.index') }}" class="inline-block mt-6 text-indigo-600 underline">{{ __('Continue shopping') }}</a>
</div>
