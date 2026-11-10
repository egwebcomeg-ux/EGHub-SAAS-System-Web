<?php

namespace App\Services\Orders;

use App\Models\Branch;
use App\Models\Order;
use App\Models\Shift;
use App\Services\Entitlements;
use Carbon\CarbonInterface;

/**
 * The number an order is called by ("#14"): it starts again at 1 with every shift of the branch, so the numbers the
 * kitchen, the pager and the guest hear stay short. A shift opened while another one of the branch is still open
 * carries on the same count (the cashiers hand over); the count starts again only when a shift opens with none open.
 * A restaurant without shifts starts again every day (Cairo time).
 *
 * The number is no longer unique: the order's id is. Anything that looks an order up by its number also narrows it
 * (branch, date, the guest's phone) or takes the most recent one.
 */
final class OrderNumbers
{
    /** How many past shifts are looked through to find where the current count started. */
    private const SHIFTS_LOOKED_AT = 50;

    /** The next number in the branch. Call it inside the transaction that creates the order: it locks the branch row. */
    public static function next(Branch $branch): int
    {
        Branch::query()->lockForUpdate()->whereKey($branch->id)->value('id');

        return (int) Order::query()->where('branch_id', $branch->id)->where('created_at', '>=', self::countStartedAt($branch))->max('number') + 1;
    }

    /** When the branch's current count started: the last shift opened with no other shift of the branch open, or today. */
    public static function countStartedAt(Branch $branch): CarbonInterface
    {
        $today = local_day_start();
        if (! Entitlements::current()->allows('shifts')) {
            return $today;
        }
        $shifts = Shift::query()->where('branch_id', $branch->id)->latest('opened_at')->limit(self::SHIFTS_LOOKED_AT)->get(['id', 'opened_at', 'closed_at']);
        foreach ($shifts as $shift) {
            $overlapped = $shifts->contains(fn (Shift $other) => $other->id !== $shift->id && $other->opened_at->lt($shift->opened_at)
                && ($other->closed_at === null || $other->closed_at->gt($shift->opened_at)));
            if (! $overlapped) {
                return $shift->opened_at;
            }
        }

        return $shifts->isEmpty() ? $today : $shifts->last()->opened_at;
    }
}
