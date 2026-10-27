<?php

namespace App\Support;

/**
 * The guest's delivery address typed in parts at the checkout (area, street, building, floor, apartment, landmark,
 * and a label to save it under), like the delivery apps. Everything that reads an order still reads the one line
 * delivery_address.text (tickets, the driver, WhatsApp, the call center), so the server writes it from the parts;
 * the parts themselves are kept beside it (delivery_address.parts, and on the guest's saved address).
 */
final class DeliveryAddress
{
    public const LABELS = ['home', 'work', 'other'];

    /** field => max length */
    private const FIELDS = ['area' => 80, 'street' => 120, 'building' => 30, 'floor' => 20, 'apartment' => 20, 'landmark' => 150];

    /**
     * Validation rules for the parts sent with an order (key prefix "address_parts").
     *
     * @return array<string, mixed>
     */
    public static function rules(string $prefix = 'address_parts'): array
    {
        $rules = [$prefix => 'nullable|array'];
        foreach (self::FIELDS as $field => $max) {
            $rules["{$prefix}.{$field}"] = "nullable|string|max:{$max}";
        }
        $rules["{$prefix}.label"] = 'nullable|string|in:'.implode(',', self::LABELS);

        return $rules;
    }

    /**
     * The parts cleaned (trimmed, one line each, empty ones left out).
     *
     * @param  array<string,mixed>|null  $parts
     * @return array{area?:string, street?:string, building?:string, floor?:string, apartment?:string, landmark?:string, label?:string}
     */
    public static function clean(?array $parts): array
    {
        $clean = [];
        foreach (self::FIELDS as $field => $max) {
            $value = mb_substr(trim((string) preg_replace('/\s+/u', ' ', (string) ($parts[$field] ?? ''))), 0, $max);
            if ($value !== '') {
                $clean[$field] = $value;
            }
        }
        if (in_array($parts['label'] ?? null, self::LABELS, true)) {
            $clean['label'] = $parts['label'];
        }

        return $clean;
    }

    /**
     * The one line everybody reads: "شارع البحر، عمارة 12، الدور 3، شقة 5، قسم أول طنطا — علامة مميزة: جنب الصيدلية".
     * A number gets its word ("12" → "عمارة 12"); a word typed by the guest stays as it is ("فيلا 4", "أرضي").
     *
     * @param  array<string,string>  $parts  cleaned
     */
    public static function compose(array $parts): string
    {
        $word = fn (string $key, string $value) => preg_match('/^\p{N}/u', $value) ? __($key, ['n' => $value]) : $value;
        $line = array_filter([
            $parts['street'] ?? null,
            isset($parts['building']) ? $word('Building :n', $parts['building']) : null,
            isset($parts['floor']) ? $word('Floor :n', $parts['floor']) : null,
            isset($parts['apartment']) ? $word('Apartment :n', $parts['apartment']) : null,
            $parts['area'] ?? null,
        ]);
        $text = implode(app()->getLocale() === 'ar' ? '، ' : ', ', $line);
        if (isset($parts['landmark'])) {
            $text .= ($text !== '' ? ' — ' : '').__('Landmark: :text', ['text' => $parts['landmark']]);
        }

        return $text;
    }

    /** The saved address's name for a label: "البيت", "الشغل", or nothing (the area shows instead). */
    public static function labelName(?string $label): string
    {
        return match ($label) {
            'home' => __('My home'),
            'work' => __('My work'),
            default => '',
        };
    }
}
