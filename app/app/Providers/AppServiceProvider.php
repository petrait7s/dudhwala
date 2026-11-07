<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(\App\Contracts\OrderNotifier::class, \App\Notifications\LogOrderNotifier::class);
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // public API rate limits (per IP; track also per order number)
        RateLimiter::for('orders', fn (Request $r) => [Limit::perMinute(6)->by('o:'.$r->ip()), Limit::perHour(30)->by('oh:'.$r->ip())]);
        RateLimiter::for('track', fn (Request $r) => [Limit::perMinute(15)->by('t:'.$r->ip()), Limit::perHour(10)->by('tn:'.$r->ip().':'.strtoupper((string) $r->input('order_number')))]);

        // ADMIN: everything. MANAGER: view only.
        Gate::define('manage', fn (User $u) => $u->isActive() && $u->isAdmin());
        Gate::define('view', fn (User $u) => $u->isActive());
        //
    }
}
