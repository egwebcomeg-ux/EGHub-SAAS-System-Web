<?php

namespace App\Support;

/**
 * The guest menu header next to the logo (setting header_show_name): most logos already carry the place's name,
 * so "auto" shows the name and tagline only when there is no logo; "always" and "never" are the owner's choice.
 */
final class MenuHeader
{
    public const CHOICES = ['auto', 'always', 'never'];

    /** @param  array<string,string>  $settings */
    public static function showsName(array $settings): bool
    {
        return match ($settings['header_show_name'] ?? 'auto') {
            'always' => true,
            'never' => false,
            default => trim((string) ($settings['logo'] ?? '')) === '',
        };
    }
}
