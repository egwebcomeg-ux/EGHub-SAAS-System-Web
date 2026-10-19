<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Auth\SecurityController as BaseSecurityController;
use App\Services\Corporate\CorporateVouchers;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Account security of a restaurant staff member (app.<domain>/security), with the manager PIN for exceptions. */
class SecurityController extends BaseSecurityController
{
    protected string $guard = 'web';

    protected string $routes = 'dash.';

    protected string $view = 'dashboard.security';

    /**
     * The manager PIN (4–6 digits, stored hashed): typed on a cashier's device to approve an expired corporate
     * voucher without signing the cashier out. Setting or removing it asks for the account password.
     */
    public function pin(Request $request, CorporateVouchers $service): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->can('vouchers.exception'), 403);
        $remove = $request->boolean('remove');
        $data = $request->validate([
            'current_password' => ['required', 'current_password:web'],
            'pin' => $remove ? 'nullable' : ['required', 'digits_between:4,6', 'confirmed'],
        ]);
        $service->setPin($user, $remove ? null : (string) $data['pin']);

        return back()->with('ok', $remove ? __('Manager PIN removed.') : __('Manager PIN saved.'));
    }
}
