<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next)
    {
        $r = $next($request);
        $h = $r->headers;
        $h->set('X-Content-Type-Options', 'nosniff');
        $h->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $h->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        if (! $request->is('api/*')) $h->set('X-Frame-Options', 'DENY'); // the admin must never be framed
        if ($request->isSecure()) $h->set('Strict-Transport-Security', 'max-age=31536000');
        return $r;
    }
}
