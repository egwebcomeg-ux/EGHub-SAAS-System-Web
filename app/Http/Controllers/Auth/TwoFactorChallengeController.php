<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\TwoFactor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Second login step: the password matched (App\Services\Auth\TwoFactor::challenge), now a TOTP or recovery code
 * is needed before anybody is signed in. The POST is rate-limited in the routes (5 tries a minute).
 */
abstract class TwoFactorChallengeController extends Controller
{
    protected string $guard;

    /** Route name prefix, e.g. "dash." → dash.two-factor, dash.login, dash.home */
    protected string $routes;

    public function show(Request $request): View|RedirectResponse
    {
        if (! TwoFactor::pending($request, $this->guard)) {
            return redirect()->route($this->routes.'login');
        }

        return view('auth.two-factor-challenge', [
            'action' => route($this->routes.'two-factor'),
            'login' => route($this->routes.'login'),
            'dark' => $this->guard === 'admin',
        ]);
    }

    public function verify(Request $request): RedirectResponse
    {
        $data = $request->validate(['code' => 'required|string|max:40']);
        $user = TwoFactor::pending($request, $this->guard);
        if (! $user) {
            return redirect()->route($this->routes.'login')->withErrors(['login' => __('Your session expired. Please sign in again.')]);
        }
        if (! TwoFactor::check($user, $data['code'])) {
            return back()->withErrors(['code' => __('The code is not right. Try again.')]);
        }
        TwoFactor::complete($request, $this->guard, $user, $request->boolean('remember_device'));

        return redirect()->intended(route($this->routes.'home'));
    }
}
