<?php

use App\Services\Cart\CartService;

use function Livewire\Volt\{computed};

$items = computed(fn () => app(CartService::class)->items());
$total = computed(fn () => app(CartService::class)->total());

$updateQty = function (int $variantId, int $qty) {
    app(CartService::class)->update($variantId, $qty);
};

$remove = function (int $variantId) {
    app(CartService::class)->remove($variantId);
};

?>

<div class="max-w-4xl mx-auto p-6">
    <h1 class="text-2xl font-bold mb-6">{{ __('Cart') }}</h1>

    @if ($this->items->isEmpty())
        <p class="text-gray-500">{{ __('Your cart is empty.') }}</p>
    @else
        <ul class="divide-y border rounded-lg bg-white">
            @foreach ($this->items as $line)
                <li class="flex items-center justify-between gap-4 p-4" wire:key="line-{{ $line['variant']->id }}">
                    <div class="min-w-0">
                        <p class="font-medium truncate">{{ $line['variant']->product->title }}</p>
                        <p class="text-sm text-gray-500">{{ $line['variant']->name }} ({{ $line['variant']->sku }})</p>
                    </div>

                    <div class="flex items-center gap-4">
                        <input type="number" min="1" value="{{ $line['qty'] }}"
                               wire:change="updateQty({{ $line['variant']->id }}, $event.target.value)"
                               class="w-16 border rounded p-1 text-center">
                        <span class="w-24 text-right">{{ money($line['line_total_cents']) }}</span>
                        <button wire:click="remove({{ $line['variant']->id }})"
                                class="text-red-600 text-sm hover:underline">{{ __('Remove') }}</button>
                    </div>
                </li>
            @endforeach
        </ul>

        <div class="flex items-center justify-between mt-6">
            <span class="text-lg font-semibold">{{ __('Total') }}: {{ money($this->total) }}</span>
            <button class="px-4 py-2 bg-gray-300 text-gray-600 rounded cursor-not-allowed" disabled>
                {{ __('Checkout') }} — {{ __('coming in Phase 2') }}
            </button>
        </div>
    @endif
</div>
