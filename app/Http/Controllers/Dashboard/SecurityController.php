<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Auth\SecurityController as BaseSecurityController;
use App\Models\User;
use App\Services\Corporate\CorporateVouchers;
use App\Services\Entitlements;
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
    /** A manager PIN is for someone who approves on another person's device: voucher exceptions or POS refunds. */
    public static function mayHavePin(User $user): bool
    {
        return ($user->can('vouchers.exception') && CorporateVouchers::enabled())
            || ($user->can('pos.refund') && Entitlements::current()->allows('pos'));
    }

    public function pin(Request $request, CorporateVouchers $service): RedirectResponse
    {
        $user = $request->user();
        abort_unless(self::mayHavePin($user), 403);
        $remove = $request->boolean('remove');
        $data = $request->validate([
            'current_password' => ['required', 'current_password:web'],
            'pin' => $remove ? 'nullable' : ['required', 'digits_between:4,6', 'confirmed'],
        ]);
        $service->setPin($user, $remove ? null : (string) $data['pin']);

        return back()->with('ok', $remove ? __('Manager PIN removed.') : __('Manager PIN saved.'));
    }
}
