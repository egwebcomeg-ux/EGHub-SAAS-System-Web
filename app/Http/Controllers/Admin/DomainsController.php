<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * EgWeb's side of custom domains: after the domain is registered (egweb.com.eg) or pointed to the server, and added
 * as an alias with SSL on the hosting account, the admin activates it. Activating makes the menu and QR codes use it.
 */
class DomainsController extends Controller
{
    public function update(Request $request, Domain $domain): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['requested', 'pending', 'active', 'rejected'])],
            'note' => 'nullable|string|max:300',
        ]);
        $domain->update([
            'status' => $data['status'], 'note' => $data['note'] ?? null,
            'verified_at' => $data['status'] === 'active' ? ($domain->verified_at ?? now()) : null,
        ]);

        return back()->with('ok', __('Domain updated.'));
    }
}
