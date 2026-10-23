<?php

namespace App\Support;

/**
 * The Google fonts a restaurant can give its guest pages. The Arabic families carry Arabic and Latin glyphs; the
 * Latin ones (Playfair Display, DM Serif Display, Poppins, Nunito) are for English menus and always fall back to
 * Cairo for Arabic text in the font stack. Each family lists only the weights Google serves for it, because the
 * css2 API rejects the whole request when one weight does not exist.
 */
final class MenuFonts
{
    /** family => weights asked from fonts.googleapis.com */
    public const WEIGHTS = [
        'Cairo' => '400;600;700;800',
        'Tajawal' => '400;500;700;800',
        'Almarai' => '400;700;800',
        'IBM Plex Sans Arabic' => '400;600;700',
        'Readex Pro' => '400;600;700',
        'El Messiri' => '400;600;700',
        'Amiri' => '400;700',
        'Reem Kufi' => '400;600;700',
        'Playfair Display' => '400;600;700;800',
        'DM Serif Display' => '400',
        'Poppins' => '400;600;700;800',
        'Nunito' => '400;600;700;800',
    ];

    public const NAMES = ['Cairo', 'Tajawal', 'Almarai', 'IBM Plex Sans Arabic', 'Readex Pro', 'El Messiri', 'Amiri', 'Reem Kufi',
        'Playfair Display', 'DM Serif Display', 'Poppins', 'Nunito'];

    /** The stylesheet of a family (null for Cairo, which every page already loads, or an unknown name). */
    public static function cssUrl(string $font): ?string
    {
        if ($font === 'Cairo' || ! isset(self::WEIGHTS[$font])) {
            return null;
        }

        return 'https://fonts.googleapis.com/css2?family='.str_replace(' ', '+', $font).':wght@'.self::WEIGHTS[$font].'&display=swap';
    }

    /**
     * The font for the heading style the AI saw on a printed menu. An Arabic-only menu keeps an Arabic family
     * (Latin display faces would only touch the prices); English menus get the Latin family that matches.
     *
     * @param  'serif'|'sans'|'rounded'|'script'|'display'|'arabic_naskh'|'arabic_kufi'  $style
     * @param  'ar'|'en'|'both'|null  $language
     */
    public static function forStyle(string $style, ?string $language): ?string
    {
        $arabicOnly = $language === 'ar';

        return match ($style) {
            'arabic_naskh' => 'Amiri',
            'arabic_kufi' => 'Reem Kufi',
            'serif', 'script' => $arabicOnly ? 'Amiri' : 'Playfair Display',
            'display' => $arabicOnly ? 'El Messiri' : 'DM Serif Display',
            'sans' => $arabicOnly ? 'Cairo' : 'Poppins',
            'rounded' => $arabicOnly ? 'Tajawal' : 'Nunito',
            default => null,
        };
    }
}
