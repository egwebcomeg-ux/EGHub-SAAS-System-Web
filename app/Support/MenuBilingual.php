<?php

namespace App\Support;

use App\Services\Settings;
use Illuminate\Database\Eloquent\Model;

/**
 * The bilingual guest menu (setting menu_bilingual): under each name in the language the guest reads, the other base
 * language in a smaller, muted line, like many printed Egyptian menus («برجر لحم» with "Beef burger" under it).
 * Reading Arabic, the second line is English; reading English (or an extra language, App\Support\MenuLocales), Arabic.
 * Only on the guest pages: the POS, the kitchen and the receipts keep one name.
 */
final class MenuBilingual
{
    public static function enabled(): bool
    {
        return tenant() !== null && Settings::get('menu_bilingual') === '1';
    }

    /** The language of the second line: English under an Arabic page, Arabic under everything else. */
    public static function lang(): string
    {
        return app()->getLocale() === 'ar' && MenuLocales::current() === null ? 'en' : 'ar';
    }

    public static function dir(): string
    {
        return self::lang() === 'ar' ? 'rtl' : 'ltr';
    }

    /**
     * The second-line text of a menu row (item, category, option group, option); null when the setting is off, the
     * other language is empty or it says the same as the shown name.
     */
    public static function text(?Model $model, string $field = 'name'): ?string
    {
        if (! $model || ! self::enabled()) {
            return null;
        }
        $other = trim((string) $model->getAttribute(self::lang() === 'en' ? $field.'_en' : $field));
        $shown = trim(tx($model, $field));

        return $other === '' || mb_strtolower($other) === mb_strtolower($shown) ? null : $other;
    }
}
