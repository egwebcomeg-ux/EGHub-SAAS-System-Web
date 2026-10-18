<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PlatformAdmin;
use App\Services\Auth\TwoFactor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function show(): View|RedirectResponse
    {
        return Auth::guard('admin')->check() ? redirect()->route('admin.home') : view('admin.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $data = $request->validate(['email' => 'required|email|max:120', 'password' => 'required|string|max:200']);
        $admin = PlatformAdmin::query()->where('email', strtolower($data['email']))->first();
        if (! $admin || ! Hash::check($data['password'], $admin->password)) {
            return back()->withInput(['email' => $data['email']])->withErrors(['email' => __('Wrong login or password.')]);
        }
        if (! $admin->is_active) {
            return back()->withInput(['email' => $data['email']])->withErrors(['email' => __('This account is turned off. Ask the platform owner.')]);
        }
        // second step: the code from the authenticator app, unless this browser was remembered within 30 days
        if ($admin->hasTwoFactorEnabled() && ! TwoFactor::isRemembered($request, 'admin', $admin)) {
            TwoFactor::challenge($request, 'admin', $admin, $request->boolean('remember'));

            return redirect()->route('admin.two-factor');
        }

        Auth::guard('admin')->login($admin, $request->boolean('remember'));
        $request->session()->regenerate();
        $admin->forceFill(['last_login_at' => now()])->save();

        return redirect()->intended(route('admin.home'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('admin')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }
}
