<?php

use App\Services\Cart\CartService;

use function Livewire\Volt\{computed, on};

// Any component that changes the cart dispatches `cart-updated`; re-rendering is all we need.
on(['cart-updated' => fn () => null]);

$count = computed(fn () => app(CartService::class)->count());

?>

<a href="{{ route('cart.index') }}" class="text-gray-600 hover:text-gray-900">
    {{ __('Cart') }} ({{ $this->count }})
</a>
