<?php

namespace App\Services\Customers;

use App\Models\Customer;
use App\Models\MembershipTier;
use App\Models\Order;
use App\Services\Loyalty\Membership;
use Illuminate\Database\Eloquent\Builder;

/**
 * Computed guest segments of the current restaurant (nothing is stored; each one is a query scope):
 *  new      first visit, in the last 30 days
 *  regular  3 or more orders in the last 90 days and a visit in the last 30 days
 *  vip      top 10% by spend, or the highest membership tier (feature membership)
 *  at_risk  was regular (3+ orders in 90 days) but no visit for 30 to 60 days
 *  lost     visited at least twice, nothing for more than 60 days
 */
final class Segments
{
    /** key => [arabic, english] */
    public const LABELS = [
        'new' => ['جديد', 'New'],
        'regular' => ['دايم', 'Regular'],
        'vip' => ['VIP', 'VIP'],
        'at_risk' => ['بيبعد', 'At risk'],
        'lost' => ['راح', 'Lost'],
    ];

    /** Which badge wins when a guest is in more than one segment. */
    public const PRIORITY = ['vip', 'at_risk', 'lost', 'regular', 'new'];

    /** @var array<string, float|null> VIP spend threshold per tenant, for one request */
    private array $thresholds = [];

    /** The top tier handed in by the CSV export (false = resolve it from the tenant). */
    private MembershipTier|null|false $topTierOverride = false;

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::LABELS);
    }

    public static function is(string $segment): bool
    {
        return array_key_exists($segment, self::LABELS);
    }

    public static function label(string $segment): string
    {
        $l = self::LABELS[$segment] ?? [$segment, $segment];

        return app()->getLocale() === 'en' ? $l[1] : $l[0];
    }

    /**
     * Narrows a guest query to one segment.
     *
     * @param  Builder<Customer>  $q
     * @return Builder<Customer>
     */
    public function apply(Builder $q, string $segment): Builder
    {
        return match ($segment) {
            'new' => $q->where('visits_count', 1)->where('last_visit_at', '>=', now()->subDays(30)),
            'regular' => $this->frequent($q)->where('last_visit_at', '>=', now()->subDays(30)),
            'at_risk' => $this->frequent($q)->whereBetween('last_visit_at', [now()->subDays(60), now()->subDays(30)]),
            'lost' => $q->where('visits_count', '>=', 2)->where('last_visit_at', '<', now()->subDays(60)),
            'vip' => $this->vip($q),
            default => $q,
        };
    }

    /** @return array<string, int> guests per segment */
    public function counts(): array
    {
        $counts = [];
        foreach (self::keys() as $key) {
            $counts[$key] = $this->apply(Customer::query(), $key)->count();
        }

        return $counts;
    }

    /**
     * The badge of one guest (the highest-priority segment they are in), or null. Uses `orders_90` when the
     * listing query loaded it (see withRecentOrders) so a page of guests costs no extra queries.
     */
    public function of(Customer $customer): ?string
    {
        $orders90 = $customer->getAttribute('orders_90');
        if ($orders90 === null) {
            $orders90 = $this->recentOrders($customer->orders()->getQuery())->count();
        }
        $visit = $customer->last_visit_at;
        $days = $visit ? $visit->diffInDays(now()) : null;
        $frequent = (int) $orders90 >= 3;
        $in = [
            'vip' => $this->isVip($customer),
            'at_risk' => $frequent && $days !== null && $days >= 30 && $days <= 60,
            'lost' => $customer->visits_count >= 2 && $days !== null && $days > 60,
            'regular' => $frequent && $days !== null && $days < 30,
            'new' => (int) $customer->visits_count === 1 && $days !== null && $days < 30,
        ];
        foreach (self::PRIORITY as $key) {
            if ($in[$key]) {
                return $key;
            }
        }

        return null;
    }

    /**
     * of() for the CSV export, which runs after the request when the tenant scope is gone: the VIP threshold was
     * memoised and the top tier resolved beforehand, and the guest's recent orders are counted with an explicit tenant.
     */
    public function ofExported(Customer $customer, ?MembershipTier $tier): ?string
    {
        $customer->setAttribute('orders_90', $this->recentOrders(
            Order::query()->withoutGlobalScopes()->where('tenant_id', $customer->tenant_id)->where('customer_id', $customer->id)
        )->count());
        $this->topTierOverride = $tier;
        try {
            return $this->of($customer);
        } finally {
            $this->topTierOverride = false;
        }
    }

    /**
     * Adds `orders_90` (counted orders in the last 90 days) to a listing query so of() needs no extra query per row.
     *
     * @param  Builder<Customer>  $q
     * @return Builder<Customer>
     */
    public function withRecentOrders(Builder $q): Builder
    {
        return $q->withCount(['orders as orders_90' => fn ($o) => $this->recentOrders($o)]);
    }

    /** Spend at which the top 10% begins (the 10th-percentile spender from the top), or null with no spenders. */
    public function vipThreshold(): ?float
    {
        $id = (string) tenant()?->getTenantKey();
        if (! array_key_exists($id, $this->thresholds)) {
            $spenders = Customer::query()->where('total_spent', '>', 0);
            $n = $spenders->clone()->count();
            $this->thresholds[$id] = $n === 0 ? null
                : (float) $spenders->clone()->orderByDesc('total_spent')->orderBy('id')->skip(max(1, (int) ceil($n / 10)) - 1)->value('total_spent');
        }

        return $this->thresholds[$id];
    }

    public function flush(): void
    {
        $this->thresholds = [];
    }

    /** The highest membership tier, when the restaurant has tiers and the feature. */
    private function topTier(): ?MembershipTier
    {
        if ($this->topTierOverride !== false) {
            return $this->topTierOverride;
        }

        return Membership::enabled() ? app(Membership::class)->tiers()->first() : null;
    }

    private function isVip(Customer $customer): bool
    {
        $threshold = $this->vipThreshold();
        if ($threshold !== null && $customer->total_spent > 0 && $customer->total_spent >= $threshold) {
            return true;
        }
        $tier = $this->topTier();

        return $tier !== null && $tier->qualifies($customer);
    }

    /** @param Builder<Customer> $q @return Builder<Customer> */
    private function vip(Builder $q): Builder
    {
        $threshold = $this->vipThreshold();
        $tier = $this->topTier();

        return $q->where(function (Builder $w) use ($threshold, $tier) {
            $w->whereRaw('1 = 0');
            if ($threshold !== null) {
                $w->orWhere(fn ($s) => $s->where('total_spent', '>', 0)->where('total_spent', '>=', $threshold));
            }
            if ($tier) {
                $w->orWhere(fn ($s) => $s->where('visits_count', '>=', $tier->min_visits)->where('total_spent', '>=', $tier->min_spent));
            }
        });
    }

    /** @param Builder<Customer> $q @return Builder<Customer> */
    private function frequent(Builder $q): Builder
    {
        return $q->whereHas('orders', fn ($o) => $this->recentOrders($o), '>=', 3);
    }

    /** Counted (not cancelled) orders of the last 90 days. */
    private function recentOrders($orders)
    {
        return $orders->where('status', '!=', 'cancelled')->where('created_at', '>=', now()->subDays(90));
    }
}
