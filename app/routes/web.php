<?php

use App\Livewire\Admin;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');

Route::prefix('admin')->group(function () {
    Route::get('/login', Admin\Login::class)->name('admin.login');

    Route::middleware(['auth', 'staff:pre'])->group(function () {
        Route::get('/mfa', Admin\Mfa::class)->name('admin.mfa');
        Route::get('/security', Admin\Security::class)->name('admin.security');
        Route::post('/logout', function () {
            Auth::logout();
            session()->invalidate();
            session()->regenerateToken();
            return redirect()->route('admin.login');
        })->name('admin.logout');
    });

    Route::middleware(['auth', 'staff'])->group(function () {
        Route::get('/', Admin\Orders::class)->name('admin.orders');
        Route::get('/orders/{order}', Admin\OrderShow::class)->name('admin.order');
    });
});
