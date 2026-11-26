<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Auth\SecurityController as BaseSecurityController;
use App\Models\User;
use App\Services\Audit;
use App\Services\Auth\EmailCode;
use App\Services\Auth\TwoFactor;
use App\Services\Corporate\CorporateVouchers;
use App\Services\Entitlements;
use App\Support\Passwords;
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

    /**
     * The account's own password. A welcome-kit owner (must_change_password) confirms with the temporary one they
     * signed in with; the new password retires it (App\Models\User) and opens the rest of the dashboard.
     */
    public function password(Request $request): RedirectResponse
    {
        $user = $request->user();
        $request->validate([
            'current_password' => ['required', 'current_password:web'],
            'password' => ['required', 'string', 'max:200', 'confirmed', 'different:current_password', Passwords::rule()],
        ]);
        $first = $user->must_change_password;
        $user->forceFill(['password' => $request->input('password')])->save();
        Audit::record('user.password_changed', $user->name, ['id' => $user->id, 'from_temporary' => $first]);

        return $first
            ? redirect()->route('dash.home')->with('ok', __('Your new password is saved. Welcome to your dashboard!'))
            : back()->with('ok', __('Your new password is saved.'));
    }

    /** Email two-factor, step 1: a code to the account's email proves it arrives there. */
    public function emailStart(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless(TwoFactor::emailAllowed($user), 403);
        if ($user->hasTwoFactorEnabled()) {
            return redirect()->route('dash.security');
        }
        if (! EmailCode::send($user, EmailCode::ENABLE) && ! EmailCode::pending($user, EmailCode::ENABLE)) {
            return back()->withErrors(['email_code' => __('We could not send the code. Try again in a minute.')]);
        }

        return redirect()->route('dash.security')->with('ok', __('We sent a 6-digit code to :email.', ['email' => EmailCode::mask($user->email)]));
    }

    /** Step 2: the code from the email turns it on (and marks the email confirmed). */
    public function emailConfirm(Request $request): RedirectResponse
    {
        $data = $request->validate(['code' => 'required|string|max:12']);
        $user = $request->user();
        abort_unless(TwoFactor::emailAllowed($user), 403);
        if ($user->hasTwoFactorEnabled()) {
            return redirect()->route('dash.security');
        }
        if (! EmailCode::check($user, EmailCode::ENABLE, $data['code'])) {
            return back()->withErrors(['email_code' => __('The code is not right or has expired. Ask for a new one.')]);
        }
        $user->enableEmailTwoFactor();
        Audit::record('two_factor.enabled', $user->name, ['id' => $user->id, 'via' => 'email']);

        return redirect()->route('dash.security')->with('ok', __('Two-factor authentication is on: a code reaches your email at each sign-in.'));
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
