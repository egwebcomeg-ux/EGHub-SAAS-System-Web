<?php

namespace App\Billing;

use App\Models\Plan;
use App\Models\Subscription;
use App\Notifications\MovedToFreePlan;
use App\Notifications\SubscriptionEndingSoon;
use App\Notifications\SubscriptionPastDue;
use App\Services\Entitlements;
use Illuminate\Support\Facades\Notification;

/**
 * Daily subscription housekeeping (egmenu:billing). No data is ever deleted and no menu goes offline:
 *   trial ended (unpaid)        → the free plan (menu stays online, ordering and paid features pause)
 *   paid period ended           → past_due with GRACE_DAYS of grace (everything still works)
 *   grace ended                 → the free plan
 *   3 days before the end       → reminder (dashboard banner + email to the owners)
 * Paying an invoice (Billing::markPaid) brings the paid plan back. Suspension is an admin action only.
 */
final class Lifecycle
{
    public const REMIND_DAYS = 3;

    public const FREE_PLAN = 'free';

    /** @return array{past_due:int, downgraded:int, reminded:int} */
    public function run(): array
    {
        $out = ['past_due' => 0, 'downgraded' => 0, 'reminded' => 0];

        Subscription::query()->with('tenant')->whereIn('status', ['trialing', 'active'])->get()
            ->each(function (Subscription $sub) use (&$out) {
                $end = $sub->endsAt();
                if ($end && $end->isPast()) {
                    if ($sub->status === 'trialing') {
                        $this->moveToFree($sub, 'trial');
                        $out['downgraded']++;
                    } else {
                        $sub->update(['status' => 'past_due', 'grace_ends_at' => now()->addDays(Billing::GRACE_DAYS)]);
                        Notification::send($sub->tenant?->owners() ?? [], new SubscriptionPastDue($sub));
                        $out['past_due']++;
                    }
                } elseif ($end && ! $sub->reminded_at && $end->lte(now()->addDays(self::REMIND_DAYS))) {
                    $sub->update(['reminded_at' => now()]);
                    Notification::send($sub->tenant?->owners() ?? [], new SubscriptionEndingSoon($sub));
                    $out['reminded']++;
                }
            });

        Subscription::query()->with('tenant')->where('status', 'past_due')->whereNotNull('grace_ends_at')
            ->where('grace_ends_at', '<', now())->get()
            ->each(function (Subscription $sub) use (&$out) {
                $this->moveToFree($sub, 'subscription');
                $out['downgraded']++;
            });

        Entitlements::flush();

        return $out;
    }

    /** The free plan has no end date, so nothing here fires for it again until the restaurant pays. */
    private function moveToFree(Subscription $sub, string $reason): void
    {
        $free = Plan::query()->where('code', self::FREE_PLAN)->first();
        if (! $free) {
            return; // plans not seeded yet: leave the subscription as it is rather than guess
        }
        $previous = $sub->plan;
        $sub->update([
            'plan_id' => $free->id, 'status' => 'active', 'cycle_months' => 1, 'trial_ends_at' => null,
            'current_period_start' => now(), 'current_period_end' => null, 'grace_ends_at' => null,
            'reminded_at' => null, 'period_value' => 0,
        ]);
        $sub->addons()->delete();
        if ($sub->tenant) {
            Notification::send($sub->tenant->owners(), new MovedToFreePlan($sub->tenant, $previous, $reason));
        }
    }
}
