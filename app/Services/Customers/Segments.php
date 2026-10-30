<?php

namespace App\Services\Customers;

use App\Models\Customer;
use App\Models\MembershipTier;
use App\Models\Order;
use App\Services\Loyalty\Membership;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The one way of grouping the current restaurant's guests (nothing is stored; each group is a query scope). The
 * customers page, its CSV export and the Meta Ads export, and every campaign (from the staff's own phone, SMS or EgHub
 * WhatsApp) pick guests by these groups:
 *  new       first visit, in the last 30 days
 *  regular   3 or more orders in the last 90 days and a visit in the last 30 days
 *  vip       top 10% by spend, or the highest membership tier (feature membership)
 *  at_risk   was regular (3+ orders in 90 days) but no visit for 30 to 60 days
 *  lost      visited at least twice, nothing for more than 60 days
 *  imported  came from an imported list and never ordered online
 *  birthday  birthday in the next 7 days (shown on the customers page only when some guest has a birthday)
 * Each comes with what to do about it and a suggested message, in the restaurant's words. The first five are also the
 * badge of a guest (of()).
 */
final class Segments
{
    /**
     * key => [arabic, english] of: the group's name, one guest's badge, the rule, what to do, a suggested message.
     *
     * @var array<string, array{label: array{string, string}, badge: array{string, string}, rule: array{string, string}, action: array{string, string}, message: array{string, string}}>
     */
    private const GROUPS = [
        'new' => [
            'label' => ['جداد', 'New'], 'badge' => ['جديد', 'New'],
            'rule' => ['أول زيارة في آخر 30 يوم', 'First visit in the last 30 days'],
            'action' => ['ابعتلهم شكر على أول طلب وكود خصم على الطلب التاني عشان يرجعوا.', 'Thank them for the first order and send a code for the second one.'],
            'message' => ['أهلاً {name} 👋 شكراً إنك جربت أكلنا! ده كود خصم ليك على طلبك الجاي: {coupon}. اطلب من هنا: {link}', 'Hi {name} 👋 thanks for trying us! Here is a code for your next order: {coupon}. Order here: {link}'],
        ],
        'regular' => [
            'label' => ['دايمين', 'Regulars'], 'badge' => ['دايم', 'Regular'],
            'rule' => ['3 طلبات أو أكتر في 90 يوم، وزيارة في آخر 30 يوم', '3 or more orders in 90 days, and a visit in the last 30 days'],
            'action' => ['دول أحسن ناسك: كافئهم بهدية أو خصم خاص من غير ما يطلبوا.', 'Your best people: reward them with a gift or a private discount.'],
            'message' => ['أهلاً {name} ❤️ إنت من أكتر الناس اللي بتطلب مننا، فده كود هدية ليك: {coupon}. {link}', 'Hi {name} ❤️ you are one of our regulars, so here is a gift code: {coupon}. {link}'],
        ],
        'vip' => [
            'label' => ['VIP', 'VIP'], 'badge' => ['VIP', 'VIP'],
            'rule' => ['أعلى 10% في الصرف، أو أعلى مستوى عضوية', 'Top 10% by spend, or the highest membership tier'],
            'action' => ['عاملهم VIP: جربهم في الصنف الجديد الأول، أو عرض مخصوص ليهم.', 'Treat them as VIPs: let them try new dishes first, or a private offer.'],
            'message' => ['أهلاً {name} ✨ عندنا حاجة جديدة وحبينا تبقى أول واحد يجربها. اطلب من هنا: {link}', 'Hi {name} ✨ we have something new and want you to try it first: {link}'],
        ],
        'at_risk' => [
            'label' => ['بيبعدوا', 'At risk'], 'badge' => ['بيبعد', 'At risk'],
            'rule' => ['عملاء دايمين مزاروش من 30 لـ 60 يوم', 'Regulars who have not visited for 30 to 60 days'],
            'action' => ['كانوا دايمين وبقالهم شهر ما جوش: «وحشتنا» مع كود لمدة أسبوع قبل ما يروحوا.', 'They were regulars and have been away a month: a “we miss you” with a one-week code before they go.'],
            'message' => ['وحشتنا يا {name}! 😊 ده كود خصم ليك لمدة أسبوع: {coupon}. المنيو هنا: {link}', 'We miss you {name}! 😊 Here is a code for this week: {coupon}. The menu: {link}'],
        ],
        'lost' => [
            'label' => ['راحوا', 'Lost'], 'badge' => ['راح', 'Lost'],
            'rule' => ['زاروا مرتين أو أكتر، ومزاروش من أكتر من 60 يوم', 'Visited twice or more, nothing for over 60 days'],
            'action' => ['بقالهم أكتر من شهرين: عرض قوي يرجعهم، وقولهم إيه الجديد عندك.', 'Gone for over two months: a strong offer to bring them back, and what is new at your place.'],
            'message' => ['وحشتنا يا {name}! 😊 عندنا حاجات جديدة، وده كود خصم ليك: {coupon}. المنيو هنا: {link}', 'We miss you {name}! 😊 We have new things, and here is a code for you: {coupon}. The menu: {link}'],
        ],
        'imported' => [
            'label' => ['اتنقلوا ولسه ما طلبوش أونلاين', 'Imported, never ordered online'], 'badge' => ['اتنقل', 'Imported'],
            'rule' => ['من قايمة عملاء اتنقلت، ولسه ما طلبوش أونلاين', 'From an imported list, no online order yet'],
            'action' => ['عرّفهم إن محلك بقى أونلاين: لينك المنيو، ولينك حسابهم بعنوانهم المحفوظ.', 'Tell them you are online now: the menu link, and their account link with their saved address.'],
            'message' => ['أهلاً {name} 👋 بقينا أونلاين! تقدر تطلب من موبايلك وعنوانك محفوظ عندنا: {link}', 'Hi {name} 👋 we are online now! Order from your phone, your address is saved: {link}'],
        ],
        'birthday' => [
            'label' => ['عيد ميلادهم الأسبوع ده', 'Birthday this week'], 'badge' => ['عيد ميلاده', 'Birthday'],
            'rule' => ['عيد ميلادهم النهارده أو في الـ 6 أيام الجايين', 'Birthday today or in the next 6 days'],
            'action' => ['هدية عيد الميلاد بتفرق: كود خصم أو حلو هدية مع الطلب.', 'A birthday gift goes a long way: a code or a free dessert with the order.'],
            'message' => ['كل سنة وإنت طيب يا {name} 🎂 ده كود هدية عيد ميلادك: {coupon}. {link}', 'Happy birthday {name} 🎂 here is your birthday gift code: {coupon}. {link}'],
        ],
    ];

    /**
     * Old names of the customers page's groups, still in old links: old key => the closest group. (Saved campaigns were
     * moved by a migration; "away" became the filter inactive 30 days there.)
     */
    public const RENAMED = ['loyal' => 'regular', 'top' => 'vip', 'away' => 'at_risk'];

    /** Which badge wins when a guest is in more than one segment. */
    public const PRIORITY = ['vip', 'at_risk', 'lost', 'regular', 'new'];

    /** @var array<string, float|null> VIP spend threshold per tenant, for one request */
    private array $thresholds = [];

    /** The top tier handed in by the CSV export (false = resolve it from the tenant). */
    private MembershipTier|null|false $topTierOverride = false;

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::GROUPS);
    }

    public static function is(string $segment): bool
    {
        return array_key_exists($segment, self::GROUPS);
    }

    /** A group key from a request, old names included; '' when it is none. */
    public static function fromRequest(mixed $segment): string
    {
        $segment = is_string($segment) ? (self::RENAMED[$segment] ?? $segment) : '';

        return self::is($segment) ? $segment : '';
    }

    public static function label(string $segment): string
    {
        return self::text($segment, 'label');
    }

    /** The word on one guest's row ("جديد" where the group is "جداد"). */
    public static function badge(string $segment): string
    {
        return self::text($segment, 'badge');
    }

    public static function rule(string $segment): string
    {
        return self::text($segment, 'rule');
    }

    public static function action(string $segment): string
    {
        return self::text($segment, 'action');
    }

    /** A message to start from, with {name}, {link} and {coupon}. */
    public static function message(string $segment): string
    {
        return self::text($segment, 'message');
    }

    private static function text(string $segment, string $part): string
    {
        $t = self::GROUPS[$segment][$part] ?? null;

        return $t ? (app()->getLocale() === 'en' ? $t[1] : $t[0]) : $segment;
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
            'imported' => $q->where('source', 'import')->whereDoesntHave('orders', fn ($o) => $o->whereIn('source', Order::GUEST_SOURCES)),
            'birthday' => $q->whereNotNull('birthday')->whereIn(DB::raw($this->monthDay('birthday')), $this->nextSevenDays()),
            default => $q,
        };
    }

    /**
     * Guests of one group, opted-out ones included (callers that message leave them out).
     *
     * @return Builder<Customer>
     */
    public function query(string $segment): Builder
    {
        return self::is($segment) ? $this->apply(Customer::query(), $segment) : Customer::query()->whereRaw('1 = 0');
    }

    /**
     * Every group with its guests, what they spent, and what to do: the cards of the customers page and the
     * "who gets it" of a new campaign.
     *
     * @return list<array{key:string, label:string, rule:string, action:string, count:int, revenue:float}>
     */
    public function summary(): array
    {
        $out = [];
        foreach (self::keys() as $key) {
            if ($key === 'birthday' && ! Customer::query()->whereNotNull('birthday')->exists()) {
                continue;
            }
            $q = $this->query($key);
            $out[] = ['key' => $key, 'label' => self::label($key), 'rule' => self::rule($key), 'action' => self::action($key),
                'count' => $q->clone()->count(), 'revenue' => round((float) $q->clone()->sum('total_spent'), 2)];
        }

        return $out;
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

    /** SQL for "MM-DD" of a date column on the current database. */
    private function monthDay(string $column): string
    {
        return match (DB::connection()->getDriverName()) {
            'sqlite' => "strftime('%m-%d', $column)",
            'pgsql' => "to_char($column, 'MM-DD')",
            default => "DATE_FORMAT($column, '%m-%d')",
        };
    }

    /** @return list<string> "MM-DD" of today and the next 6 days */
    private function nextSevenDays(): array
    {
        return array_map(fn (int $d) => local_at()->addDays($d)->format('m-d'), range(0, 6));
    }
}
