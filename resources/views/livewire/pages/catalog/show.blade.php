<?php

use App\Enums\ProductStatus;
use App\Models\Product;
use App\Services\Cart\CartService;

use function Livewire\Volt\{mount, state};

state(['product', 'justAdded' => null]);

mount(function (Product $product) {
    abort_if($product->status !== ProductStatus::Published, 404); // draft/archived → 404

    $this->product = $product->load('variants');
});

$addToCart = function (int $variantId) {
    app(CartService::class)->add($variantId);
    $this->justAdded = $variantId;
};

?>

<div class="max-w-5xl mx-auto p-6">
    <a href="{{ route('catalog.index') }}" class="text-sm text-gray-500">&larr; {{ __('Catalog') }}</a>
    <h1 class="text-2xl font-bold mt-2">{{ $product->title }}</h1>
    <p class="text-gray-600 mt-2">{{ $product->description }}</p>

    <h2 class="font-semibold mt-6 mb-2">{{ __('Variants') }}</h2>
    <ul class="divide-y border rounded-lg bg-white">
        @foreach ($product->variants as $variant)
            <li class="flex items-center justify-between p-3" wire:key="variant-{{ $variant->id }}">
                <span>{{ $variant->name }}
                    <span class="text-gray-400 text-sm">({{ $variant->sku }})</span></span>

                <div class="flex items-center gap-3">
                    <span>{{ money($variant->price_cents) }}</span>

                    @if ($variant->stock > 0)
                        <button wire:click="addToCart({{ $variant->id }})"
                                class="text-sm px-3 py-1 rounded bg-indigo-600 text-white hover:bg-indigo-700">
                            {{ __('Add to cart') }}
                        </button>
                        @if ($justAdded === $variant->id)
                            <span class="text-green-600 text-sm">{{ __('Added') }}</span>
                        @endif
                    @else
                        <span class="text-gray-400 text-sm">{{ __('Out of stock') }}</span>
                    @endif
                </div>
            </li>
        @endforeach
    </ul>
</div>
