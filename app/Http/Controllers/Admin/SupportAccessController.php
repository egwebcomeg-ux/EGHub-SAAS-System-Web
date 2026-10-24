<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use App\Services\SupportAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * "Open the restaurant's dashboard" (tenants.impersonate): a one-time ticket for the owner, or for the staff
 * account picked on the restaurant page, then off to app.<domain>, which uses it (App\Services\SupportAccess).
 * A suspended restaurant opens too (support); a deleted one never resolves here.
 */
class SupportAccessController extends Controller
{
    public function store(Request $request, Tenant $tenant, SupportAccess $support): RedirectResponse
    {
        $data = $request->validate(['user_id' => 'nullable|integer']);
        $users = User::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('is_active', true);
        $user = isset($data['user_id'])
            ? $users->find((int) $data['user_id'])
            : ($users->clone()->role('owner')->orderBy('id')->first() ?? $users->orderBy('id')->first());
        if (! $user) {
            return back()->withErrors(['support_access' => __('This restaurant has no active account to open.')]);
        }

        $ticket = $support->issue($request->user('admin'), $tenant, $user);

        return redirect()->away(route('dash.support-access', $ticket));
    }
}
