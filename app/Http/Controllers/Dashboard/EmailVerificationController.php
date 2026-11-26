<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Services\Audit;
use App\Services\Auth\EmailCode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * "Confirm your email": a 6-digit code to the account's email (sent at signup, on the first visit here, or again on
 * request) typed on this page stamps users.email_verified_at. Never a gate: the dashboard works unconfirmed, a
 * banner only reminds (dashboard.partials.email-banner).
 */
class EmailVerificationController extends Controller
{
    public function show(Request $request): View|RedirectResponse
    {
        $user = $request->user();
        if (blank($user->email) || $user->email_verified_at !== null) {
            return redirect()->route('dash.home');
        }
        if (! EmailCode::pending($user, EmailCode::VERIFY)) {
            EmailCode::send($user, EmailCode::VERIFY);
        }

        return view('dashboard.email', ['user' => $user, 'email' => EmailCode::mask($user->email)]);
    }

    public function verify(Request $request): RedirectResponse
    {
        $data = $request->validate(['code' => 'required|string|max:12']);
        $user = $request->user();
        if ($user->email_verified_at !== null) {
            return redirect()->route('dash.home');
        }
        if (! EmailCode::check($user, EmailCode::VERIFY, $data['code'])) {
            return back()->withErrors(['code' => __('The code is not right or has expired. Ask for a new one.')]);
        }
        $user->forceFill(['email_verified_at' => now()])->save();
        Audit::record('email.verified', (string) $user->email, ['user_id' => $user->id]);

        return redirect()->route('dash.home')->with('ok', __('Your email is confirmed.'));
    }

    public function resend(Request $request): RedirectResponse
    {
        $user = $request->user();
        if ($user->email_verified_at !== null || blank($user->email)) {
            return redirect()->route('dash.home');
        }
        if (! EmailCode::send($user, EmailCode::VERIFY)) {
            return back()->withErrors(['code' => __('A code was sent less than a minute ago. Check your inbox and spam folder.')]);
        }

        return back()->with('ok', __('We sent a 6-digit code to :email.', ['email' => EmailCode::mask($user->email)]));
    }
}
