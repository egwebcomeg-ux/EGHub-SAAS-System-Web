<?php

namespace App\Billing;

use App\Models\Subscription;
use App\Services\Entitlements;

/**
 * Daily subscription housekeeping (egmenu:billing). No data is ever deleted:
 *   trial or paid period ended  → past_due with GRACE_DAYS of grace (menu still online)
 *   grace ended                 → restaurant suspended (menu offline, dashboard open so the owner can pay)
 *   3 days before the end       → reminder flag (dashboard banner; WhatsApp/email later)
 * Paying an invoice (Billing::markPaid) brings everything back.
 */
final class Lifecycle
{
    public const REMIND_DAYS = 3;

    /** @return array{past_due:int, suspended:int, reminded:int} */
    public function run(): array
    {
        $out = ['past_due' => 0, 'suspended' => 0, 'reminded' => 0];

        Subscription::query()->with('tenant')->whereIn('status', ['trialing', 'active'])->get()
            ->each(function (Subscription $sub) use (&$out) {
                $end = $sub->endsAt();
                if ($end && $end->isPast()) {
                    $sub->update(['status' => 'past_due', 'grace_ends_at' => now()->addDays(Billing::GRACE_DAYS)]);
                    $out['past_due']++;
                } elseif ($end && !$sub->reminded_at && $end->lte(now()->addDays(self::REMIND_DAYS))) {
                    $sub->update(['reminded_at' => now()]);
                    $out['reminded']++;
                }
            });

        Subscription::query()->with('tenant')->where('status', 'past_due')->whereNotNull('grace_ends_at')
            ->where('grace_ends_at', '<', now())->get()
            ->each(function (Subscription $sub) use (&$out) {
                if ($sub->tenant && !$sub->tenant->isSuspended()) {
                    $sub->tenant->update(['status' => 'suspended', 'suspended_at' => now()]);
                    $out['suspended']++;
                }
            });

        Entitlements::flush();
        return $out;
    }
}
