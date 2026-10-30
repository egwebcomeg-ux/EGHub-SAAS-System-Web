<?php

namespace App\Support;

use Carbon\CarbonInterface;

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

    /**
     * The daily window as ["20:00", "03:00"] (close may be earlier than open: the place closes after midnight),
     * or null when the text cannot be read. "24 hours" gives ["00:00", "23:59"].
     *
     * @return array{0:string, 1:string}|null
     */
    public static function window(?string $text): ?array
    {
        return preg_match('/(\d{2}:\d{2})-(\d{2}:\d{2})$/', (string) self::toSchema($text), $m) ? [$m[1], $m[2]] : null;
    }

    /**
     * Open at $at (read on the restaurant's clock)? An overnight window (20:00–03:00) is open after midnight too.
     * Null when the working hours are not set or not readable, so nothing wrong is shown.
     */
    public static function isOpenAt(?string $text, CarbonInterface $at): ?bool
    {
        $window = self::window($text);
        if (! $window) {
            return null;
        }
        [$open, $close] = $window;
        if ($close === '23:59' && $open === '00:00') {
            return true;
        }
        $now = local_at($at)->format('H:i');
        if ($open <= $close) {
            return $now >= $open && $now < $close;
        }

        return $now >= $open || $now < $close;
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
