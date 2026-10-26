<?php

namespace App\Services\Crm;

use App\Models\CallScriptItem;
use App\Models\Plan;
use App\Models\PlatformAdmin;
use App\Support\SiteServices;
use Illuminate\Support\Collection;

/**
 * The offer to make on a call, from the answers given (each answer option carries a signal, CallScriptItem::SIGNALS),
 * the kind of place and what it did so far (App\Services\Crm\LeadSignals). Simple, readable rules, so the caller can
 * see why: which plan, which of its features to talk about (only features that plan really has), whether a discount
 * is worth offering (never above the caller's own limit) and the next step.
 */
final class OfferAdvisor
{
    /** What to talk about for each signal, in order of importance. */
    private const FEATURES_BY_SIGNAL = [
        'wants_table_orders' => ['qr_ordering', 'order_board', 'pager'],
        'wants_delivery' => ['online_ordering', 'delivery_zones', 'whatsapp_notifications'],
        'wants_loyalty' => ['loyalty_stamps', 'loyalty_points', 'campaigns'],
        'wants_reservations' => ['reservations'],
        'has_system' => ['online_ordering', 'qr_ordering'],
        'menu_only' => ['full_branding', 'menu_themes', 'printed_menu'],
        'chain' => ['pos', 'kitchen_display', 'inventory', 'call_center'],
    ];

    /** When no answer says what they want: what fits the kind of place. */
    private const FEATURES_BY_TYPE = [
        'cafe' => ['qr_ordering', 'loyalty_stamps', 'menu_themes'],
        'restaurant' => ['online_ordering', 'delivery_zones', 'reservations'],
        'both' => ['qr_ordering', 'online_ordering', 'loyalty_stamps'],
        'pastry' => ['scheduled_orders', 'online_ordering', 'printed_menu'],
        'shop' => ['online_ordering', 'whatsapp_notifications', 'delivery_zones'],
    ];

    /** A discount suggested to a price-sensitive lead, before the caller's own limit: more for one that already uses it. */
    private const SUGGESTED_PERCENT = ['engaged' => 15, 'cold' => 10];

    /**
     * @param  array<string, string>  $answers  question key => option key
     * @param  array{type: string, engaged: bool}  $signals  from LeadSignals::read()
     * @param  Collection<int, CallScriptItem>  $questions
     * @return array{plan: ?Plan, signals: list<string>, why: list<string>, features: list<array{key: string, label: string}>,
     *     discount: ?array{percent: int, why: string}, next: list<string>}
     */
    public function advise(array $answers, array $signals, Collection $questions, PlatformAdmin $caller): array
    {
        $picked = $this->signalsOf($answers, $questions);
        $plans = Plan::query()->whereIn('code', ['free', 'full', 'enterprise'])->with('features')->get()->keyBy('code');
        $why = [];
        $next = [];

        if (in_array('not_interested', $picked, true)) {
            return ['plan' => null, 'signals' => $picked, 'why' => [__('They said they are not interested.')], 'features' => [], 'discount' => null,
                'next' => [__('Thank them, mark the lead "Not interested" with the reason, and leave the free menu running.')]];
        }

        if (in_array('chain', $picked, true)) {
            $code = 'enterprise';
            $why[] = __('Several branches or a POS: the Enterprise plan, priced with sales.');
            $next[] = __('Write the number of branches and what they need in the notes, then pass it to sales for a price.');
        } elseif (in_array('menu_only', $picked, true) && array_intersect($picked, ['wants_table_orders', 'wants_delivery', 'wants_loyalty', 'wants_reservations']) === []) {
            $code = 'free';
            $why[] = __('They only want a QR menu: the Free plan covers it, honestly.');
            $why[] = __('Mention the Complete plan only for their own logo, colors and menu designs.');
        } else {
            $code = 'full';
            $why[] = $picked === [] ? __('No answers yet: the Complete plan they are trying, with what fits their kind of place.') : __('What they want is in the Complete plan.');
        }
        $plan = $plans->get($code);

        $keys = [];
        foreach ($picked as $signal) {
            array_push($keys, ...(self::FEATURES_BY_SIGNAL[$signal] ?? []));
        }
        if ($keys === [] || $code === 'free') {
            array_push($keys, ...(self::FEATURES_BY_TYPE[$signals['type']] ?? self::FEATURES_BY_TYPE['cafe']));
        }
        // only features the recommended plan really has (Complete for the Free plan's "upgrade for" list)
        $rights = ($code === 'free' ? $plans->get('full') : $plan)?->entitlements()['features'] ?? [];
        $features = collect($keys)->unique()->filter(fn (string $k) => ! empty($rights[$k]))->take(4)
            ->map(fn (string $k) => ['key' => $k, 'label' => Plan::label($k)])->values()->all();

        if (in_array('needs_menu_help', $picked, true) && ($service = SiteServices::find('menu_setup'))) {
            $next[] = __('Offer our menu entry service (:price EGP), or the Excel import from the dashboard.', ['price' => money($service['price'])]);
        }
        if (in_array('just_trying', $picked, true)) {
            $next[] = __('They were only trying: show one thing that fits their place, no pressure, and set a follow-up before the trial ends.');
        }
        if (in_array('ready_to_pay', $picked, true)) {
            $next[] = __('Ready to subscribe: walk them to Subscription in their dashboard now. No discount needed.');
        }

        $discount = null;
        $limit = $caller->discountLimit();
        if ($code === 'full' && in_array('price_sensitive', $picked, true) && ! in_array('ready_to_pay', $picked, true) && $limit > 0) {
            $percent = min($limit, self::SUGGESTED_PERCENT[$signals['engaged'] ? 'engaged' : 'cold']);
            $discount = ['percent' => $percent, 'why' => $signals['engaged']
                ? __('Price is the only thing in the way and they already use it: offer :pct% off the first invoice if they subscribe this week.', ['pct' => $percent])
                : __('Price matters and they have not used it much yet: offer :pct% off the first invoice, valid one week.', ['pct' => $percent])];
        }
        if ($next === []) {
            $next[] = $code === 'free'
                ? __('Help them finish the menu and print the QR cards; the Free plan stays theirs.')
                : __('Show the features above on their own menu link, then ask monthly or yearly.');
        }

        return ['plan' => $plan, 'signals' => $picked, 'why' => $why, 'features' => $features, 'discount' => $discount, 'next' => $next];
    }

    /**
     * The signals of the answers given (answers to options that no longer exist are skipped).
     *
     * @param  array<string, string>  $answers
     * @param  Collection<int, CallScriptItem>  $questions
     * @return list<string>
     */
    public function signalsOf(array $answers, Collection $questions): array
    {
        $out = [];
        foreach ($questions as $question) {
            $chosen = $answers[$question->key] ?? null;
            foreach ($question->answerOptions() as $option) {
                if ($option['key'] === $chosen && $option['signal'] !== '') {
                    $out[] = $option['signal'];
                }
            }
        }

        return array_values(array_unique($out));
    }
}
