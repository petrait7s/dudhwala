<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** Admin language: the choice is kept in the session and in a one-year cookie. Default: APP_LOCALE (Bangla). */
class SetLocale
{
    public const LOCALES = ['bn', 'en'];

    public function handle(Request $request, Closure $next)
    {
        $l = $request->session()->get('locale') ?: $request->cookie('dw_admin_locale');
        if (in_array($l, self::LOCALES, true)) app()->setLocale($l);
        return $next($request);
    }
}
