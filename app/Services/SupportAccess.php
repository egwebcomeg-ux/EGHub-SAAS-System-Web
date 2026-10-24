<?php

namespace App\Services;

use App\Models\PlatformAdmin;
use App\Models\PlatformAuditLog;
use App\Models\SupportAccessTicket;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * "Sign in to the restaurant's dashboard" for EgWeb support (admin permission tenants.impersonate). Sessions are per
 * host, so the admin page issues a one-time ticket (random, stored hashed, bound to the admin, the restaurant and
 * the staff account, valid 60 seconds) and sends the browser to app.<domain>/support-access/{ticket}, which uses it
 * once and signs that staff account in without touching its password or remember token.
 *
 * While it lasts the dashboard shows a banner, a few account and money actions are blocked
 * (App\Http\Middleware\BlockDuringSupportAccess) and it ends by itself after 60 minutes. Start, end and expiry are
 * written to the platform audit log and to the restaurant's own audit trail.
 */
final class SupportAccess
{
    public const TICKET_SECONDS = 60;

    public const SESSION_MINUTES = 60;

    /** Session keys of the dashboard session opened from the admin. */
    public const ADMIN = 'impersonated_by_admin_id';

    public const STARTED = 'impersonation_started_at';

    public const ADMIN_EMAIL = 'impersonation_admin_email';

    public const USER = 'impersonation_user_id';

    public const TENANT = 'impersonation_tenant_id';

    /** A new one-time ticket; returns the plain ticket (only its hash is stored). Old rows are pruned on the way. */
    public function issue(PlatformAdmin $admin, Tenant $tenant, User $user): string
    {
        SupportAccessTicket::query()->where('expires_at', '<', now()->subDay())->delete();
        $ticket = random_token(24);
        SupportAccessTicket::query()->create([
            'token_hash' => hash('sha256', $ticket), 'admin_id' => $admin->id, 'tenant_id' => $tenant->id,
            'user_id' => $user->id, 'expires_at' => now()->addSeconds(self::TICKET_SECONDS),
        ]);

        return $ticket;
    }

    /**
     * Uses the ticket up (an atomic update, so two requests can never both win) and returns who it is for, or null
     * when it is unknown, used, expired, or the admin, the restaurant or the account is no longer allowed.
     *
     * @return array{admin: PlatformAdmin, tenant: Tenant, user: User}|null
     */
    public function redeem(string $ticket): ?array
    {
        $hash = hash('sha256', $ticket);
        $consumed = SupportAccessTicket::query()->where('token_hash', $hash)->whereNull('used_at')
            ->where('expires_at', '>', now())->update(['used_at' => now()]);
        if ($consumed !== 1) {
            return null;
        }
        $row = SupportAccessTicket::query()->where('token_hash', $hash)->firstOrFail();
        $admin = PlatformAdmin::query()->find($row->admin_id);
        $tenant = Tenant::query()->find($row->tenant_id);
        $user = $tenant ? User::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->find($row->user_id) : null;
        if (! $admin?->allows('tenants.impersonate') || ! $tenant || ! $user?->is_active) {
            return null;
        }

        return ['admin' => $admin, 'tenant' => $tenant, 'user' => $user];
    }

    /** Signs $user in on this (dashboard) session, in a fresh session, and records it on both sides. */
    public function start(Request $request, PlatformAdmin $admin, Tenant $tenant, User $user): void
    {
        if (Auth::guard('web')->check()) {
            Auth::guard('web')->logout();
        }
        $request->session()->invalidate();
        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $request->session()->regenerateToken();
        $request->session()->put([
            self::ADMIN => $admin->id, self::STARTED => now()->getTimestamp(), self::ADMIN_EMAIL => $admin->email,
            self::USER => $user->id, self::TENANT => $tenant->id,
        ]);

        PlatformAuditLog::record($admin, 'tenant.impersonation_started', $tenant->id, ['user' => $user->id, 'user_name' => $user->name]);
        $this->inTenant($tenant, fn () => Audit::record('support.access_by_platform', $user->name, [
            'admin' => $admin->email, 'user' => $user->id,
        ], $user->id));
    }

    public static function active(Request $request): bool
    {
        return $request->hasSession() && $request->session()->has(self::ADMIN);
    }

    /** The session is past its 60 minutes. */
    public static function expired(Request $request): bool
    {
        $started = (int) $request->session()->get(self::STARTED, 0);

        return now()->getTimestamp() - $started >= self::SESSION_MINUTES * 60;
    }

    /** Minutes left, for the banner. */
    public static function minutesLeft(Request $request): int
    {
        $started = (int) $request->session()->get(self::STARTED, 0);

        return max(0, (int) ceil(($started + self::SESSION_MINUTES * 60 - now()->getTimestamp()) / 60));
    }

    /**
     * Why this support session must stop now: 'expired' after 60 minutes, 'revoked' when the admin was switched off
     * or lost the permission, 'mismatch' when the signed-in account is not the one the ticket was for; null = fine.
     */
    public function problem(Request $request, User $user): ?string
    {
        if (self::expired($request)) {
            return 'expired';
        }
        if ((int) $request->session()->get(self::USER) !== $user->id || (string) $request->session()->get(self::TENANT) !== (string) $user->tenant_id) {
            return 'mismatch';
        }
        $admin = PlatformAdmin::query()->find((int) $request->session()->get(self::ADMIN));

        return $admin?->allows('tenants.impersonate') ? null : 'revoked';
    }

    /**
     * Ends the support session: records it ('ended' when the admin left, else 'expired' / 'revoked' / 'mismatch'),
     * signs the account out of this browser only and drops the session. Returns the restaurant's id.
     */
    public function end(Request $request, string $reason = 'ended'): ?string
    {
        $session = $request->session();
        $tenantId = $session->get(self::TENANT);
        $userId = (int) $session->get(self::USER);
        $admin = PlatformAdmin::query()->find((int) $session->get(self::ADMIN));
        $minutes = (int) floor((now()->getTimestamp() - (int) $session->get(self::STARTED, 0)) / 60);

        $action = $reason === 'ended' ? 'ended' : 'expired';
        PlatformAuditLog::record($admin, "tenant.impersonation_$action", (string) $tenantId, [
            'user' => $userId, 'reason' => $reason, 'minutes' => $minutes, 'admin_id' => $session->get(self::ADMIN),
        ]);
        $tenant = $tenantId ? Tenant::query()->find($tenantId) : null;
        if ($tenant) {
            $user = User::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->find($userId);
            $this->inTenant($tenant, fn () => Audit::record('support.exit_by_platform', (string) $user?->name, [
                'admin' => $session->get(self::ADMIN_EMAIL), 'how' => $reason, 'minutes' => $minutes,
            ], $user?->id));
        }

        Auth::guard('web')->logout();
        $session->invalidate();
        $session->regenerateToken();

        return $tenantId ? (string) $tenantId : null;
    }

    private function inTenant(Tenant $tenant, \Closure $fn): void
    {
        if (tenancy()->initialized && tenant()?->getTenantKey() === $tenant->getTenantKey()) {
            $fn();

            return;
        }
        $previous = tenancy()->initialized ? tenant() : null;
        tenancy()->initialize($tenant);
        try {
            $fn();
        } finally {
            $previous ? tenancy()->initialize($previous) : tenancy()->end();
            Settings::flush();
        }
    }
}
