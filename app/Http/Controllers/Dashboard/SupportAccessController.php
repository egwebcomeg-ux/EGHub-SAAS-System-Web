<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Services\SupportAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** EgWeb support signed in from the admin (App\Services\SupportAccess): the way in with a ticket, and the way out. */
class SupportAccessController extends Controller
{
    /** app.<domain>/support-access/{ticket}: uses the one-time ticket and opens the restaurant's dashboard. */
    public function enter(Request $request, string $ticket, SupportAccess $support): RedirectResponse
    {
        $granted = $support->redeem($ticket);
        if ($granted === null) {
            return redirect()->route('dash.login')->withErrors(['login' => __('This sign-in link from the admin expired or was already used. Open it again from the admin.')]);
        }
        $support->start($request, $granted['admin'], $granted['tenant'], $granted['user']);

        return redirect()->route('dash.home');
    }

    /** "Leave and go back to the admin": signs out of this browser and opens the restaurant's admin page. */
    public function exit(Request $request, SupportAccess $support): RedirectResponse
    {
        abort_unless(SupportAccess::active($request), 404);
        $tenantId = $support->end($request);

        return redirect()->away($tenantId ? route('admin.tenants.show', $tenantId) : route('admin.tenants'));
    }
}
