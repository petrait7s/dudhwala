<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);
        $middleware->appendToGroup('web', \App\Http\Middleware\SetLocale::class);
        $middleware->prependToPriorityList(\Illuminate\Routing\Middleware\SubstituteBindings::class, \App\Http\Middleware\StaffAccess::class);
        $middleware->alias(['staff' => \App\Http\Middleware\StaffAccess::class]);
        $middleware->redirectGuestsTo(fn () => route('admin.login'));
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();

// Hosts where the web folder is not app/public (public_html layout): `php artisan dudhwala:install --public-path=...` writes this file
if (is_file($f = __DIR__.'/../.public_path') && is_dir($p = trim((string) file_get_contents($f)))) {
    $app->usePublicPath($p);
}

return $app;
