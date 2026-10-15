<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
        if (! Auth::guard('admin')->attempt(['email' => strtolower($data['email']), 'password' => $data['password']], $request->boolean('remember'))) {
            return back()->withInput(['email' => $data['email']])->withErrors(['email' => __('Wrong login or password.')]);
        }
        $request->session()->regenerate();
        Auth::guard('admin')->user()->forceFill(['last_login_at' => now()])->save();

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
