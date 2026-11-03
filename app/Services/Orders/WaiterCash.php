<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\Shift;
use App\Models\User;
use App\Services\Audit;
use App\Services\Entitlements;
use App\Services\Settings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A waiter takes the money at the table. With the setting pos_waiter_pay_first a waiter's POS order is refused unless
 * it is paid as it is sent, so nothing reaches the kitchen unpaid (the cashier and the manager still open tables and
 * pay at the end). A waiter has no drawer: the order is paid at once (no double collection on the table's bill), and
 * the cash part is noted on the order (cash_collected) until a cashier with an open shift takes it from the waiter;
 * the order then joins that shift, so its drawer counts the cash. A card / InstaPay / wallet payment holds nothing
 * and goes into the branch's open shift, like a driver's at the door (DeliveryService). A waiter with an open shift
 * of their own pays into it like a cashier.
 */
final class WaiterCash
{
    public function __construct(private OrderService $orders, private ShiftService $shifts) {}

    /** The restaurant asks its waiters to take the money before the order goes to the kitchen. */
    public static function payFirst(): bool
    {
        return Settings::get('pos_waiter_pay_first') === '1';
    }

    /** $user is held to it: a waiter of a restaurant with the setting on. */
    public static function mustPayFirst(User $user): bool
    {
        return self::payFirst() && $user->hasRole('waiter');
    }

    /** $user's payments go through this class: a waiter with no open shift of their own, in a plan with shifts. */
    public function handles(User $user): bool
    {
        return $user->hasRole('waiter') && Entitlements::current()->allows('shifts') && ! $this->shifts->current($user);
    }

    /**
     * Pays $order taken by $waiter (OrderService::pay() arguments). The cash part stays with the waiter until handed in.
     *
     * @param  list<array{code?:?string, id?:?int, approval?:?string}>  $vouchers
     * @param  list<array{method:string, amount:float|int|string, tendered?:float|int|string|null}>  $parts
     */
    public function pay(Order $order, string $method, User $waiter, array $vouchers = [], ?string $voucherPhone = null, array $parts = []): Order
    {
        return DB::transaction(function () use ($order, $method, $waiter, $vouchers, $voucherPhone, $parts) {
            $this->orders->pay($order, $method, $waiter, null, $vouchers, $voucherPhone, $parts, withoutShift: true);
            $cash = round((float) (ShiftService::split($order)['cash'] ?? 0), 2);
            if ($cash > 0) {
                $order->forceFill(['cash_collected' => $cash])->save();
            } elseif ($shift = Shift::query()->whereNull('closed_at')->where('branch_id', $order->branch_id)->latest('id')->first()) {
                $order->forceFill(['shift_id' => $shift->id])->save();
            }

            return $order;
        });
    }

    /**
     * Orders whose cash a waiter took and did not hand in yet (driver orders keep theirs in DeliveryService).
     *
     * @return Builder<Order>
     */
    public static function unsettled(?int $branchId = null): Builder
    {
        return Order::query()->whereNull('driver_id')->where('source', 'pos')->where('cash_collected', '>', 0)->whereNull('cash_settled_at')
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId));
    }

    /**
     * The waiters holding cash in a branch, most first.
     *
     * @return Collection<int, array{waiter:User, orders:int, amount:float}>
     */
    public static function holders(int $branchId): Collection
    {
        $rows = self::unsettled($branchId)->whereNotNull('created_by')
            ->selectRaw('created_by, count(*) as orders, sum(cash_collected) as amount')->groupBy('created_by')->get();
        $users = User::query()->whereIn('id', $rows->pluck('created_by'))->get()->keyBy('id');

        return $rows->filter(fn ($r) => $users->has($r->created_by))
            ->map(fn ($r) => ['waiter' => $users[$r->created_by], 'orders' => (int) $r->orders, 'amount' => round((float) $r->amount, 2)])
            ->sortByDesc('amount')->values();
    }

    /**
     * $by took the waiter's cash into $shift: the orders join the shift (its drawer counts the cash). All or nothing.
     *
     * @return array{orders:int, amount:float}
     */
    public function settle(User $waiter, User $by, Shift $shift): array
    {
        if (! $shift->isOpen()) {
            throw new OrderException(__('This shift is closed.'));
        }

        return DB::transaction(function () use ($waiter, $by, $shift) {
            $orders = self::unsettled($shift->branch_id)->where('created_by', $waiter->id)->lockForUpdate()->get();
            if ($orders->isEmpty()) {
                throw new OrderException(__('This waiter has no cash to hand in.'));
            }
            foreach ($orders as $order) {
                $order->forceFill(['shift_id' => $order->shift_id ?? $shift->id, 'cash_settled_at' => now(), 'cash_settled_by' => $by->id])->save();
            }
            $amount = round((float) $orders->sum('cash_collected'), 2);
            Audit::record('waiter.settled', $waiter->name, ['waiter' => $waiter->id, 'orders' => $orders->count(), 'amount' => $amount, 'shift' => $shift->id]);

            return ['orders' => $orders->count(), 'amount' => $amount];
        });
    }
}
