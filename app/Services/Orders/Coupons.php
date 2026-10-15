<?php

namespace App\Services\Orders;

use App\Models\Coupon;
use App\Models\Order;

/**
 * Discount codes on orders (sent as "coupon" in the order payload). Checked before the order is priced, counted
 * inside the order's transaction so max_uses can never be passed by two orders at once, given back on cancel.
 */
final class Coupons
{
    /**
     * Validates a code for an order of $amount (after any staff discount) and returns the discount, capped so the
     * order never goes below zero.
     *
     * @return array{0: Coupon, 1: float}
     *
     * @throws OrderException
     */
    public function check(string $code, float $amount, ?string $phone): array
    {
        $coupon = Coupon::query()->where('code', Coupon::normalize($code))->first();
        if (! $coupon || ! $coupon->is_active || ($coupon->expires_at && $coupon->expires_at->isPast())) {
            throw new OrderException(__('This coupon code is not valid.'));
        }
        if ($coupon->max_uses !== null && $coupon->uses >= $coupon->max_uses) {
            throw new OrderException(__('This coupon was already fully used.'));
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
        $off = $coupon->type === 'percent' ? $amount * min(100, $coupon->value) / 100 : $coupon->value;
        if ($coupon->type === 'percent' && $coupon->max_discount) {
            $off = min($off, $coupon->max_discount);
        }

        return [$coupon, round(max(0.0, min($amount, $off)), 2)];
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
