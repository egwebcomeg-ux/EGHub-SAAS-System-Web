<?php

namespace App\Support;

use App\Models\PlatformSetting;

/**
 * The company behind EgMenu as EgWeb enters it in admin › settings (legal name, commercial register, tax number,
 * address). Shown in the site footer and on the legal pages; nothing is shown for an empty field, and nothing is
 * ever filled in by default.
 */
final class LegalDetails
{
    public const KEYS = ['legal_entity', 'commercial_register', 'tax_id', 'legal_address'];

    /** @return list<array{key: string, label: string, value: string}> the filled fields, in display order */
    public static function lines(): array
    {
        $labels = [
            'legal_entity' => __('Company'),
            'commercial_register' => __('Commercial register'),
            'tax_id' => __('Tax number'),
            'legal_address' => __('Address'),
        ];
        $lines = [];
        foreach (self::KEYS as $key) {
            $value = trim((string) PlatformSetting::get($key, ''));
            if ($value !== '') {
                $lines[] = ['key' => $key, 'label' => $labels[$key], 'value' => $value];
            }
        }

        return $lines;
    }
}
