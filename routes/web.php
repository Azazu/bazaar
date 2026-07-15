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

require __DIR__.'/auth.php';
