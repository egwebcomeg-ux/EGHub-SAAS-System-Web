<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
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

        if (!$user || !Hash::check($data['password'], $user->password)) {
            return back()->withInput(['login' => $login])->withErrors(['login' => __('Wrong login or password.')]);
        }
        if (!$user->is_active || !Tenant::query()->whereKey($user->tenant_id)->exists()) {
            return back()->withInput(['login' => $login])->withErrors(['login' => __('Your account is not active.')]);
        }

        Auth::guard('web')->login($user, $request->boolean('remember'));
        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->save();

        return redirect()->intended(route('dash.home'));
    }

    public function logout(Request $request): RedirectResponse
    {
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
