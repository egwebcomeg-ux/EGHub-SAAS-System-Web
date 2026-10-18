<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit;
use Illuminate\Auth\SessionGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * Support for a restaurant's users from the admin restaurant page (tenants.manage): email a password reset link,
 * set a temporary password (shown once) or sign the user out everywhere. Each action goes to the restaurant's audit
 * trail with the admin's email. The user is always looked up inside the restaurant from the URL.
 */
class TenantUsersController extends Controller
{
    public function sendResetLink(Request $request, Tenant $tenant, int $user): RedirectResponse
    {
        $staff = $this->staff($tenant, $user);
        if (! $staff->email) {
            return back()->withErrors(['email' => __('This user signs in with a mobile number and has no email. Set a temporary password instead.')]);
        }

        $status = Password::broker('users')->sendResetLink(['email' => $staff->email]);
        if ($status === Password::RESET_THROTTLED) {
            return back()->withErrors(['email' => __('A reset link was sent a moment ago. Wait a minute before sending another.')]);
        }
        if ($status !== Password::RESET_LINK_SENT) {
            return back()->withErrors(['email' => __('The reset link could not be sent.')]);
        }
        $this->audit($request, $tenant, 'user.password_link_by_platform', $staff);

        return back()->with('ok', __('A password reset link was emailed to :email.', ['email' => $staff->email]));
    }

    /** A new random password, shown to the admin once (to pass on by phone); the old sessions end. */
    public function setPassword(Request $request, Tenant $tenant, int $user): RedirectResponse
    {
        $staff = $this->staff($tenant, $user);
        $password = Str::password(12, symbols: false);
        $staff->forceFill(['password' => $password])->save();
        $this->endSessions($staff);
        $this->audit($request, $tenant, 'user.password_set_by_platform', $staff);

        return back()->with('ok', __('New temporary password set for :name.', ['name' => $staff->name]))
            ->with('temporary_password', ['user' => $staff->id, 'name' => $staff->name, 'password' => $password]);
    }

    public function signOut(Request $request, Tenant $tenant, int $user): RedirectResponse
    {
        $staff = $this->staff($tenant, $user);
        $this->endSessions($staff);
        $this->audit($request, $tenant, 'user.signed_out_by_platform', $staff);

        return back()->with('ok', __(':name was signed out on every device.', ['name' => $staff->name]));
    }

    private function staff(Tenant $tenant, int $user): User
    {
        return User::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->findOrFail($user);
    }

    /**
     * New remember token (ends "remember me" cookies) and, with database sessions, removes the user's sessions.
     *
     * The sessions table's user_id belongs to whichever guard served the request, so on the admin panel it holds
     * platform admin ids. A session is only removed when its payload is signed in on the restaurant ("web") guard as
     * this user; otherwise an admin whose id equals the user's id would sign themselves out.
     */
    private function endSessions(User $staff): void
    {
        $staff->forceFill(['remember_token' => Str::random(60)])->save();
        if (config('session.driver') !== 'database') {
            return;
        }
        $sessions = DB::connection(config('session.connection'))->table(config('session.table', 'sessions'));
        $webKey = 'login_web_'.sha1(SessionGuard::class);
        $ids = (clone $sessions)->where('user_id', $staff->id)->get(['id', 'payload'])
            ->filter(function (object $row) use ($webKey, $staff): bool {
                $data = @unserialize(base64_decode((string) $row->payload), ['allowed_classes' => false]);

                return is_array($data) && (string) ($data[$webKey] ?? '') === (string) $staff->id;
            })
            ->pluck('id');
        if ($ids->isNotEmpty()) {
            $sessions->whereIn('id', $ids)->delete();
        }
    }

    private function audit(Request $request, Tenant $tenant, string $action, User $staff): void
    {
        tenancy()->initialize($tenant);
        try {
            Audit::record($action, $staff->name, ['id' => $staff->id, 'admin' => $request->user('admin')->email]);
        } finally {
            tenancy()->end();
        }
    }
}
