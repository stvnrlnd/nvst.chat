<?php

use Illuminate\Support\Facades\Route;

Route::redirect('/', '/login')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('dashboard', 'dashboard')->name('dashboard');
    Route::livewire('watchlist', 'watchlist')->name('watchlist');
    Route::livewire('positions', 'positions')->name('positions');
    Route::livewire('trades', 'trade-history')->name('trades');
    Route::livewire('signals', 'signals')->name('signals');
    Route::livewire('discover', 'discover')->name('discover');
});

require __DIR__.'/settings.php';
