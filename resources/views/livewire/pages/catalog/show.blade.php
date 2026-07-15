<?php

use App\Enums\ProductStatus;
use App\Models\Product;
use App\Services\Cart\CartService;

use function Livewire\Volt\{computed, mount, state};

state(['product', 'justAdded' => null, 'rating' => 5, 'body' => '', 'reviewSubmitted' => false]);

mount(function (Product $product) {
    abort_if($product->status !== ProductStatus::Published, 404); // draft/archived → 404

    $this->product = $product->load('variants');
});

$reviews = computed(fn () => $this->product->reviews()->approved()->with('user')->latest()->get());
$averageRating = computed(fn () => $this->product->averageRating());
$hasReviewed = computed(fn () => auth()->check()
    && $this->product->reviews()->where('user_id', auth()->id())->exists());
$canReview = computed(fn () => auth()->check()
    && ! $this->hasReviewed
    && $this->product->purchasedBy(auth()->user()));

$addToCart = function (int $variantId) {
    app(CartService::class)->add($variantId);
    $this->justAdded = $variantId;
};

$submitReview = function () {
    abort_unless(auth()->check() && $this->product->purchasedBy(auth()->user()), 403);

    $this->validate([
        'rating' => ['required', 'integer', 'min:1', 'max:5'],
        'body' => ['nullable', 'string', 'max:2000'],
    ]);

    $this->product->reviews()->create([
        'user_id' => auth()->id(),
        'rating' => $this->rating,
        'body' => $this->body,
        'approved' => false, // pending moderation (admin approves in Phase 3)
    ]);

    $this->reset('body');
    $this->rating = 5;
    $this->reviewSubmitted = true;
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

    {{-- Reviews --}}
    <div class="mt-10">
        <h2 class="font-semibold mb-3">
            {{ __('Reviews') }}
            @if ($this->reviews->isNotEmpty())
                <span class="text-yellow-500">&#9733; {{ $this->averageRating }}</span>
                <span class="text-gray-400 text-sm">({{ $this->reviews->count() }})</span>
            @endif
        </h2>

        @if ($this->reviews->isEmpty())
            <p class="text-gray-500 text-sm">{{ __('No reviews yet.') }}</p>
        @else
            <ul class="space-y-3">
                @foreach ($this->reviews as $review)
                    <li class="border rounded-lg p-3 bg-white" wire:key="review-{{ $review->id }}">
                        <div class="text-yellow-500 text-sm">{{ str_repeat('★', $review->rating).str_repeat('☆', 5 - $review->rating) }}</div>
                        @if ($review->body)
                            <p class="text-gray-700 mt-1">{{ $review->body }}</p>
                        @endif
                        <p class="text-xs text-gray-400 mt-1">— {{ $review->user->name }}</p>
                    </li>
                @endforeach
            </ul>
        @endif

        @auth
            @if ($reviewSubmitted)
                <p class="mt-4 text-green-600 text-sm">{{ __('Thanks! Your review is pending moderation.') }}</p>
            @elseif ($this->hasReviewed)
                <p class="mt-4 text-gray-400 text-sm">{{ __('You have already reviewed this product.') }}</p>
            @elseif ($this->canReview)
                <form wire:submit="submitReview" class="mt-4 space-y-2 max-w-md">
                    <h3 class="font-medium">{{ __('Write a review') }}</h3>
                    <select wire:model="rating" class="border-gray-300 rounded-md shadow-sm">
                        @foreach ([5, 4, 3, 2, 1] as $r)
                            <option value="{{ $r }}">{{ $r }} &#9733;</option>
                        @endforeach
                    </select>
                    <textarea wire:model="body" rows="3" class="block w-full border-gray-300 rounded-md shadow-sm"
                              placeholder="{{ __('Your thoughts...') }}"></textarea>
                    <x-input-error :messages="$errors->get('body')" />
                    <x-primary-button>{{ __('Submit review') }}</x-primary-button>
                </form>
            @else
                <p class="mt-4 text-gray-400 text-sm">{{ __('Only buyers of this product can review it.') }}</p>
            @endif
        @endauth
    </div>
</div>
