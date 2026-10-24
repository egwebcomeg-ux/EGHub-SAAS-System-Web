<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Auth\SecurityController as BaseSecurityController;
use App\Models\User;
use App\Services\Audit;
use App\Services\Corporate\CorporateVouchers;
use App\Services\Entitlements;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

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

    /**
     * The account's own password. A welcome-kit owner (must_change_password) confirms with the temporary one they
     * signed in with; the new password retires it (App\Models\User) and opens the rest of the dashboard.
     */
    public function password(Request $request): RedirectResponse
    {
        $user = $request->user();
        $request->validate([
            'current_password' => ['required', 'current_password:web'],
            'password' => ['required', 'string', 'max:200', 'confirmed', 'different:current_password', Password::min(8)->letters()->numbers()],
        ]);
        $first = $user->must_change_password;
        $user->forceFill(['password' => $request->input('password')])->save();
        Audit::record('user.password_changed', $user->name, ['id' => $user->id, 'from_temporary' => $first]);

        return $first
            ? redirect()->route('dash.home')->with('ok', __('Your new password is saved. Welcome to your dashboard!'))
            : back()->with('ok', __('Your new password is saved.'));
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
