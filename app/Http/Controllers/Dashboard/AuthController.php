<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\TwoFactor;
use App\Services\SupportAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

/** Staff sign-in on the shared dashboard host with email or mobile number. */
class AuthController extends Controller
{
    public function show(): View|RedirectResponse
    {
        return Auth::guard('web')->check() ? redirect()->route('dash.home') : view('dashboard.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $data = $request->validate(['login' => 'required|string|max:120', 'password' => 'required|string|max:200']);
        $login = trim($data['login']);
        $phone = normalize_phone($login);

        // Central context: no tenant scope yet, the identifier is unique platform-wide.
        $user = User::query()->where(str_contains($login, '@') ? 'email' : 'phone', str_contains($login, '@') ? strtolower($login) : $phone)->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            return back()->withInput(['login' => $login])->withErrors(['login' => __('Wrong login or password.')]);
        }
        if (! self::mayEnter($user)) {
            return back()->withInput(['login' => $login])->withErrors(['login' => __('Your account is not active.')]);
        }
        // second step: the code from the authenticator app, unless this browser was remembered within 30 days
        if ($user->hasTwoFactorEnabled() && ! TwoFactor::isRemembered($request, 'web', $user)) {
            TwoFactor::challenge($request, 'web', $user, $request->boolean('remember'));

            return redirect()->route('dash.two-factor');
        }

        Auth::guard('web')->login($user, $request->boolean('remember'));
        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->save();

        return redirect()->intended(route('dash.home'));
    }

    /**
     * Deactivated users and removed restaurants never get in, whatever the way in (password, Google, Facebook).
     * A suspended restaurant does: its menu is offline but the dashboard stays reachable to renew.
     */
    public static function mayEnter(User $user): bool
    {
        return $user->is_active && Tenant::query()->whereKey($user->tenant_id)->exists();
    }

    /** One-time signed link from signup (valid 5 minutes, only for a user who never logged in). */
    public function welcome(Request $request, int $user): RedirectResponse
    {
        abort_unless($request->hasValidSignature(), 403);
        $owner = User::query()->whereNull('last_login_at')->findOrFail($user);
        Auth::guard('web')->login($owner, true);
        $request->session()->regenerate();
        $owner->forceFill(['last_login_at' => now()])->save();

        // first stop: "what will you use?" (the signup owner may always pick; skippable), then confirming the mobile
        // on WhatsApp when the platform has a number (skippable, never a gate), then the home page
        return redirect()->route('dash.modules', ['welcome' => 1])->with('platform_signup', true)->with('ok', __('Welcome to EgHub! Your 14-day free trial has started.'));
    }

    /** Leaving a support session opened from the admin ends it (recorded) and goes back to the admin page. */
    public function logout(Request $request, SupportAccess $support): RedirectResponse
    {
        if (SupportAccess::active($request)) {
            $tenantId = $support->end($request);

            return redirect()->away($tenantId ? route('admin.tenants.show', $tenantId) : route('admin.tenants'));
        }
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('dash.login');
    }

    public function locale(Request $request): RedirectResponse
    {
        $locale = $request->input('locale') === 'en' ? 'en' : 'ar';
        $request->user()->forceFill(['locale' => $locale])->save();

        return back();
    }
}
