@props(['title'])

@php $store = auth()->user()?->store; @endphp

<div class="flex items-center gap-3 mb-4">
    @if ($store?->logoUrl('thumb'))
        <img src="{{ $store->logoUrl('thumb') }}" alt="" class="w-12 h-12 rounded-full object-cover bg-gray-100">
    @endif
    <div>
        <h1 class="text-2xl font-bold leading-tight">{{ $title }}</h1>
        @if ($store)
            <p class="text-sm text-gray-500">{{ $store->name }}</p>
        @endif
    </div>
</div>

<nav class="text-sm mb-6 space-x-4">
    @foreach (['vendor.orders' => __('Orders'), 'vendor.products' => __('Products'), 'vendor.payouts' => __('Payouts')] as $route => $label)
        <a href="{{ route($route) }}" @class(['font-medium' => request()->routeIs($route), 'text-indigo-600 hover:underline' => ! request()->routeIs($route)])>{{ $label }}</a>
    @endforeach
</nav>
