<?php

namespace App\Billing;

use App\Models\Plan;
use App\Models\PlatformSetting;
use App\Models\Subscription;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Launch offer "the second branch free for the first year": a restaurant that subscribes to the Complete plan while
 * the offer runs gets its first extra branch at 0 EGP for 12 months from that subscription. The super admin switches
 * the offer on or off and may give it an end date (PlatformSetting); the date it ends is stored on each subscription
 * (subscriptions.branch_offer_until), so switching the offer off later keeps it for those who already got it.
 */
final class LaunchOffer
{
    public const ENABLED_KEY = 'launch_offer_branch_enabled';

    public const ENDS_KEY = 'launch_offer_branch_ends';

    /** The plan the offer is for. */
    public const PLAN = 'full';

    /** The add-on the offer makes free (one unit of it). */
    public const ADDON = 'branches';

    public const MONTHS = 12;

    /** On by default (no setting saved yet), until the super admin switches it off or its end date passes. */
    public static function isRunning(): bool
    {
        if (PlatformSetting::get(self::ENABLED_KEY, '1') !== '1') {
            return false;
        }
        $ends = trim((string) PlatformSetting::get(self::ENDS_KEY, ''));

        return $ends === '' || ! today()->greaterThan(Carbon::parse($ends));
    }

    /**
     * When the free extra branch ends for this subscription on this plan: the stored date, or, while the offer runs,
     * the date it would get if it subscribed to the Complete plan now. Null when it has no offer.
     */
    public static function until(?Subscription $sub, Plan $plan): ?CarbonInterface
    {
        if ($plan->code !== self::PLAN) {
            return null;
        }
        if ($sub?->branch_offer_until) {
            return $sub->branch_offer_until;
        }

        return self::isRunning() ? now()->addMonthsNoOverflow(self::MONTHS) : null;
    }

    /**
     * Whether the subscription is inside its offer window now: a Complete subscription whose stored offer date is
     * still ahead, or a Complete trial while the offer runs (it gets the date when it subscribes). Inside the window
     * the plan's branch limit counts the free branch even before it is claimed as an add-on (Entitlements).
     */
    public static function coversBranchNow(?Subscription $sub): bool
    {
        if (! $sub || $sub->plan?->code !== self::PLAN || $sub->status === 'canceled') {
            return false;
        }
        if ($sub->branch_offer_until) {
            return $sub->branch_offer_until->isFuture();
        }

        return $sub->status === 'trialing' && self::isRunning();
    }

    /** Called when a Complete subscription starts (paid invoice or set by EgWeb): the offer is kept from then on. */
    public static function grant(Subscription $sub, CarbonInterface $start): void
    {
        $sub->loadMissing('plan');
        if ($sub->plan?->code === self::PLAN && ! $sub->branch_offer_until && self::isRunning()) {
            $sub->update(['branch_offer_until' => $start->copy()->addMonthsNoOverflow(self::MONTHS)]);
        }
    }

    /** Whole months of a period starting at $from that the offer still covers (0 to $months). */
    public static function freeMonths(?CarbonInterface $until, CarbonInterface $from, int $months): int
    {
        if (! $until || ! $until->greaterThan($from)) {
            return 0;
        }

        return (int) min($months, max(0, round($from->diffInMonths($until))));
    }
}
