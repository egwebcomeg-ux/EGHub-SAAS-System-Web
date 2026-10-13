<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\View\View;

/**
 * Forgot / reset password, shared by the restaurant dashboard and the platform admin; each subclass names its
 * password broker and route prefix. The answer never says whether an email exists.
 */
abstract class PasswordResetController extends Controller
{
    abstract protected function broker(): string;

    /** Route name prefix: "dash" or "admin". */
    abstract protected function prefix(): string;

    public function request(): View
    {
        return view('auth.forgot-password', ['prefix' => $this->prefix()]);
    }

    public function send(Request $request): RedirectResponse
    {
        $data = $request->validate(['email' => 'required|email|max:120']);
        Password::broker($this->broker())->sendResetLink(['email' => strtolower(trim($data['email']))]);

        return back()->with('ok', __('If this email belongs to an account, a reset link is on its way. Check your inbox and spam folder.'));
    }

    public function edit(Request $request, string $token): View
    {
        return view('auth.reset-password', ['prefix' => $this->prefix(), 'token' => $token, 'email' => (string) $request->query('email', '')]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'token' => 'required|string',
            'email' => 'required|email|max:120',
            'password' => ['required', 'confirmed', PasswordRule::min(8)],
        ]);

        $status = Password::broker($this->broker())->reset(
            ['email' => strtolower(trim($data['email']))] + $data,
            function (CanResetPassword $user, string $password): void {
                $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            return back()->withInput(['email' => $data['email']])->withErrors(['email' => __('This reset link is invalid or has expired. Ask for a new one.')]);
        }

        return redirect()->route($this->prefix().'.login')->with('ok', __('Your password was changed. Sign in with the new one.'));
    }
}
