<?php

use App\Livewire\Actions\Logout;

$logout = function (Logout $logout) {
    $logout();

    $this->redirect('/', navigate: true);
};

?>

<div class="relative" x-data="{ open: false }" @click.outside="open = false" @keydown.escape.window="open = false">
    <button type="button" @click="open = ! open" :aria-expanded="open"
            class="inline-flex items-center gap-1 text-gray-600 hover:text-gray-900">
        {{ auth()->user()->name }}
        <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
            <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
        </svg>
    </button>

    <div x-show="open" x-cloak x-transition.opacity
         class="absolute right-0 mt-2 w-48 rounded-md bg-white border shadow-lg py-1 text-sm z-40">
        <a href="{{ route('dashboard') }}" wire:navigate class="block px-4 py-2 text-gray-700 hover:bg-gray-50">{{ __('My orders') }}</a>
        <a href="{{ route('profile') }}" wire:navigate class="block px-4 py-2 text-gray-700 hover:bg-gray-50">{{ __('Profile') }}</a>
        @role('vendor')
            <a href="{{ route('vendor.orders') }}" wire:navigate class="block px-4 py-2 text-gray-700 hover:bg-gray-50">{{ __('Vendor dashboard') }}</a>
        @endrole
        @role('admin')
            <a href="{{ url('/admin') }}" class="block px-4 py-2 text-gray-700 hover:bg-gray-50">{{ __('Admin panel') }}</a>
        @endrole
        <button type="button" wire:click="logout" class="block w-full text-start px-4 py-2 text-gray-700 hover:bg-gray-50 border-t">{{ __('Log out') }}</button>
    </div>
</div>
