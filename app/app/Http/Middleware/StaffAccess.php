<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Staff area gate: signed in + active + (ADMIN must have two-factor enrolled AND passed it this session).
 * MANAGER: password only (read-only role).
 * Flags: 'staff' (full gate), 'staff:pre' (signed in only, for the MFA / enrol pages).
 */
class StaffAccess
{
    public function handle(Request $request, Closure $next, string $mode = 'full')
    {
        $u = Auth::user();
        if (! $u || ! $u->isActive()) {
            Auth::logout();
            return redirect()->route('admin.login');
        }
        if ($mode === 'pre') return $next($request);

        if ($u->isAdmin()) {
            if (! $u->mfa_enabled) return redirect()->route('admin.security');
            if (! $request->session()->get('mfa_ok')) return redirect()->route('admin.mfa');
        }
        return $next($request);
    }
}
