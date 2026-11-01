<?php

namespace App\Http\Middleware;

use App\Services\Customers\GuestAccount;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** `guest.accounts`: the "حسابي" pages do not exist at a restaurant that hid them; an old link lands on the menu. */
class GuestAccountsOpen
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! GuestAccount::hidden()) {
            return $next($request);
        }
        abort_unless($request->isMethod('GET') && ! $request->expectsJson(), 404);

        return redirect()->route('menu.show');
    }
}
