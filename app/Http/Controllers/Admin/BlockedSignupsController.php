<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BlockedSignup;
use App\Models\PlatformAuditLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * «المحظورين» (tenants.manage): the emails and mobiles that may not open a new restaurant, blocked from a
 * restaurant's page (TenantsController::block). One stays here after its restaurant is deleted, until removed.
 */
class BlockedSignupsController extends Controller
{
    public function index(): View
    {
        return view('admin.blocked-signups', [
            'blocked' => BlockedSignup::query()->with('admin')->latest()->latest('id')->paginate(50),
        ]);
    }

    public function destroy(Request $request, BlockedSignup $blocked): RedirectResponse
    {
        $blocked->delete();
        PlatformAuditLog::record($request->user('admin'), 'signup.unblocked', (string) $blocked->tenant_id, ['email' => $blocked->email, 'phone' => $blocked->phone]);

        return back()->with('ok', __('Removed. This email and mobile can open an account again.'));
    }
}
