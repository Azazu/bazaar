<?php

use App\Models\Category;
use App\Services\Catalog\ProductSearch;

use function Livewire\Volt\{state, updated, usesPagination, with};

usesPagination();

// Filters live in the URL so a filtered catalog can be shared/bookmarked.
state(['q' => '', 'category' => '', 'min_price' => '', 'max_price' => '', 'min_rating' => ''])->url(except: '');
state(['sort' => 'newest'])->url(except: 'newest');
state(['in_stock' => false])->url();

// Any filter change starts over from page 1.
updated(array_fill_keys(
    ['q', 'category', 'min_price', 'max_price', 'min_rating', 'sort', 'in_stock'],
    fn () => $this->resetPage(),
));

with(fn () => [
    'products' => app(ProductSearch::class)->paginate([
        'q' => $this->q,
        'category' => $this->category ?: null,
        'min_price' => cents($this->min_price), // dollars typed by the user → exact cents
        'max_price' => cents($this->max_price),
        'in_stock' => (bool) $this->in_stock,
        'min_rating' => $this->min_rating !== '' ? (int) $this->min_rating : null,
        'sort' => $this->sort,
    ]),
    'categories' => Category::cachedList(),
]);

$clearFilters = function () {
    $this->reset('q', 'category', 'min_price', 'max_price', 'min_rating', 'sort', 'in_stock');
    $this->resetPage();
};

?>

<div class="max-w-7xl mx-auto p-6">
    <h1 class="text-2xl font-bold mb-6">{{ __('Catalog') }}</h1>

    {{-- Search + facets --}}
    <div class="bg-white border rounded-lg p-4 mb-6 space-y-3">
        <x-text-input wire:model.live.debounce.400ms="q" type="search" class="block w-full"
                      placeholder="{{ __('Search products…') }}" />

        <div class="grid grid-cols-2 md:grid-cols-6 gap-3 items-end text-sm">
            <div>
                <x-input-label for="category" :value="__('Category')" />
                <select wire:model.live="category" id="category" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm text-sm">
                    <option value="">{{ __('All') }}</option>
                    @foreach ($categories as $option)
                        <option value="{{ $option->slug }}">{{ $option->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <x-input-label for="min_price" :value="__('Min price')" />
                <x-text-input wire:model.live.debounce.500ms="min_price" id="min_price" type="number" min="0" step="0.01"
                              class="mt-1 block w-full text-sm" placeholder="0.00" />
            </div>
            <div>
                <x-input-label for="max_price" :value="__('Max price')" />
                <x-text-input wire:model.live.debounce.500ms="max_price" id="max_price" type="number" min="0" step="0.01"
                              class="mt-1 block w-full text-sm" placeholder="999.00" />
            </div>
            <div>
                <x-input-label for="min_rating" :value="__('Rating')" />
                <select wire:model.live="min_rating" id="min_rating" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm text-sm">
                    <option value="">{{ __('Any') }}</option>
                    @foreach ([4, 3, 2, 1] as $stars)
                        <option value="{{ $stars }}">{{ $stars }}+ &#9733;</option>
                    @endforeach
                </select>
            </div>
            <div>
                <x-input-label for="sort" :value="__('Sort')" />
                <select wire:model.live="sort" id="sort" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm text-sm">
                    <option value="newest">{{ __('Newest') }}</option>
                    <option value="price_asc">{{ __('Price: low to high') }}</option>
                    <option value="price_desc">{{ __('Price: high to low') }}</option>
                </select>
            </div>
            <div class="flex items-center justify-between gap-3 pb-2">
                <label class="inline-flex items-center gap-2">
                    <input wire:model.live="in_stock" type="checkbox" class="rounded border-gray-300 text-indigo-600 shadow-sm">
                    <span>{{ __('In stock') }}</span>
                </label>
                <button type="button" wire:click="clearFilters" class="text-indigo-600 hover:underline">{{ __('Reset') }}</button>
            </div>
        </div>
    </div>

    <p class="text-sm text-gray-500 mb-4">{{ trans_choice('{0} No products found|{1} :count product|[2,*] :count products', $products->total(), ['count' => $products->total()]) }}</p>

    <div class="grid grid-cols-2 md:grid-cols-4 gap-6">
        @foreach ($products as $product)
            <a href="{{ route('products.show', $product) }}" wire:key="product-{{ $product->id }}"
               class="border rounded-lg p-4 bg-white shadow-sm block hover:shadow-md">
                @if ($product->primaryImage)
                    <img src="{{ $product->primaryImage->url('card') }}" alt="{{ $product->title }}" loading="lazy"
                         class="aspect-square w-full object-cover rounded mb-3 bg-gray-100">
                @else
                    <div class="aspect-square bg-gray-100 rounded mb-3"></div>
                @endif
                <h2 class="font-medium">{{ $product->title }}</h2>
                <div class="flex items-center justify-between mt-1">
                    <p class="text-gray-600">{{ money($product->price_cents) }}</p>
                    @if ($product->reviews_count > 0)
                        <p class="text-sm text-yellow-500">&#9733; {{ number_format($product->reviews_avg_rating, 1) }}
                            <span class="text-gray-400">({{ $product->reviews_count }})</span></p>
                    @endif
                </div>
            </a>
        @endforeach
    </div>

    <div class="mt-6">
        {{ $products->links() }}
    </div>
</div>
