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
    ];

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
    ];

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
