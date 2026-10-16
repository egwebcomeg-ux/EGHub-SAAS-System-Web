<?php

namespace App\Services\Orders;

use App\Services\Settings;
use Illuminate\Support\Carbon;

/**
 * "We are busy right now": until `busy_until` the menu shows a message and online / takeaway / delivery ordering
 * is paused; orders from a table QR, the POS and the call center go on. Expires by itself when the time passes.
 */
final class BusyMode
{
    public const MAX_HOURS = 24;

    public static function until(): ?Carbon
    {
        $raw = Settings::get('busy_until');
        if ($raw === '') {
            return null;
        }
        try {
            return Carbon::parse($raw);
        } catch (\Throwable) {
            return null;
        }
    }

    public static function isBusy(): bool
    {
        $until = self::until();

        return $until !== null && $until->isFuture();
    }

    /** The restaurant's own text, or the standard line. */
    public static function message(): string
    {
        $own = trim(Settings::get('busy_message'));

        return $own !== '' ? $own : __('We are very busy right now, so online ordering is paused for a little while. You can still browse the menu.');
    }

    /** Pauses online ordering until $until (at most MAX_HOURS ahead). */
    public static function start(Carbon $until, string $message = ''): void
    {
        $until = $until->copy()->min(now()->addHours(self::MAX_HOURS));
        Settings::put(['busy_until' => $until->toIso8601String(), 'busy_message' => mb_substr(trim($message), 0, 200)]);
    }

    public static function stop(): void
    {
        Settings::put(['busy_until' => '']);
    }

    /** Does busy mode block this order? Only guests ordering without a table (online / takeaway / delivery). */
    public static function blocks(string $source, bool $hasTable): bool
    {
        return ! $hasTable && in_array($source, ['qr', 'online', 'whatsapp'], true) && self::isBusy();
    }

    /** @return array{until:string, until_iso:string, message:string}|null for the board feed / public menu */
    public static function state(): ?array
    {
        if (! self::isBusy()) {
            return null;
        }
        $until = self::until();

        return ['until' => local_time($until, 'h:i A'), 'until_iso' => $until->toIso8601String(), 'message' => self::message()];
    }
}
