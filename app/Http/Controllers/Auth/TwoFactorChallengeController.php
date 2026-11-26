<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\EmailCode;
use App\Services\Auth\TwoFactor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Second login step: the password matched (App\Services\Auth\TwoFactor::challenge), now a TOTP, emailed or recovery
 * code is needed before anybody is signed in. The POSTs are rate-limited in the routes.
 */
abstract class TwoFactorChallengeController extends Controller
{
    protected string $guard;

    /** Route name prefix, e.g. "dash." → dash.two-factor, dash.login, dash.home */
    protected string $routes;

    public function show(Request $request): View|RedirectResponse
    {
        $user = TwoFactor::pending($request, $this->guard);
        if (! $user) {
            return redirect()->route($this->routes.'login');
        }

        return view('auth.two-factor-challenge', [
            'action' => route($this->routes.'two-factor'),
            'login' => route($this->routes.'login'),
            'dark' => $this->guard === 'admin',
            // the code is in the inbox (email two-factor), or the app user may ask for one there instead
            'byEmail' => $user->hasEmailTwoFactor(),
            'emailAction' => TwoFactor::emailAllowed($user) ? route($this->routes.'two-factor.email') : null,
            'email' => EmailCode::mask($user->email),
        ]);
    }

    /** "Email me a code": for staff with an email, whether email is their way or the phone with the app is not at hand. */
    public function email(Request $request): RedirectResponse
    {
        $user = TwoFactor::pending($request, $this->guard);
        if (! $user || ! TwoFactor::emailAllowed($user)) {
            return redirect()->route($this->routes.'login');
        }
        if (! EmailCode::send($user, EmailCode::LOGIN)) {
            return back()->withErrors(['code' => __('A code was sent less than a minute ago. Check your inbox and spam folder.')]);
        }

        return back()->with('ok', __('We sent a 6-digit code to :email.', ['email' => EmailCode::mask($user->email)]));
    }

    public function verify(Request $request): RedirectResponse
    {
        $data = $request->validate(['code' => 'required|string|max:40']);
        $user = TwoFactor::pending($request, $this->guard);
        if (! $user) {
            return redirect()->route($this->routes.'login')->withErrors(['login' => __('Your session expired. Please sign in again.')]);
        }
        if (! TwoFactor::check($user, $data['code'])) {
            if (! TwoFactor::failed($request, $this->guard)) {
                return redirect()->route($this->routes.'login')->withErrors(['login' => __('Too many wrong codes. Sign in again.')]);
            }

            return back()->withErrors(['code' => __('The code is not right. Try again.')]);
        }
        TwoFactor::complete($request, $this->guard, $user, $request->boolean('remember_device'));

        return redirect()->intended(route($this->routes.'home'));
    }
}
