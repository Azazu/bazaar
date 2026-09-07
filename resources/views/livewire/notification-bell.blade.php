<?php

use function Livewire\Volt\{computed};

// Every notification writes to the `database` channel with a `message` and `url`; this is the in-app view of it.
$unread = computed(fn () => auth()->user()->unreadNotifications()->latest()->take(5)->get());
$count = computed(fn () => auth()->user()->unreadNotifications()->count());

$markAllRead = function () {
    auth()->user()->unreadNotifications->markAsRead();
};

?>

<div class="relative" x-data="{ open: false }" @click.outside="open = false" @keydown.escape.window="open = false" wire:poll.60s>
    <button type="button" @click="open = ! open" :aria-expanded="open"
            class="relative inline-flex items-center text-gray-600 hover:text-gray-900" aria-label="{{ __('Notifications') }}">
        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75v-.7V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 01-5.714 0m5.714 0a3 3 0 11-5.714 0" />
        </svg>
        @if ($this->count > 0)
            <span class="absolute -top-1.5 -right-2 min-w-[1.1rem] rounded-full bg-red-600 px-1 text-center text-[0.65rem] font-semibold leading-4 text-white">{{ $this->count }}</span>
        @endif
    </button>

    <div x-show="open" x-cloak x-transition.opacity
         class="absolute right-0 mt-2 w-80 rounded-md bg-white border shadow-lg text-sm z-40">
        <div class="flex items-center justify-between px-4 py-2 border-b">
            <span class="font-medium">{{ __('Notifications') }}</span>
            @if ($this->count > 0)
                <button type="button" wire:click="markAllRead" class="text-xs text-indigo-600 hover:underline">{{ __('Mark all as read') }}</button>
            @endif
        </div>
        @if ($this->unread->isEmpty())
            <p class="px-4 py-3 text-gray-500">{{ __("You're all caught up.") }}</p>
        @else
            <ul class="divide-y">
                @foreach ($this->unread as $notification)
                    <li wire:key="notification-{{ $notification->id }}">
                        <a href="{{ $notification->data['url'] ?? route('dashboard') }}" class="block px-4 py-2 hover:bg-gray-50">
                            <p class="text-gray-800">{{ $notification->data['message'] ?? __('Notification') }}</p>
                            <p class="text-xs text-gray-400">{{ $notification->created_at->diffForHumans() }}</p>
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</div>
