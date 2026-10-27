<?php

namespace App\Services\Customers;

use App\Models\Customer;
use App\Models\Order;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The customers page tabs, the manual WhatsApp campaign and the Meta Ads export all pick guests by these groups of
 * the current restaurant (computed, nothing stored):
 *  new         first order in the last 30 days
 *  loyal       3 orders or more (counted visits, imported ones too)
 *  away        ordered before, nothing for 30 days or more
 *  top         the top 10% by spend
 *  imported    came from an imported list and never ordered online
 *  birthday    birthday this month (shown only when some guest has a birthday)
 * Each comes with what to do about it, in the restaurant's words.
 */
final class Audiences
{
    public const KEYS = ['new', 'loyal', 'away', 'top', 'imported', 'birthday'];

    /** key => [arabic label, english label, arabic action, english action, suggested message ar, suggested message en] */
    private const TEXT = [
        'new' => ['جداد', 'New', 'ابعتلهم شكر على أول طلب وكود خصم على الطلب التاني عشان يرجعوا.', 'Thank them for the first order and send a code for the second one.',
            'أهلاً {name} 👋 شكراً إنك جربت أكلنا! ده كود خصم ليك على طلبك الجاي: {code}. اطلب من هنا: {link}', 'Hi {name} 👋 thanks for trying us! Here is a code for your next order: {code}. Order here: {link}'],
        'loyal' => ['ثابتين', 'Regulars', 'دول أحسن ناسك: كافئهم بهدية أو خصم خاص من غير ما يطلبوا.', 'Your best people: reward them with a gift or a private discount.',
            'أهلاً {name} ❤️ إنت من أكتر الناس اللي بتطلب مننا، فده كود هدية ليك: {code}. {link}', 'Hi {name} ❤️ you are one of our regulars, so here is a gift code: {code}. {link}'],
        'away' => ['غايبين', 'Away', 'بقالهم شهر أو أكتر ما طلبوش: «وحشتنا» مع كود لمدة أسبوع.', 'No order for a month or more: a “we miss you” with a one-week code.',
            'وحشتنا يا {name}! 😊 ده كود خصم ليك لمدة أسبوع: {code}. المنيو هنا: {link}', 'We miss you {name}! 😊 Here is a code for this week: {code}. The menu: {link}'],
        'top' => ['الأعلى صرفاً', 'Top spenders', 'عاملهم VIP: جربهم في الصنف الجديد الأول، أو عرض مخصوص ليهم.', 'Treat them as VIPs: let them try new dishes first, or a private offer.',
            'أهلاً {name} ✨ عندنا حاجة جديدة وحبينا تبقى أول واحد يجربها. اطلب من هنا: {link}', 'Hi {name} ✨ we have something new and want you to try it first: {link}'],
        'imported' => ['اتنقلوا ولسه ما طلبوش أونلاين', 'Imported, never ordered online', 'عرّفهم إن محلك بقى أونلاين: لينك المنيو، ولينك حسابهم بعنوانهم المحفوظ.', 'Tell them you are online now: the menu link, and their account link with their saved address.',
            'أهلاً {name} 👋 بقينا أونلاين! تقدر تطلب من موبايلك وعنوانك محفوظ عندنا: {link}', 'Hi {name} 👋 we are online now! Order from your phone, your address is saved: {link}'],
        'birthday' => ['عيد ميلادهم الشهر ده', 'Birthday this month', 'هدية عيد الميلاد بتفرق: كود خصم أو حلو هدية مع الطلب.', 'A birthday gift goes a long way: a code or a free dessert with the order.',
            'كل سنة وإنت طيب يا {name} 🎂 ده كود هدية عيد ميلادك: {code}. {link}', 'Happy birthday {name} 🎂 here is your birthday gift code: {code}. {link}'],
    ];

    /** @var array<string, float|null> */
    private array $thresholds = [];

    public static function is(string $key): bool
    {
        return in_array($key, self::KEYS, true);
    }

    public static function label(string $key): string
    {
        return self::text($key, 0);
    }

    public static function action(string $key): string
    {
        return self::text($key, 2);
    }

    public static function message(string $key): string
    {
        return self::text($key, 4);
    }

    private static function text(string $key, int $at): string
    {
        $t = self::TEXT[$key] ?? null;

        return $t ? (app()->getLocale() === 'en' ? $t[$at + 1] : $t[$at]) : $key;
    }

    /**
     * Guests of one group, opted-out ones included (callers that message leave them out).
     *
     * @return Builder<Customer>
     */
    public function query(string $key): Builder
    {
        $q = Customer::query();
        $counted = fn ($o) => $o->where('status', '!=', 'cancelled');

        return match ($key) {
            'new' => $q->whereHas('orders', fn ($o) => $counted($o)->where('created_at', '>=', now()->subDays(30)))
                ->whereDoesntHave('orders', fn ($o) => $counted($o)->where('created_at', '<', now()->subDays(30))),
            'loyal' => $q->where('visits_count', '>=', 3),
            'away' => $q->whereNotNull('last_visit_at')->where('last_visit_at', '<', now()->subDays(30)),
            'top' => ($threshold = $this->topThreshold()) === null ? $q->whereRaw('1 = 0') : $q->where('total_spent', '>', 0)->where('total_spent', '>=', $threshold),
            'imported' => $q->where('source', 'import')->whereDoesntHave('orders', fn ($o) => $o->whereIn('source', Order::GUEST_SOURCES)),
            'birthday' => $q->whereNotNull('birthday')->where(DB::raw($this->month('birthday')), now()->format('m')),
            default => $q->whereRaw('1 = 0'),
        };
    }

    /**
     * Every group with its guests, what they spent, and what to do: the tabs of the customers page.
     *
     * @return list<array{key:string, label:string, action:string, count:int, revenue:float}>
     */
    public function summary(): array
    {
        $out = [];
        foreach (self::KEYS as $key) {
            if ($key === 'birthday' && ! Customer::query()->whereNotNull('birthday')->exists()) {
                continue;
            }
            $q = $this->query($key);
            $out[] = ['key' => $key, 'label' => self::label($key), 'action' => self::action($key),
                'count' => $q->clone()->count(), 'revenue' => round((float) $q->clone()->sum('total_spent'), 2)];
        }

        return $out;
    }

    /** Spend at which the top 10% begins, or null with no spenders. */
    public function topThreshold(): ?float
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

    /** SQL for the "MM" month of a date column on the current database. */
    private function month(string $column): string
    {
        return match (DB::connection()->getDriverName()) {
            'sqlite' => "strftime('%m', $column)",
            'pgsql' => "to_char($column, 'MM')",
            default => "DATE_FORMAT($column, '%m')",
        };
    }
}
