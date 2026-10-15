<?php

namespace App\Services\Loyalty;

use App\Models\Customer;
use App\Models\MembershipTier;
use App\Services\Entitlements;
use Illuminate\Support\Collection;

/**
 * Membership tiers (feature `membership`): the guest's level follows their visits and spend, and its discount is
 * taken off every order placed with their mobile number — before the coupon, service and tax.
 */
final class Membership
{
    public static function enabled(): bool
    {
        return Entitlements::current()->allows('membership');
    }

    /** @var array<string, Collection<int, MembershipTier>> per tenant, for one request */
    private array $memo = [];

    /** @return Collection<int, MembershipTier> highest first */
    public function tiers(): Collection
    {
        $id = (string) tenant()?->getTenantKey();

        return $this->memo[$id] ??= MembershipTier::query()->orderByDesc('min_spent')->orderByDesc('min_visits')->orderByDesc('sort_order')->get();
    }

    public function flush(): void
    {
        $this->memo = [];
    }

    /** The highest tier the guest reached, or null (no tiers, or still below the first one). */
    public function tierFor(Customer $customer): ?MembershipTier
    {
        if (! self::enabled()) {
            return null;
        }

        return $this->tiers()->first(fn (MembershipTier $t) => $t->qualifies($customer));
    }

    /** The tier the guest is working towards and how far they are, for the card page. */
    public function nextFor(Customer $customer): ?MembershipTier
    {
        if (! self::enabled()) {
            return null;
        }

        return $this->tiers()->reverse()->first(fn (MembershipTier $t) => ! $t->qualifies($customer));
    }

    /**
     * Discount for an order of $amount by the guest with this mobile number.
     *
     * @return array{0: ?MembershipTier, 1: float}
     */
    public function discountFor(?string $phone, float $amount): array
    {
        if (! $phone || $amount <= 0 || ! self::enabled()) {
            return [null, 0.0];
        }
        $customer = Customer::query()->where('phone', $phone)->first();
        $tier = $customer ? $this->tierFor($customer) : null;
        if (! $tier || $tier->discount_percent <= 0) {
            return [$tier, 0.0];
        }

        return [$tier, round($amount * min(100, $tier->discount_percent) / 100, 2)];
    }
}
