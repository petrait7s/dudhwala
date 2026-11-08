<?php

use App\Livewire\Admin;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');

Route::prefix('admin')->group(function () {
    Route::post('/locale/{lang}', function (string $lang) {
        abort_unless(in_array($lang, \App\Http\Middleware\SetLocale::LOCALES, true), 404);
        session()->put('locale', $lang);
        return redirect()->back(fallback: route('admin.orders'))->withCookie(cookie('dw_admin_locale', $lang, 60 * 24 * 365));
    })->name('admin.locale');

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
        Route::get('/payments', Admin\Payments::class)->name('admin.payments');
        Route::get('/orders/{order}', Admin\OrderShow::class)->name('admin.order');
        Route::get('/account', Admin\Account::class)->name('admin.account');
        Route::get('/products', Admin\Products::class)->name('admin.products');
        Route::get('/products/{product}', Admin\ProductEdit::class)->name('admin.product');

        // admin only (managers are view-only on orders and products)
        Route::middleware('can:manage')->group(function () {
            Route::get('/products-new', Admin\ProductEdit::class)->name('admin.product.new');
            Route::get('/categories', Admin\Categories::class)->name('admin.categories');
            Route::get('/outlets', Admin\Outlets::class)->name('admin.outlets');
            Route::get('/settings', Admin\Settings::class)->name('admin.settings');
            Route::get('/pages', Admin\ContentPages::class)->name('admin.pages');
            Route::get('/audit', Admin\AuditLogView::class)->name('admin.audit');
            Route::get('/staff', Admin\Staff::class)->name('admin.staff');
            Route::get('/bulk', Admin\Bulk::class)->name('admin.bulk');
            Route::get('/orders-export', \App\Http\Controllers\OrderExportController::class)->name('admin.export');
        });
    });
});
