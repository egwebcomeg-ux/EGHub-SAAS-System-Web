<?php

namespace App\Support;

/**
 * Turns the free-text working hours of a branch ("9 AM – 1 AM", "٩ ص - ٢ م", "08:00 - 23:30") into schema.org
 * openingHours ("Mo-Su 09:00-01:00"). Text it cannot read with certainty gives null, so nothing wrong is published.
 */
final class OpeningHours
{
    public static function toSchema(?string $text): ?string
    {
        $text = strtr(mb_strtolower(trim((string) $text)), [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
        if ($text === '') {
            return null;
        }
        if (preg_match('/24\s*(?:\/|ساعة|hours?)/u', $text)) {
            return 'Mo-Su 00:00-23:59';
        }
        $time = '(\d{1,2})(?::(\d{2}))?\s*(am|pm|a\.m\.|p\.m\.|ص|م|صباحا|صباحاً|مساء|مساءً)?';
        if (! preg_match('/'.$time.'\s*(?:-|–|—|to|الى|إلى|لـ|ل)\s*'.$time.'/u', $text, $m)) {
            return null;
        }
        $from = self::clock((int) $m[1], ($m[2] ?? '') !== '' ? (int) $m[2] : null, $m[3] ?? '');
        $to = self::clock((int) $m[4], ($m[5] ?? '') !== '' ? (int) $m[5] : null, $m[6] ?? '');

        return $from && $to ? "Mo-Su $from-$to" : null;
    }

    /** 9, null, "pm" → "21:00"; without am/pm only a 24-hour time (with minutes, or 13-23) is accepted. */
    private static function clock(int $hour, ?int $minute, string $marker): ?string
    {
        if ($minute !== null && $minute > 59) {
            return null;
        }
        $marker = trim($marker);
        if ($marker === '') {
            if ($hour > 23 || ($minute === null && $hour <= 12)) {
                return null;
            }
        } else {
            if ($hour < 1 || $hour > 12) {
                return null;
            }
            $pm = in_array($marker, ['pm', 'p.m.', 'م', 'مساء', 'مساءً'], true);
            $hour = $hour % 12 + ($pm ? 12 : 0);
        }

        return sprintf('%02d:%02d', $hour, $minute ?? 0);
    }
}
