<?php

namespace App\Services;

use App\Models\AuditLog;

/**
 * Records who did what in the dashboard (the `audit_log` plan feature shows it). Always writes; the page is what is gated.
 * While EgWeb support is signed in from the admin (App\Services\SupportAccess) every entry also names that admin.
 */
final class Audit
{
    /**
     * @param  array<string,mixed>  $meta
     * @param  int|null  $userId  who did it when nobody is signed in yet (a login waiting for its 2FA code)
     */
    public static function record(string $action, string $subject = '', array $meta = [], ?int $userId = null): void
    {
        if (tenant() === null) {
            return;
        }
        $request = request();
        if (SupportAccess::active($request)) {
            $meta['support_admin'] = (string) $request->session()->get(SupportAccess::ADMIN_EMAIL);
        }
        AuditLog::query()->create([
            'user_id' => $userId ?? auth()->id(),
            'action' => $action,
            'subject' => mb_substr($subject, 0, 255),
            'meta' => $meta ?: null,
            'ip' => (string) request()->ip(),
        ]);
    }
}
