<?php

namespace App\Services\Orders;

use App\Models\Branch;
use App\Models\CashMovement;
use App\Models\Order;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Cashier shifts (idea from Filetto): a cashier opens a shift with the cash in the drawer, records cash in/out
 * with a reason, and closes by counting the drawer. Expected cash = opening + cash payments + cash in − cash out
 * + wallet top-ups and gift cards paid in cash.
 */
final class ShiftService
{
    /** wallet = mobile wallet (Vodafone Cash...); credit = the guest's prepaid balance (WalletService). */
    public const METHODS = ['cash', 'card', 'instapay', 'wallet', 'credit'];

    public function current(User $user): ?Shift
    {
        return Shift::query()->where('user_id', $user->id)->whereNull('closed_at')->latest('id')->first();
    }

    public function open(User $user, Branch $branch, float $openingCash): Shift
    {
        if ($this->current($user)) {
            throw new OrderException(__('You already have an open shift.'));
        }

        return Shift::query()->create([
            'branch_id' => $branch->id, 'user_id' => $user->id, 'opening_cash' => round($openingCash, 2), 'opened_at' => now(),
        ]);
    }

    public function move(Shift $shift, string $type, float $amount, string $reason, User $by): CashMovement
    {
        if (! $shift->isOpen()) {
            throw new OrderException(__('This shift is closed.'));
        }

        return $shift->movements()->create(['type' => $type === 'out' ? 'out' : 'in', 'amount' => round($amount, 2), 'reason' => $reason, 'user_id' => $by->id]);
    }

    public function close(Shift $shift, float $counted, ?string $notes): Shift
    {
        if (! $shift->isOpen()) {
            throw new OrderException(__('This shift is closed.'));
        }

        return DB::transaction(function () use ($shift, $counted, $notes) {
            $shift->update([
                'expected_cash' => $this->summary($shift)['expected_cash'], 'closing_cash' => round($counted, 2),
                'notes' => $notes, 'closed_at' => now(),
            ]);

            return $shift;
        });
    }

    /**
     * prepaid = wallet top-ups and gift cards sold in the shift, by how the guest paid (not sales: the sale is the
     * order later paid by credit).
     *
     * @return array{orders:int, by_method:array<string,float>, total:float, cash_in:float, cash_out:float, prepaid:array<string,float>, prepaid_total:float, expected_cash:float, difference:?float}
     */
    public function summary(Shift $shift): array
    {
        $paid = Order::query()->where('shift_id', $shift->id)->where('payment_status', 'paid')->where('status', '!=', 'cancelled')->get(['payment_method', 'total']);
        $byMethod = array_fill_keys(self::METHODS, 0.0);
        foreach ($paid as $o) {
            $byMethod[$o->payment_method ?? 'cash'] = round(($byMethod[$o->payment_method ?? 'cash'] ?? 0) + $o->total, 2);
        }
        $in = (float) $shift->movements()->where('type', 'in')->sum('amount');
        $out = (float) $shift->movements()->where('type', 'out')->sum('amount');
        $prepaid = [];
        foreach ($shift->movements()->whereIn('type', ['topup', 'gift'])->get(['method', 'amount']) as $m) {
            $prepaid[$m->method ?? 'cash'] = round(($prepaid[$m->method ?? 'cash'] ?? 0) + $m->amount, 2);
        }
        $expected = round($shift->opening_cash + $byMethod['cash'] + $in - $out + ($prepaid['cash'] ?? 0), 2);

        return [
            'orders' => $paid->count(), 'by_method' => $byMethod, 'total' => round(array_sum($byMethod), 2),
            'cash_in' => $in, 'cash_out' => $out, 'prepaid' => $prepaid, 'prepaid_total' => round(array_sum($prepaid), 2), 'expected_cash' => $expected,
            'difference' => $shift->closing_cash !== null ? round($shift->closing_cash - ($shift->expected_cash ?? $expected), 2) : null,
        ];
    }
}
