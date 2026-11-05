<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EnsureHouseholdAccess
{
    public function handle(Request $request, Closure $next)
    {
        if (!$request->user()?->is_active) {
            Auth::logout(); $request->session()->invalidate(); $request->session()->regenerateToken();
            return redirect()->route('login')->withErrors(['credential' => 'This account is paused. Please ask a parent.']);
        }
        if ($request->user()->must_change_password && !$request->routeIs('password.*', 'logout')) return redirect()->route('password.edit');
        $response = $next($request);
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'same-origin');
        $response->headers->set('X-Frame-Options', 'DENY');
        return $response;
    }
}
