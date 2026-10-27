<?php

namespace App\Services\Marketing;

use App\Models\Coupon;
use App\Models\Customer;
use App\Models\LoyaltyTransaction;
use App\Models\Order;
use App\Services\Entitlements;
use App\Services\Loyalty\LoyaltyService;
use App\Services\Settings;

/**
 * «سجّل وخد …»: the restaurant's gift for a guest's first online order (settings welcome_reward_*), set on the loyalty
 * page: a % off, a fixed amount off (both through one hidden coupon of source "welcome", applied by the server with
 * no code typed and checked like any coupon: once per mobile, first online order only) or points on the loyalty card
 * when that first order counts (LoyaltyService::orderCompleted). The menu shows it as a small banner to phones that
 * do not know the guest yet.
 */
final class WelcomeReward
{
    public const TYPES = ['percent', 'amount', 'points'];

    public const SOURCE = 'welcome';

    public static function enabled(): bool
    {
        $s = Settings::all();
        if (($s['welcome_reward_enabled'] ?? '0') !== '1' || (float) ($s['welcome_reward_value'] ?? 0) <= 0
            || ! Entitlements::current()->allows('customers') || ! Entitlements::current()->allows('loyalty_stamps')) {
            return false;
        }

        return self::type() !== 'points' || app(LoyaltyService::class)->program()?->type === 'points';
    }

    public static function type(): string
    {
        $type = (string) Settings::get('welcome_reward_type');

        return in_array($type, self::TYPES, true) ? $type : 'percent';
    }

    public static function value(): float
    {
        return round((float) Settings::get('welcome_reward_value'), 2);
    }

    /** "10% off" / "50 EGP off" / "100 points", for the banner and the cart. */
    public static function label(): string
    {
        return match (self::type()) {
            'amount' => __(':value EGP off', ['value' => money(self::value())]),
            'points' => __(':value points', ['value' => (int) self::value()]),
            default => __(':value% off', ['value' => money(self::value())]),
        };
    }

    /** The hidden coupon behind a discount gift (null for points or when off). */
    public static function coupon(): ?Coupon
    {
        if (! self::enabled() || self::type() === 'points') {
            return null;
        }

        return Coupon::query()->where('source', self::SOURCE)->where('is_active', true)->first();
    }

    /** Keeps the hidden coupon in step with the settings (called when the owner saves them). */
    public static function sync(): void
    {
        $coupon = Coupon::query()->where('source', self::SOURCE)->first();
        $on = self::enabled() && self::type() !== 'points';
        if (! $coupon && ! $on) {
            return;
        }
        $attributes = ['type' => self::type() === 'amount' ? 'amount' : 'percent', 'value' => self::value(), 'is_active' => $on,
            'once_per_customer' => true, 'note' => 'هدية التسجيل'];
        $coupon ? $coupon->update($attributes) : Coupon::query()->create($attributes + ['code' => Coupon::generateCode('WELCOME'), 'source' => self::SOURCE]);
    }

    /** Whether this mobile has not had an online order here yet (cancelled ones do not count). */
    public static function firstOnlineOrder(?string $phone): bool
    {
        return $phone !== null && ! Order::query()->where('customer_phone', $phone)->where('source', 'online')->where('status', '!=', 'cancelled')->exists();
    }

    /** The points gift, when the guest's first counted online order is $order. Called inside the loyalty transaction. */
    public static function pointsFor(Order $order, Customer $customer): int
    {
        if ($order->source !== 'online' || ! self::enabled() || self::type() !== 'points') {
            return 0;
        }
        $earlier = Order::query()->where('customer_id', $customer->id)->where('source', 'online')->whereNotNull('loyalty_applied_at')
            ->whereKeyNot($order->id)->exists();
        $given = LoyaltyTransaction::query()->where('note', self::SOURCE)->whereHas('card', fn ($c) => $c->where('customer_id', $customer->id))->exists();

        return $earlier || $given ? 0 : (int) self::value();
    }
}
