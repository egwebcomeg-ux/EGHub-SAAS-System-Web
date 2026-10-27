<?php

namespace App\Services\Sms;

/**
 * The configured SMS provider (config/sms.php), or none. Bulk sending from the manual campaigns only shows when one
 * is set; the estimate counts the segments of the message (Arabic takes 70 characters a segment, latin 160).
 */
final class Sms
{
    public static function enabled(): bool
    {
        return self::driver() !== null;
    }

    public static function driver(): ?SmsDriver
    {
        if (app()->bound(SmsDriver::class)) {
            return app(SmsDriver::class); // a provider bound in code (tests)
        }

        return match (config('sms.driver')) {
            'log' => new LogSmsDriver,
            'http' => filled(config('sms.http.url')) ? new HttpSmsDriver((array) config('sms.http'), (string) config('sms.sender')) : null,
            default => null,
        };
    }

    /** How many SMS segments $text takes. */
    public static function segments(string $text): int
    {
        $length = mb_strlen($text);
        if ($length === 0) {
            return 0;
        }
        [$single, $multi] = preg_match('/[^\x00-\x7F]/', $text) === 1 ? [70, 67] : [160, 153];

        return $length <= $single ? 1 : (int) ceil($length / $multi);
    }

    /** @return array{segments:int, messages:int, cost:float, currency:string} */
    public static function estimate(string $text, int $recipients): array
    {
        $segments = self::segments($text);

        return ['segments' => $segments, 'messages' => $recipients, 'cost' => round($segments * $recipients * (float) config('sms.cost_per_segment'), 2),
            'currency' => (string) config('sms.currency')];
    }
}
