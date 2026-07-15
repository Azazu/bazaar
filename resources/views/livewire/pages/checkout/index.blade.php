<?php

use App\Services\Cart\CartService;
use App\Services\Checkout\CheckoutService;

use function Livewire\Volt\{computed, rules, state};

state([
    'name' => '',
    'line1' => '',
    'city' => '',
    'postcode' => '',
    'country' => '',
    'shipping_method' => 'standard',
]);

rules([
    'name' => ['required', 'string', 'max:255'],
    'line1' => ['required', 'string', 'max:255'],
    'city' => ['required', 'string', 'max:255'],
    'postcode' => ['required', 'string', 'max:20'],
    'country' => ['required', 'string', 'size:2'],
    'shipping_method' => ['required', 'in:standard,express'],
]);

$items = computed(fn () => app(CartService::class)->items());
$subtotal = computed(fn () => app(CartService::class)->total());

$place = function () {
    $validated = $this->validate();

    if (app(CartService::class)->items()->isEmpty()) {
        return $this->redirect(route('catalog.index'), navigate: true);
    }

    $order = app(CheckoutService::class)->place(
        auth()->user(),
        [
            'name' => $validated['name'],
            'line1' => $validated['line1'],
            'city' => $validated['city'],
            'postcode' => $validated['postcode'],
            'country' => strtoupper($validated['country']),
        ],
        $validated['shipping_method'],
    );

    $this->redirect(route('orders.show', $order), navigate: true);
};

?>

<div class="max-w-3xl mx-auto p-6">
    <h1 class="text-2xl font-bold mb-6">{{ __('Checkout') }}</h1>

    @if ($this->items->isEmpty())
        <p class="text-gray-500">{{ __('Your cart is empty.') }}
            <a href="{{ route('catalog.index') }}" class="text-indigo-600 underline">{{ __('Browse the catalog') }}</a>.
        </p>
    @else
        {{-- Order summary --}}
        <ul class="divide-y border rounded-lg bg-white mb-6">
            @foreach ($this->items as $line)
                <li class="flex justify-between p-3" wire:key="line-{{ $line['variant']->id }}">
                    <span>{{ $line['variant']->product->title }} — {{ $line['variant']->name }} × {{ $line['qty'] }}</span>
                    <span>{{ money($line['line_total_cents']) }}</span>
                </li>
            @endforeach
            <li class="flex justify-between p-3 font-semibold">
                <span>{{ __('Subtotal') }}</span>
                <span>{{ money($this->subtotal) }}</span>
            </li>
        </ul>

        {{-- Shipping form --}}
        <form wire:submit="place" class="space-y-4">
            <div>
                <x-input-label for="name" :value="__('Full name')" />
                <x-text-input wire:model="name" id="name" class="block mt-1 w-full" type="text" />
                <x-input-error :messages="$errors->get('name')" class="mt-2" />
            </div>
            <div>
                <x-input-label for="line1" :value="__('Address')" />
                <x-text-input wire:model="line1" id="line1" class="block mt-1 w-full" type="text" />
                <x-input-error :messages="$errors->get('line1')" class="mt-2" />
            </div>
            <div class="grid grid-cols-3 gap-4">
                <div>
                    <x-input-label for="city" :value="__('City')" />
                    <x-text-input wire:model="city" id="city" class="block mt-1 w-full" type="text" />
                    <x-input-error :messages="$errors->get('city')" class="mt-2" />
                </div>
                <div>
                    <x-input-label for="postcode" :value="__('Postcode')" />
                    <x-text-input wire:model="postcode" id="postcode" class="block mt-1 w-full" type="text" />
                    <x-input-error :messages="$errors->get('postcode')" class="mt-2" />
                </div>
                <div>
                    <x-input-label for="country" :value="__('Country (2-letter)')" />
                    <x-text-input wire:model="country" id="country" class="block mt-1 w-full" type="text" maxlength="2" placeholder="US" />
                    <x-input-error :messages="$errors->get('country')" class="mt-2" />
                </div>
            </div>
            <div>
                <x-input-label for="shipping_method" :value="__('Shipping method')" />
                <select wire:model="shipping_method" id="shipping_method" class="block mt-1 w-full border-gray-300 rounded-md shadow-sm">
                    <option value="standard">{{ __('Standard') }} — {{ money(500) }}</option>
                    <option value="express">{{ __('Express') }} — {{ money(1500) }}</option>
                </select>
            </div>

            <x-primary-button class="mt-4">{{ __('Place order') }}</x-primary-button>
        </form>
    @endif
</div>
