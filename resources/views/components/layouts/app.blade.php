<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $title ?? config('app.name', 'Bazaar') }}</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="font-sans text-gray-900 antialiased bg-gray-50">
    <header class="bg-white border-b">
        <div class="max-w-7xl mx-auto px-6 py-4 flex items-center justify-between">
            <a href="{{ route('catalog.index') }}" class="text-xl font-bold">Bazaar</a>
            <nav class="text-sm space-x-4">
                <a href="{{ route('cart.index') }}" class="text-gray-600 hover:text-gray-900">
                    {{ __('Cart') }} ({{ app(\App\Services\Cart\CartService::class)->count() }})
                </a>
                @auth
                    @role('vendor')
                        <a href="{{ route('vendor.orders') }}" class="text-gray-600 hover:text-gray-900">{{ __('Vendor') }}</a>
                    @endrole
                    <a href="{{ route('dashboard') }}" class="text-gray-600 hover:text-gray-900">{{ __('Dashboard') }}</a>
                @else
                    <a href="{{ route('login') }}" class="text-gray-600 hover:text-gray-900">{{ __('Log in') }}</a>
                    <a href="{{ route('register') }}" class="text-gray-600 hover:text-gray-900">{{ __('Register') }}</a>
                @endauth
            </nav>
        </div>
    </header>

    <main>
        {{ $slot }}
    </main>
</body>
</html>
