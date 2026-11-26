<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sensitive actions (the restaurant's whole data leaving, a domain removed) ask "is it really you?" first: a code to
 * the account's email (the password when it has none), good for MINUTES in this session (ConfirmIdentityController).
 * A page opened by link comes back to itself afterwards; a button pressed (POST) comes back to the page it was on.
 */
class ConfirmIdentity
{
    public const SESSION = 'identity_confirmed_at';

    public const MINUTES = 15;

    public function handle(Request $request, Closure $next): Response
    {
        if (self::confirmed($request)) {
            return $next($request);
        }
        $request->session()->put('url.intended', $request->isMethod('GET') ? $request->fullUrl() : url()->previous(route('dash.home')));

        return redirect()->route('dash.confirm');
    }

    public static function confirmed(Request $request): bool
    {
        return (int) $request->session()->get(self::SESSION, 0) >= now()->subMinutes(self::MINUTES)->timestamp;
    }
}
