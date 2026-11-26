<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Middleware\ConfirmIdentity;
use App\Services\Audit;
use App\Services\Auth\EmailCode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * "Is it really you?" before a sensitive action (App\Http\Middleware\ConfirmIdentity): the code emailed to the
 * account, or its password when it has no email. Success is remembered in the session for a few minutes.
 */
class ConfirmIdentityController extends Controller
{
    public function show(Request $request): View|RedirectResponse
    {
        if (ConfirmIdentity::confirmed($request)) {
            return redirect()->intended(route('dash.home'));
        }
        $user = $request->user();
        $byEmail = filled($user->email);
        if ($byEmail && ! EmailCode::pending($user, EmailCode::CONFIRM)) {
            EmailCode::send($user, EmailCode::CONFIRM, __('a sensitive action in your dashboard'));
        }

        return view('dashboard.confirm-identity', ['byEmail' => $byEmail, 'email' => EmailCode::mask($user->email)]);
    }

    public function confirm(Request $request): RedirectResponse
    {
        $user = $request->user();
        if (filled($user->email)) {
            $data = $request->validate(['code' => 'required|string|max:12']);
            if (! EmailCode::check($user, EmailCode::CONFIRM, $data['code'])) {
                return back()->withErrors(['code' => __('The code is not right or has expired. Ask for a new one.')]);
            }
            if ($user->email_verified_at === null) {
                $user->forceFill(['email_verified_at' => now()])->save();
            }
        } else {
            $request->validate(['password' => ['required', 'current_password:web']]);
        }
        $request->session()->put(ConfirmIdentity::SESSION, now()->timestamp);
        Audit::record('identity.confirmed', $user->name, ['id' => $user->id, 'via' => filled($user->email) ? 'email' : 'password']);

        return redirect()->intended(route('dash.home'));
    }

    public function resend(Request $request): RedirectResponse
    {
        $user = $request->user();
        if (blank($user->email) || ! EmailCode::send($user, EmailCode::CONFIRM, __('a sensitive action in your dashboard'))) {
            return back()->withErrors(['code' => __('A code was sent less than a minute ago. Check your inbox and spam folder.')]);
        }

        return back()->with('ok', __('We sent a 6-digit code to :email.', ['email' => EmailCode::mask($user->email)]));
    }
}
