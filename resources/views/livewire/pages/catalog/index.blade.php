<?php

use App\Models\Product;

use function Livewire\Volt\{with, usesPagination};

usesPagination();

with(fn () => [
    'products' => Product::published()->with('variants')->latest()->paginate(12),
]);

?>

<div class="max-w-7xl mx-auto p-6">
    <h1 class="text-2xl font-bold mb-6">{{ __('Catalog') }}</h1>

    <div class="grid grid-cols-2 md:grid-cols-4 gap-6">
        @foreach ($products as $product)
            <a href="{{ route('products.show', $product) }}" class="border rounded-lg p-4 bg-white shadow-sm block hover:shadow-md">
                <div class="aspect-square bg-gray-100 rounded mb-3"></div> {{-- image placeholder --}}
                <h2 class="font-medium">{{ $product->title }}</h2>
                <p class="text-gray-600">${{ number_format($product->price_cents / 100, 2) }}</p>
            </a>
        @endforeach
    </div>
    <div class="mt-6">
        {{ $products->links() }}
    </div>
</div>
