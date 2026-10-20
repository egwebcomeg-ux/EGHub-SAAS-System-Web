<?php

namespace App\Services\Orders;

use App\Models\Branch;
use App\Models\CashMovement;
use App\Models\Order;
use App\Models\OrderRefund;
use App\Models\PosTerminal;
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
    /**
     * wallet = mobile wallet (Vodafone Cash...); credit = the guest's prepaid balance (WalletService); corporate = a
     * company's prepaid vouchers (CorporateVouchers): the part of an order they paid (orders.corporate_amount).
     */
    public const METHODS = ['cash', 'card', 'instapay', 'wallet', 'credit', 'corporate'];

    /**
     * How a paid order's total splits by method: the corporate vouchers' part, and the rest by the order's method.
     *
     * @return array<string,float>
     */
    public static function split(Order $order): array
    {
        $lines = is_array($order->payments) ? $order->payments : [];
        if ($lines !== []) {
            // paid line by line (split payment, offline sale): the vouchers' part, then each line by its method
            $out = [];
            if (($corporate = round((float) ($order->corporate_amount ?? 0), 2)) > 0.004) {
                $out['corporate'] = $corporate;
            }
            foreach ($lines as $line) {
                $key = (string) ($line['method'] ?? 'cash');
                $out[$key] = round(($out[$key] ?? 0) + (float) ($line['amount'] ?? 0), 2);
            }

            return array_filter($out, fn ($a) => $a > 0.004);
        }
        $method = $order->payment_method ?? 'cash';
        $corporate = round((float) ($order->corporate_amount ?? 0), 2);
        if ($corporate <= 0) {
            return [$method => (float) $order->total];
        }
        $rest = round((float) $order->total - $corporate, 2);

        return array_filter(['corporate' => $corporate, ($method === 'corporate' ? 'cash' : $method) => $rest], fn ($a) => $a > 0.004);
    }

    public function current(User $user): ?Shift
    {
        return Shift::query()->where('user_id', $user->id)->whereNull('closed_at')->latest('id')->first();
    }

    /** Opens the cashier's shift, on this device's terminal when it has one (one open shift per terminal). */
    public function open(User $user, Branch $branch, float $openingCash, ?PosTerminal $terminal = null): Shift
    {
        if ($this->current($user)) {
            throw new OrderException(__('You already have an open shift.'));
        }
        if ($terminal && $terminal->branch_id !== $branch->id) {
            throw new OrderException(__('This terminal belongs to another branch.'));
        }

        return DB::transaction(function () use ($user, $branch, $openingCash, $terminal) {
            if ($terminal) {
                PosTerminal::query()->lockForUpdate()->whereKey($terminal->id)->value('id');
                if ($open = $terminal->openShift()) {
                    throw new OrderException(__(':terminal already has an open shift (:name).', ['terminal' => $terminal->name, 'name' => $open->user?->name]));
                }
            }

            return Shift::query()->create([
                'branch_id' => $branch->id, 'user_id' => $user->id, 'terminal_id' => $terminal?->id,
                'opening_cash' => round($openingCash, 2), 'opened_at' => now(),
            ]);
        });
    }

    /** Cash put into (float, change) or taken out of (an expense, a pay-out) the drawer of an open shift. */
    public function move(Shift $shift, string $type, float $amount, string $reason, User $by, ?string $category = null): CashMovement
    {
        if (! $shift->isOpen()) {
            throw new OrderException(__('This shift is closed.'));
        }

        return $shift->movements()->create([
            'type' => $type === 'out' ? 'out' : 'in', 'category' => array_key_exists((string) $category, CashMovement::CATEGORIES) ? $category : null,
            'amount' => round($amount, 2), 'reason' => mb_substr($reason, 0, 120), 'user_id' => $by->id,
        ]);
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
     * by_method and total are net of the refunds given in the shift (refunds: by method).
     *
     * @return array{orders:int, by_method:array<string,float>, total:float, refunds:array<string,float>, refunds_total:float, cash_in:float, cash_out:float, expenses:float, prepaid:array<string,float>, prepaid_total:float, expected_cash:float, difference:?float}
     */
    public function summary(Shift $shift): array
    {
        $paid = Order::query()->where('shift_id', $shift->id)->where('payment_status', 'paid')->where('status', '!=', 'cancelled')->get(['payment_method', 'payments', 'total', 'corporate_amount']);
        $byMethod = array_fill_keys(self::METHODS, 0.0);
        foreach ($paid as $o) {
            foreach (self::split($o) as $method => $amount) {
                $byMethod[$method] = round(($byMethod[$method] ?? 0) + $amount, 2);
            }
        }
        // refunds given back during this shift (whatever shift sold the order): out of these methods, and the drawer
        $refunds = [];
        foreach (OrderRefund::query()->where('shift_id', $shift->id)->get(['methods']) as $refund) {
            foreach ((array) $refund->methods as $method => $amount) {
                $refunds[$method] = round(($refunds[$method] ?? 0) + (float) $amount, 2);
                $byMethod[$method] = round(($byMethod[$method] ?? 0) - (float) $amount, 2);
            }
        }
        $in = (float) $shift->movements()->where('type', 'in')->sum('amount');
        $out = (float) $shift->movements()->where('type', 'out')->sum('amount');
        $expenses = (float) $shift->movements()->where('type', 'out')->where('category', 'expense')->sum('amount');
        $prepaid = [];
        foreach ($shift->movements()->whereIn('type', ['topup', 'gift', 'package'])->get(['method', 'amount']) as $m) {
            $prepaid[$m->method ?? 'cash'] = round(($prepaid[$m->method ?? 'cash'] ?? 0) + $m->amount, 2);
        }
        $expected = round($shift->opening_cash + $byMethod['cash'] + $in - $out + ($prepaid['cash'] ?? 0), 2);

        return [
            'orders' => $paid->count(), 'by_method' => $byMethod, 'total' => round(array_sum($byMethod), 2),
            'refunds' => $refunds, 'refunds_total' => round(array_sum($refunds), 2),
            'cash_in' => $in, 'cash_out' => $out, 'expenses' => round($expenses, 2), 'prepaid' => $prepaid, 'prepaid_total' => round(array_sum($prepaid), 2), 'expected_cash' => $expected,
            'difference' => $shift->closing_cash !== null ? round($shift->closing_cash - ($shift->expected_cash ?? $expected), 2) : null,
        ];
    }
}
