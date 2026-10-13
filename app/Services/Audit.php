<?php

namespace App\Services;

use App\Models\AuditLog;

/** Records who did what in the dashboard (the `audit_log` plan feature shows it). Always writes; the page is what is gated. */
final class Audit
{
    /** @param array<string,mixed> $meta */
    public static function record(string $action, string $subject = '', array $meta = []): void
    {
        if (tenant() === null) {
            return;
        }
        AuditLog::query()->create([
            'user_id' => auth()->id(),
            'action' => $action,
            'subject' => mb_substr($subject, 0, 255),
            'meta' => $meta ?: null,
            'ip' => (string) request()->ip(),
        ]);
    }
}
