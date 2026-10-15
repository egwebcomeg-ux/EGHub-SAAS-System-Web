<?php

namespace App\Support;

use App\Services\Entitlements;
use App\Services\Settings;

/**
 * Extra languages a restaurant can add to its public menu on top of Arabic and English (feature extra_languages).
 * Arabic/English text lives in the rows' name / name_en columns; these languages in menu_translations.
 *
 * While a guest reads the menu in one of them, the app locale stays "en" (interface strings, and the English text
 * as the first fallback) and current() returns the extra language for the menu content and <html lang/dir>.
 */
final class MenuLocales
{
    /** code => [native name, english name, arabic name, direction] */
    public const ALL = [
        'fr' => ['Français', 'French', 'الفرنساوي', 'ltr'],
        'it' => ['Italiano', 'Italian', 'الإيطالي', 'ltr'],
        'de' => ['Deutsch', 'German', 'الألماني', 'ltr'],
        'ru' => ['Русский', 'Russian', 'الروسي', 'ltr'],
        'es' => ['Español', 'Spanish', 'الإسباني', 'ltr'],
        'tr' => ['Türkçe', 'Turkish', 'التركي', 'ltr'],
        'zh' => ['中文', 'Chinese', 'الصيني', 'ltr'],
    ];

    private const ATTRIBUTE = 'menu_locale';

    /** @return list<string> */
    public static function codes(): array
    {
        return array_keys(self::ALL);
    }

    /** Name of a language in itself ("Français"), also for ar / en. */
    public static function native(string $code): string
    {
        return match ($code) {
            'ar' => 'العربية',
            'en' => 'English',
            default => self::ALL[$code][0] ?? $code,
        };
    }

    /** Name of the language in the dashboard language ("الفرنساوي" / "French"). */
    public static function label(string $code): string
    {
        $l = self::ALL[$code] ?? [$code, $code, $code];

        return app()->getLocale() === 'en' ? $l[1] : $l[2];
    }

    public static function dir(string $code): string
    {
        return $code === 'ar' ? 'rtl' : (self::ALL[$code][3] ?? 'ltr');
    }

    /**
     * Extra languages the current restaurant turned on, in catalogue order; empty without the feature.
     *
     * @return list<string>
     */
    public static function enabled(): array
    {
        if (! tenant() || ! Entitlements::current()->allows('extra_languages')) {
            return [];
        }

        return self::parse(Settings::get('menu_locales'));
    }

    /** @return list<string> the known codes of a stored JSON list, in catalogue order */
    public static function parse(?string $json): array
    {
        $codes = json_decode((string) $json, true);

        return is_array($codes) ? array_values(array_intersect(self::codes(), $codes)) : [];
    }

    /**
     * Every language a guest can pick on this menu: ar, en (when shown) and the enabled extra ones.
     *
     * @return list<string>
     */
    public static function available(): array
    {
        return array_merge(['ar'], Settings::get('english_enabled') === '1' ? ['en'] : [], self::enabled());
    }

    /** The extra language the menu is shown in for this request, or null (Arabic / English). */
    public static function current(): ?string
    {
        $code = app()->bound('request') ? request()->attributes->get(self::ATTRIBUTE) : null;

        return is_string($code) ? $code : null;
    }

    /** Kept on the request, so it never outlives it (queue jobs and the dashboard never see it). */
    public static function use(?string $code): void
    {
        request()->attributes->set(self::ATTRIBUTE, $code);
    }

    /** Language of the page content: the extra language, else the app locale. */
    public static function pageLocale(): string
    {
        return self::current() ?? app()->getLocale();
    }

    public static function pageDir(): string
    {
        return self::dir(self::pageLocale());
    }
}
