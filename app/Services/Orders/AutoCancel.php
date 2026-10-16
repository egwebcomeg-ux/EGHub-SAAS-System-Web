<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Services\Audit;
use App\Services\Settings;

/**
 * Cancels the online orders nobody accepted in time (setting online_auto_cancel_minutes, 0 = off). Only orders
 * guests placed themselves without a table (takeaway / delivery from the menu link); orders for later are left
 * to the restaurant. Goes through OrderService::transition, so wallet refunds, stock and loyalty are handled there.
 */
final class AutoCancel
{
    public const MAX_MINUTES = 180;

    public function __construct(private OrderService $orders) {}

    /** Runs for the current restaurant. @return int orders cancelled */
    public function run(): int
    {
        $minutes = min(self::MAX_MINUTES, max(0, (int) Settings::get('online_auto_cancel_minutes')));
        if ($minutes === 0) {
            return 0;
        }
        $due = Order::query()->where('status', 'new')
            ->whereIn('source', Order::GUEST_SOURCES)->whereIn('type', ['takeaway', 'delivery'])->whereNull('dining_table_id')
            ->whereNull('scheduled_for')->where('created_at', '<=', now()->subMinutes($minutes))
            ->orderBy('id')->limit(200)->get();

        $n = 0;
        foreach ($due as $order) {
            try {
                $this->orders->transition($order, 'cancelled', null, Order::REASON_NOT_ACCEPTED);
                Audit::record('order.auto_cancelled', '#'.$order->number, ['id' => $order->id, 'minutes' => $minutes]);
                $n++;
            } catch (OrderException) {
                // moved by staff in the meantime: nothing to do
            }
        }

        return $n;
    }
}
