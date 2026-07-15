<?php

use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::view('dashboard', 'dashboard')
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::view('profile', 'profile')
    ->middleware(['auth'])
    ->name('profile');

Volt::route('/', 'pages.catalog.index')->name('catalog.index');
Volt::route('/products/{product:slug}', 'pages.catalog.show')->name('products.show');
Volt::route('/cart', 'pages.cart.index')->name('cart.index');

Volt::route('/checkout', 'pages.checkout.index')->middleware('auth')->name('checkout.index');
Volt::route('/orders/{order}', 'pages.orders.show')->middleware('auth')->name('orders.show');

require __DIR__.'/auth.php';
