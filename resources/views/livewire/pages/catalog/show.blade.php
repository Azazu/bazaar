<?php

use App\Enums\ProductStatus;
use App\Models\Product;

use function Livewire\Volt\{state, mount};

state(['product']);

mount(function (Product $product) {
    abort_if($product->status !== ProductStatus::Published, 404);   // draft/archived → 404

    $this->product = $product->load('variants');
});

?>

<div class="max-w-5xl mx-auto p-6">
    <a href="{{ route('catalog.index') }}" class="text-sm text-gray-500">&larr; {{ __('Catalog') }}</a>
    <h1 class="text-2xl font-bold mt-2">{{ $product->title }}</h1>
    <p class="text-gray-600 mt-2">{{ $product->description }}</p>

    <h2 class="font-semibold mt-6 mb-2">{{ __('Variants') }}</h2>
    <ul class="divide-y border rounded-lg bg-white">
        @foreach ($product->variants as $variant)
            <li class="flex justify-between p-3">
                <span>{{ $variant->name }}
                    <span class="text-gray-400 text-sm">({{ $variant->sku }})</span></span>
                <span>${{ number_format($variant->price_cents / 100, 2) }}
                    · {{ $variant->stock > 0 ? __('In stock') : __('Out of stock') }}</span>
            </li>
        @endforeach
    </ul>
</div>
