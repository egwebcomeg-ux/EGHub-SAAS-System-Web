<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Auth\SecurityController as BaseSecurityController;
use App\Models\PlatformAuditLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

/** Account security of a platform admin (admin.<domain>/security): two-factor sign-in and the admin's own password. */
class SecurityController extends BaseSecurityController
{
    protected string $guard = 'admin';

    protected string $routes = 'admin.';

    protected string $view = 'admin.security';

    /** The admin's own password, confirmed with the current one. */
    public function password(Request $request): RedirectResponse
    {
        $admin = $request->user('admin');
        $request->validate([
            'current_password' => ['required', 'current_password:admin'],
            'password' => ['required', 'string', 'max:200', 'confirmed', 'different:current_password', Password::min(10)->letters()->numbers()],
        ]);
        $admin->forceFill(['password' => $request->input('password')])->save();
        PlatformAuditLog::record($admin, 'admin.password_changed', $admin->email);

        return back()->with('ok', __('Your new password is saved.'));
    }
}
