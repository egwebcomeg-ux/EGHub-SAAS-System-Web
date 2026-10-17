<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\PlatformAdmin;
use App\Models\User;
use App\Services\Audit;
use App\Services\Auth\Totp;
use App\Services\Auth\TwoFactor;
use App\Services\Qr;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * "Account security" page of a signed-in user: turn two-factor authentication on (scan the QR, confirm one code,
 * get the recovery codes once) or off (with the current password). Shared by the dashboard (guard web) and the
 * platform admin (guard admin); the subclasses only name their guard, route prefix and view.
 */
abstract class SecurityController extends Controller
{
    protected string $guard;

    /** Route name prefix, e.g. "dash." → dash.security, dash.security.enable, ... */
    protected string $routes;

    protected string $view;

    public function show(Request $request): View
    {
        $user = $this->user($request);
        $pending = $user->two_factor_secret !== null && ! $user->hasTwoFactorEnabled();

        return view($this->view, [
            'user' => $user,
            'pending' => $pending,
            'secret' => $pending ? $user->two_factor_secret : null,
            'qr' => $pending ? Qr::svg(Totp::uri('EgMenu', $this->account($user), $user->two_factor_secret), 220, '#0A2540') : null,
            'recoveryCodes' => $request->session()->get('two_factor_recovery_codes'),
            'routes' => $this->routes,
            'required' => $user instanceof User && TwoFactor::setupRequired($user),
        ]);
    }

    /** Step 1: a fresh secret to scan. Nothing is enforced until a code confirms the app has it. */
    public function enable(Request $request): RedirectResponse
    {
        $user = $this->user($request);
        if ($user->hasTwoFactorEnabled()) {
            return redirect()->route($this->routes.'security');
        }
        $user->startTwoFactorSetup(Totp::secret());

        return redirect()->route($this->routes.'security')->with('ok', __('Scan the code with your authenticator app, then enter the 6 digits it shows.'));
    }

    /** Step 2: the first code from the app turns 2FA on and issues the recovery codes (shown once). */
    public function confirm(Request $request): RedirectResponse
    {
        $data = $request->validate(['code' => 'required|string|max:12']);
        $user = $this->user($request);
        if ($user->hasTwoFactorEnabled() || $user->two_factor_secret === null) {
            return redirect()->route($this->routes.'security');
        }
        if (! Totp::verify($user->two_factor_secret, $data['code'])) {
            return back()->withErrors(['code' => __('The code is not right. Check the time on your phone and try again.')]);
        }
        $codes = $user->confirmTwoFactor();
        Audit::record('two_factor.enabled', $user->name, ['id' => $user->id]);

        return redirect()->route($this->routes.'security')
            ->with('two_factor_recovery_codes', $codes)
            ->with('ok', __('Two-factor authentication is on. Save your recovery codes somewhere safe.'));
    }

    public function disable(Request $request): RedirectResponse
    {
        $request->validate(['password' => ['required', 'current_password:'.$this->guard]]);
        $user = $this->user($request);
        $user->disableTwoFactor();
        Audit::record('two_factor.disabled', $user->name, ['id' => $user->id]);
        cookie()->queue(TwoFactor::forgetCookie($this->guard));

        return redirect()->route($this->routes.'security')->with('ok', __('Two-factor authentication is off.'));
    }

    protected function user(Request $request): User|PlatformAdmin
    {
        return $request->user($this->guard);
    }

    /** The account label inside the authenticator app. */
    protected function account(User|PlatformAdmin $user): string
    {
        $login = (string) ($user->email ?? $user->phone);

        return $user instanceof User ? $login.' · '.tenant()->name : $login;
    }
}
