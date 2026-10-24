<?php

namespace App\Services\WhatsApp;

/**
 * The text of every WhatsApp message, Arabic (Egyptian) and English. The same wording must be registered as the
 * approved template on the provider, with {{1}}, {{2}}... in the order of PARAMS.
 */
final class Templates
{
    /** key => body parameters in template order */
    public const PARAMS = [
        'order_received' => ['name', 'restaurant', 'number', 'link'],
        'order_ready' => ['name', 'restaurant', 'number', 'link'],
        'order_on_way' => ['name', 'restaurant', 'number', 'link'],
        'reservation_confirmed' => ['name', 'restaurant', 'date', 'guests'],
        'loyalty_reward' => ['name', 'restaurant', 'reward', 'link'],
        'campaign' => ['text'],
        'automation' => ['text'],
        'order_staff' => ['text'],     // new-order alert to the branch's / restaurant's own WhatsApp number
        'driver_assigned' => ['text'], // "an order is with you" to the delivery driver's own number
        // a cash-on-delivery delivery order asks the guest to confirm it (App\Services\Orders\OrderConfirmation), with the
        // three quick-reply buttons of BUTTONS. Meta template (category Utility), body with {{1}}..{{6}} in this order:
        // ar: أهلاً {{1}} 🙏 طلبك رقم {{2}} من {{3}} وصلنا وبنجهّزه، ومحتاجين تأكيدك قبل ما نبعته:
        //     🛍 {{4}}
        //     💰 المبلغ عند الاستلام: {{5}}
        //     📍 {{6}}
        //     لو كله تمام دوس «تأكيد الطلب»، ولو حابب تعدّل دوس «تعديل الطلب»، ولو عايز تلغي دوس «إلغاء الطلب».
        // en: Hi {{1}} 🙏 {{3}} got your order #{{2}} and is preparing it. Please confirm it before it leaves:
        //     🛍 {{4}}
        //     💰 Cash on delivery: {{5}}
        //     📍 {{6}}
        //     All good? Tap “Confirm order”. Want a change? Tap “Edit order”, or “Cancel order”.
        // buttons (Quick reply, in this order): تأكيد الطلب · تعديل الطلب · إلغاء الطلب / Confirm order · Edit order · Cancel order
        'order_confirm' => ['name', 'number', 'restaurant', 'items', 'total', 'address'],
        // a free-form reply inside the 24-hour window the guest opened by writing or tapping (no template, no credit)
        'session_reply' => ['text'],
    ];

    /**
     * Quick-reply buttons per template, in the approved template's order: action => [ar, en]. Their payloads are
     * set per message (App\Services\Orders\OrderConfirmation::payload).
     */
    public const BUTTONS = [
        'order_confirm' => [
            'confirm' => ['تأكيد الطلب', 'Confirm order'],
            'edit' => ['تعديل الطلب', 'Edit order'],
            'cancel' => ['إلغاء الطلب', 'Cancel order'],
        ],
    ];

    /** Free-form texts answering the guest inside their 24-hour window: never a template, never a credit. */
    public const SESSION = ['session_reply'];

    private const TEXT = [
        'order_received' => [
            'ar' => 'أهلاً {name} 👋 {restaurant} استلم طلبك رقم {number} وبيجهزه دلوقتي. تابع حالة الطلب من هنا: {link}',
            'en' => 'Hi {name} 👋 {restaurant} received your order #{number} and is on it. Follow it here: {link}',
        ],
        'order_ready' => [
            'ar' => 'يا {name}، طلبك رقم {number} من {restaurant} جاهز ✅ {link}',
            'en' => 'Hi {name}, your order #{number} from {restaurant} is ready ✅ {link}',
        ],
        'order_on_way' => [
            'ar' => 'يا {name}، طلبك رقم {number} من {restaurant} خرج مع الطيار وفي الطريق ليك 🛵 {link}',
            'en' => 'Hi {name}, your order #{number} from {restaurant} is on its way 🛵 {link}',
        ],
        'reservation_confirmed' => [
            'ar' => 'أهلاً {name}، حجزك في {restaurant} اتأكد: {date} لـ {guests} أفراد. مستنيينك 🌟',
            'en' => 'Hi {name}, your table at {restaurant} is confirmed: {date} for {guests}. See you soon 🌟',
        ],
        'loyalty_reward' => [
            'ar' => 'مبروك يا {name} 🎁 كسبت «{reward}» في كارت ولاء {restaurant}. ورّي الكارت للكاشير: {link}',
            'en' => 'Congrats {name} 🎁 you earned "{reward}" on your {restaurant} loyalty card. Show your card at the counter: {link}',
        ],
        'campaign' => ['ar' => '{text}', 'en' => '{text}'],
        'automation' => ['ar' => '{text}', 'en' => '{text}'],
        'order_staff' => ['ar' => '{text}', 'en' => '{text}'],
        'driver_assigned' => ['ar' => '{text}', 'en' => '{text}'],
        'order_confirm' => [
            'ar' => "أهلاً {name} 🙏 طلبك رقم {number} من {restaurant} وصلنا وبنجهّزه، ومحتاجين تأكيدك قبل ما نبعته:\n🛍 {items}\n💰 المبلغ عند الاستلام: {total}\n📍 {address}\nلو كله تمام دوس «تأكيد الطلب»، ولو حابب تعدّل دوس «تعديل الطلب»، ولو عايز تلغي دوس «إلغاء الطلب».",
            'en' => "Hi {name} 🙏 {restaurant} got your order #{number} and is preparing it. Please confirm it before it leaves:\n🛍 {items}\n💰 Cash on delivery: {total}\n📍 {address}\nAll good? Tap “Confirm order”. Want a change? Tap “Edit order”, or “Cancel order”.",
        ],
        'session_reply' => ['ar' => '{text}', 'en' => '{text}'],
    ];

    /** The line that replaces the buttons for a provider that cannot show them: the confirmation page link. */
    private const LINK_LINE = [
        'ar' => 'أكّد أو عدّل أو الغِ من هنا: {link}',
        'en' => 'Confirm, change or cancel here: {link}',
    ];

    /**
     * The template's buttons in the guest's language with their payloads, in the approved template's order.
     *
     * @param  array<string,string>  $payloads  action => payload
     * @return list<array{id:string, title:string, payload:string}>
     */
    public static function buttons(string $key, array $payloads, string $locale = 'ar'): array
    {
        $out = [];
        foreach (self::BUTTONS[$key] ?? [] as $action => $titles) {
            if (isset($payloads[$action])) {
                $out[] = ['id' => $action, 'title' => $titles[$locale === 'en' ? 1 : 0], 'payload' => $payloads[$action]];
            }
        }

        return $out;
    }

    public static function linkLine(string $link, string $locale = 'ar'): string
    {
        return strtr(self::LINK_LINE[$locale === 'en' ? 'en' : 'ar'], ['{link}' => $link]);
    }

    /**
     * Keeps only the template's parameters, in its order (missing ones become "").
     *
     * @param  array<string,mixed>  $values
     * @return array<string,string>
     */
    public static function params(string $key, array $values): array
    {
        $out = [];
        foreach (self::PARAMS[$key] ?? array_keys($values) as $name) {
            $out[$name] = trim((string) ($values[$name] ?? ''));
        }

        return $out;
    }

    /** @param array<string,string> $params */
    public static function render(string $key, array $params, string $locale = 'ar'): string
    {
        $text = self::TEXT[$key][$locale === 'en' ? 'en' : 'ar'] ?? implode(' ', $params);
        $replace = [];
        foreach (self::withDefaults($params, $locale) as $name => $value) {
            $replace['{'.$name.'}'] = $value;
        }

        return trim(strtr($text, $replace));
    }

    /**
     * A guest without a name is greeted as "عميلنا" / "there".
     *
     * @param  array<string,string>  $params
     * @return array<string,string>
     */
    public static function withDefaults(array $params, string $locale = 'ar'): array
    {
        if (array_key_exists('name', $params) && $params['name'] === '') {
            $params['name'] = $locale === 'en' ? 'there' : 'عميلنا';
        }

        return $params;
    }
}
