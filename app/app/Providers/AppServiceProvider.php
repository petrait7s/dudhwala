<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // ADMIN: everything. MANAGER: view only.
        Gate::define('manage', fn (User $u) => $u->isActive() && $u->isAdmin());
        Gate::define('view', fn (User $u) => $u->isActive());
        //
    }
}
