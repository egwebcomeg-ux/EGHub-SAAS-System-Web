<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderRefund;
use App\Models\PosTerminal;
use App\Models\Shift;
use App\Models\User;
use App\Services\Audit;
use App\Services\Corporate\CorporateVouchers;
use App\Services\Entitlements;
use App\Services\Inventory\StockService;
use App\Services\Loyalty\LoyaltyService;
use App\Services\Wallet\WalletService;
use Illuminate\Support\Facades\DB;

/**
 * Refunds and returns of paid orders, approved by a manager (signed in with pos.refund, or their PIN on the
 * cashier's device). A full refund gives every part back the way it was paid (corporate vouchers get their value
 * back, the guest's balance its money, the counter part by the method chosen) and undoes what a cancellation undoes
 * (stock, loyalty stamps and cashback, the coupon use). A partial refund returns some lines (quantities): their share
 * of the order total (discount, service and tax included) by the method chosen, and their stock. Every refund leaves
 * the refunder's open shift, so the shift, the day's report and the Z report net it out. Audit-logged.
 */
final class RefundService
{
    /** Methods a refund can go back by at the counter (credit = the guest's prepaid balance). */
    public const METHODS = ['cash', 'card', 'instapay', 'wallet', 'credit'];

    public function __construct(private ManagerApproval $approval) {}

    /**
     * @param  array{full?:bool, lines?:list<array{order_item_id:int, qty:int}>, method?:?string, reason:string, approver_id?:?int, pin?:?string}  $data
     */
    public function refund(Order $order, User $by, ?Shift $shift, ?PosTerminal $terminal, array $data): OrderRefund
    {
        $reason = mb_substr(trim((string) ($data['reason'] ?? '')), 0, 200);
        if (mb_strlen($reason) < 3) {
            throw new OrderException(__('Write the reason of the refund.'));
        }
        if (! $shift && Entitlements::current()->allows('shifts')) {
            throw new OrderException(__('Open a shift first: the refund leaves its drawer.'));
        }
        $method = $data['method'] ?? null;
        if ($method !== null && ! in_array($method, self::METHODS, true)) {
            throw new OrderException(__('Choose how the money goes back.'));
        }
        $approver = $this->approval->approve($by, 'pos.refund', isset($data['approver_id']) ? (int) $data['approver_id'] : null, $data['pin'] ?? null, $order->reference());

        return DB::transaction(function () use ($order, $by, $shift, $terminal, $data, $reason, $method, $approver) {
            // locked: two refunds of the same order at once never give the money back twice
            $locked = Order::query()->lockForUpdate()->with('items')->findOrFail($order->id);
            if ($locked->payment_status !== 'paid' || $locked->status === 'cancelled') {
                throw new OrderException(__('Only a paid order can be refunded.'));
            }
            if ($locked->refund_status === 'full') {
                throw new OrderException(__('This order was already refunded in full.'));
            }
            $refund = ! empty($data['full'])
                ? $this->full($locked, $by, $method)
                : $this->partial($locked, $by, $method, (array) ($data['lines'] ?? []));

            $row = OrderRefund::query()->create($refund + [
                'order_id' => $locked->id, 'branch_id' => $locked->branch_id, 'shift_id' => $shift?->id, 'terminal_id' => $terminal?->id,
                'reason' => $reason, 'approved_by' => $approver->id, 'created_by' => $by->id,
            ]);
            $refunded = round($locked->refunded_amount + $row->amount, 2);
            // every line came back one by one (and no vouchers wait for a full refund): the order is refunded in full
            $everything = ! $row->is_full && $locked->corporate_amount <= 0.004 && $locked->items->every(fn (OrderItem $i) => $i->refunded_qty >= $i->quantity);
            if ($everything) {
                app(LoyaltyService::class)->orderReversed($locked);
                app(Coupons::class)->release($locked);
            }
            $locked->forceFill(['refunded_amount' => $refunded, 'refund_status' => $row->is_full || $everything ? 'full' : 'partial'])->save();
            $order->setRawAttributes($locked->getAttributes(), true);
            Audit::record('pos.refund', $locked->reference(), [
                'amount' => $row->amount, 'methods' => $row->methods, 'full' => $row->is_full, 'reason' => $reason,
                'approved_by' => $approver->name, 'cashier' => $by->name, 'lines' => $row->lines,
            ], $by->id);

            return $row;
        });
    }

    /** @return array<string,mixed> the refund row of a whole order (what is left of it) */
    private function full(Order $order, User $by, ?string $method): array
    {
        $left = round((float) $order->total - $order->refunded_amount, 2);
        $methods = [];
        $first = $order->refunded_amount <= 0.004;
        // the vouchers and the guest's balance get back what they paid
        if ($order->corporate_amount > 0.004 && app(CorporateVouchers::class)->refundOrder($order, $by) > 0) {
            $methods['corporate'] = round($order->corporate_amount, 2);
        }
        if ($order->payment_method === WalletService::METHOD && ($tx = app(WalletService::class)->refundOrder($order, $by))) {
            $methods['credit'] = round((float) $tx->delta, 2);
        }
        $rest = round($left - array_sum($methods), 2);
        if ($rest > 0.004) {
            if ($method === WalletService::METHOD) {
                if (! app(WalletService::class)->refundAmount($order, $rest, $by)) {
                    throw new OrderException(__('This order has no guest wallet to put the money back.'));
                }
                $methods['credit'] = round(($methods['credit'] ?? 0) + $rest, 2);
            } elseif ($method) {
                $methods[$method] = round(($methods[$method] ?? 0) + $rest, 2);
            } elseif ($first && is_array($order->payments) && $order->payments !== []) {
                foreach ($order->payments as $p) {
                    $methods[$p['method']] = round(($methods[$p['method']] ?? 0) + (float) $p['amount'], 2);
                }
            } else {
                $paidBy = in_array($order->payment_method, ShiftService::METHODS, true) && ! in_array($order->payment_method, ['credit', 'corporate'], true) ? $order->payment_method : 'cash';
                $methods[$paidBy] = round(($methods[$paidBy] ?? 0) + $rest, 2);
            }
        }
        // what a cancellation undoes: the stock, the stamps / cashback, the coupon use
        $restocked = (bool) $order->stock_deducted_at;
        app(StockService::class)->restoreForOrder($order);
        app(LoyaltyService::class)->orderReversed($order);
        app(Coupons::class)->release($order);
        $lines = [];
        foreach ($order->items as $item) {
            if ($item->refunded_qty < $item->quantity) {
                $lines[] = ['order_item_id' => $item->id, 'name' => $item->name, 'qty' => $item->quantity - $item->refunded_qty];
            }
            $item->forceFill(['refunded_qty' => $item->quantity])->save();
        }

        return [
            'is_full' => true, 'amount' => round(array_sum($methods), 2), 'methods' => $methods,
            'method' => count($methods) > 1 ? OrderService::SPLIT : (string) (array_key_first($methods) ?? 'cash'),
            'lines' => $lines, 'restocked' => $restocked,
        ];
    }

    /**
     * @param  list<array{order_item_id:int, qty:int}>  $wanted
     * @return array<string,mixed> the refund row of some lines
     */
    private function partial(Order $order, User $by, ?string $method, array $wanted): array
    {
        if (! $method) {
            throw new OrderException(__('Choose how the money goes back.'));
        }
        $returned = [];
        foreach ($wanted as $w) {
            $qty = (int) ($w['qty'] ?? 0);
            if ($qty > 0) {
                $returned[(int) ($w['order_item_id'] ?? 0)] = ($returned[(int) ($w['order_item_id'] ?? 0)] ?? 0) + $qty;
            }
        }
        if ($returned === []) {
            throw new OrderException(__('Choose what comes back.'));
        }
        // each line's share of what the guest paid: the order's discount, service and tax spread over its lines
        $factor = $order->subtotal > 0 ? (float) $order->total / (float) $order->subtotal : 0.0;
        $lines = [];
        $amount = 0.0;
        foreach ($returned as $id => $qty) {
            $item = $order->items->firstWhere('id', $id);
            if (! $item || $qty > $item->quantity - $item->refunded_qty) {
                throw new OrderException(__('More than what is left of :item.', ['item' => $item?->name ?? '#'.$id]));
            }
            $value = round($item->unit_price * $qty * $factor, 2);
            $lines[] = ['order_item_id' => $item->id, 'name' => $item->name, 'qty' => $qty, 'amount' => $value];
            $amount += $value;
            $item->forceFill(['refunded_qty' => $item->refunded_qty + $qty])->save();
        }
        $amount = round($amount, 2);
        // the vouchers' part goes back only with the whole order
        $refundable = round((float) $order->total - $order->refunded_amount - $order->corporate_amount, 2);
        if ($amount > $refundable + 0.004) {
            throw new OrderException(__('Only :amount can be refunded by line; refund the whole order to give the vouchers back.', ['amount' => money(max(0, $refundable))]));
        }
        if ($method === WalletService::METHOD && ! app(WalletService::class)->refundAmount($order, $amount, $by)) {
            throw new OrderException(__('This order has no guest wallet to put the money back.'));
        }
        $restocked = app(StockService::class)->restoreLines($order, $returned);

        return ['is_full' => false, 'amount' => $amount, 'methods' => [$method => $amount], 'method' => $method, 'lines' => $lines, 'restocked' => $restocked];
    }
}
