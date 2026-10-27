<?php

namespace App\Services\Orders;

use App\Models\Coupon;
use App\Models\Customer;
use App\Models\MenuItem;
use App\Models\Order;
use App\Services\Marketing\WelcomeReward;

/**
 * Discount codes on orders (sent as "coupon" in the order payload). Checked before the order is priced, counted
 * inside the order's transaction so max_uses can never be passed by two orders at once, given back on cancel.
 */
final class Coupons
{
    /**
     * Validates a code for an order of $amount (after any staff discount) and returns the discount, capped so the
     * order never goes below zero. A voucher held by a guest only works with that guest's mobile; a voucher for
     * some categories or items takes its discount off those lines only ($lines: the priced order lines).
     *
     * @param  list<array{menu_item_id:int, line_total:float}>  $lines
     * @return array{0: Coupon, 1: float}
     *
     * @throws OrderException
     */
    public function check(string $code, float $amount, ?string $phone, array $lines = []): array
    {
        $coupon = Coupon::query()->where('code', Coupon::normalize($code))->first();
        if (! $coupon || ! $coupon->is_active || ($coupon->expires_at && $coupon->expires_at->isPast())) {
            throw new OrderException(__('This coupon code is not valid.'));
        }
        if ($coupon->starts_at && $coupon->starts_at->isFuture()) {
            throw new OrderException(__('This coupon starts on :date.', ['date' => local_time($coupon->starts_at, 'Y/m/d')]));
        }
        // the welcome gift (App\Services\Marketing\WelcomeReward): a mobile's first online order only
        if ($coupon->source === WelcomeReward::SOURCE && (! WelcomeReward::enabled() || ! WelcomeReward::firstOnlineOrder($phone))) {
            throw new OrderException($phone ? __('The welcome gift is for the first online order only.') : __('Enter your mobile number to use this coupon.'));
        }
        if ($coupon->max_uses !== null && $coupon->uses >= $coupon->max_uses) {
            throw new OrderException(__('This coupon was already fully used.'));
        }
        if ($coupon->customer_id) {
            if (! $phone) {
                throw new OrderException(__('Enter your mobile number to use this coupon.'));
            }
            $holder = Customer::query()->where('phone', $phone)->value('id');
            if ((int) $holder !== (int) $coupon->customer_id) {
                throw new OrderException(__('This voucher belongs to another guest.'));
            }
        }
        if ($coupon->min_order > 0 && $amount < $coupon->min_order) {
            throw new OrderException(__('This coupon needs an order of at least :amount.', ['amount' => money($coupon->min_order)]));
        }
        if ($coupon->once_per_customer) {
            if (! $phone) {
                throw new OrderException(__('Enter your mobile number to use this coupon.'));
            }
            if (Order::query()->where('coupon_id', $coupon->id)->where('customer_phone', $phone)->where('status', '!=', 'cancelled')->exists()) {
                throw new OrderException(__('You already used this coupon.'));
            }
        }
        $base = $coupon->hasQualifying() ? min($amount, $this->qualifyingTotal($coupon, $lines)) : $amount;
        if ($coupon->hasQualifying() && $base <= 0) {
            throw new OrderException(__('This coupon is for :items only: add one to your order.', ['items' => implode('، ', array_slice($coupon->qualifyingNames(), 0, 4))]));
        }
        $off = $coupon->type === 'percent' ? $base * min(100, $coupon->value) / 100 : $coupon->value;
        if ($coupon->type === 'percent' && $coupon->max_discount) {
            $off = min($off, $coupon->max_discount);
        }

        return [$coupon, round(max(0.0, min($base, $off)), 2)];
    }

    /**
     * What the order's lines of the coupon's categories / items add up to.
     *
     * @param  list<array{menu_item_id:int, line_total:float}>  $lines
     */
    private function qualifyingTotal(Coupon $coupon, array $lines): float
    {
        $categories = array_map('intval', $coupon->qualifying['categories'] ?? []);
        $items = array_map('intval', $coupon->qualifying['items'] ?? []);
        $ids = array_values(array_unique(array_map(fn ($l) => (int) $l['menu_item_id'], $lines)));
        $categoryOf = $ids === [] ? [] : MenuItem::query()->whereIn('id', $ids)->pluck('category_id', 'id')->all();
        $total = 0.0;
        foreach ($lines as $line) {
            $id = (int) $line['menu_item_id'];
            if (in_array($id, $items, true) || in_array((int) ($categoryOf[$id] ?? 0), $categories, true)) {
                $total += (float) $line['line_total'];
            }
        }

        return round($total, 2);
    }

    /** Counts one use; fails when the last use was just taken by another order. Call inside the order transaction. */
    public function redeem(Coupon $coupon): void
    {
        $taken = Coupon::query()->whereKey($coupon->id)
            ->where(fn ($q) => $q->whereNull('max_uses')->orWhereColumn('uses', '<', 'max_uses'))
            ->increment('uses');
        if ($taken !== 1) {
            throw new OrderException(__('This coupon was already fully used.'));
        }
    }

    /** A cancelled order gives its use back. */
    public function release(Order $order): void
    {
        if ($order->coupon_id) {
            Coupon::query()->whereKey($order->coupon_id)->where('uses', '>', 0)->decrement('uses');
        }
    }
}
