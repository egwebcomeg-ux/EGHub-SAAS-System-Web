<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\User;
use App\Services\Entitlements;
use App\Services\Settings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * How orders move with the screens a restaurant really uses. Only the order board and the kitchen display move an
 * order (and drivers a delivery), so a restaurant that sells at the counter with neither would keep every sale
 * "new" for ever: on the number board, the guest's page and the pager.
 *
 *  - order_flow (auto | counter | kitchen): a paid counter sale (POS, takeaway, no table) is done at once ("counter"),
 *    or goes through the kitchen like any order ("kitchen"). "auto" takes the kitchen way while the kitchen display
 *    was opened in the last KITCHEN_DAYS days or staff moved counter sales on in the last KITCHEN_DAYS days.
 *  - order_auto_serve_minutes (0 = off): a paid order left "ready" that long without a table or a driver is closed
 *    (served), so collected orders never pile up on the screens.
 */
final class OrderFlow
{
    public const MODES = ['auto', 'counter', 'kitchen'];

    public const KITCHEN_DAYS = 7;

    public const MAX_SERVE_MINUTES = 1440;

    public static function mode(): string
    {
        $mode = Settings::get('order_flow', 'auto');

        return in_array($mode, self::MODES, true) ? $mode : 'auto';
    }

    /** A paid counter sale still goes through the kitchen (someone moves it to ready). */
    public static function counterSalesGoToKitchen(): bool
    {
        return match (self::mode()) {
            'counter' => false,
            'kitchen' => true,
            default => self::kitchenInUse(),
        };
    }

    /** The kitchen display was opened, or staff moved counter sales on, in the last KITCHEN_DAYS days. */
    public static function kitchenInUse(): bool
    {
        return Cache::remember('order-flow:kitchen:'.tenant()->getTenantKey(), 600, function () {
            $since = now()->subDays(self::KITCHEN_DAYS);
            $seen = Settings::get('kitchen_seen_at');
            if ($seen !== '' && Entitlements::current()->allows('kitchen_display') && strtotime($seen) >= $since->getTimestamp()) {
                return true;
            }

            return Order::query()->where('source', 'pos')->whereNull('offline_ref')->where('preparing_at', '>=', $since)->exists();
        });
    }

    /** The kitchen display asked for its tickets: noted at most once an hour. */
    public static function kitchenSeen(): void
    {
        if (Cache::add('order-flow:kitchen-seen:'.tenant()->getTenantKey(), 1, 3600)) {
            Settings::put(['kitchen_seen_at' => now()->toIso8601String()]);
            Cache::forget('order-flow:kitchen:'.tenant()->getTenantKey());
        }
    }

    /**
     * A paid counter sale is done at once when the restaurant works without the kitchen: served straight away, no
     * "ready" news (the guest has it in hand). Called inside the payment's transaction.
     */
    public function finishCounterSale(Order $order, ?User $by = null): void
    {
        if ($order->source !== 'pos' || $order->dining_table_id || $order->type !== 'takeaway' || $order->payment_status !== 'paid'
            || ! in_array($order->status, ['new', 'preparing', 'ready'], true) || $order->scheduled_for || self::counterSalesGoToKitchen()) {
            return;
        }
        DB::transaction(function () use ($order, $by) {
            $from = Order::query()->lockForUpdate()->whereKey($order->id)->value('status');
            if (! in_array($from, ['new', 'preparing', 'ready'], true)) {
                return;
            }
            $order->items()->whereNull('ready_at')->update(['ready_at' => now()]);
            $order->forceFill(['status' => 'served', 'completed_at' => now()])->save();
            OrderStatusHistory::query()->create(['order_id' => $order->id, 'from_status' => $from, 'to_status' => 'served', 'user_id' => $by?->id]);
        });
    }

    /** Closes the paid orders left "ready" past order_auto_serve_minutes (no table, not a delivery). @return int closed */
    public function autoServe(OrderService $orders): int
    {
        $minutes = min(self::MAX_SERVE_MINUTES, max(0, (int) Settings::get('order_auto_serve_minutes', '60')));
        if ($minutes === 0) {
            return 0;
        }
        $due = Order::query()->where('status', 'ready')->where('payment_status', 'paid')->whereNull('dining_table_id')
            ->where('type', '!=', 'delivery')->where('ready_at', '<=', now()->subMinutes($minutes))->orderBy('id')->limit(200)->get();
        $n = 0;
        foreach ($due as $order) {
            try {
                $orders->transition($order, 'served');
                $n++;
            } catch (OrderException) {
                // moved by staff in the meantime
            }
        }

        return $n;
    }
}
