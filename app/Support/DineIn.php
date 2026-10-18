<?php

namespace App\Support;

use App\Services\Entitlements;

/**
 * What a guest in the hall (table QR, or the general menu link choosing "in the place") can do with the cart.
 *
 * - order: the guest sends the order from the table to the orders board (plan feature qr_ordering, ordering on).
 * - list:  the guest builds a list to show the waiter; nothing is sent. Every plan, the free one included.
 * - off:   menu only, no cart in the hall.
 *
 * The setting dine_in_mode is empty until the owner picks something other than the default, so a restaurant that
 * upgrades to table ordering gets it without touching the settings.
 */
final class DineIn
{
    public const MODES = ['order', 'list', 'off'];

    /** Whether the plan lets guests send orders from the table at all. */
    public static function orderingAllowed(): bool
    {
        return Entitlements::current()->allows('qr_ordering');
    }

    /** The mode a restaurant gets while the owner has not chosen: order when it can take table orders, else list. */
    public static function defaultMode(array $settings): string
    {
        return self::orderingAllowed() && ($settings['ordering_enabled'] ?? '1') === '1' ? 'order' : 'list';
    }

    /** The mode in effect: order falls back to list when the plan or the ordering switch does not allow it. */
    public static function mode(array $settings): string
    {
        $chosen = (string) ($settings['dine_in_mode'] ?? '');

        return in_array($chosen, ['list', 'off'], true) ? $chosen : self::defaultMode($settings);
    }

    /** Prices and the total inside the hall cart/list (the menu itself always shows prices). */
    public static function showPrices(array $settings, string $mode): bool
    {
        $chosen = (string) ($settings['dine_in_show_prices'] ?? '');

        return $chosen === '' ? self::defaultShowPrices($mode) : $chosen === '1';
    }

    /** Default: prices in an order, a plain read-out list for the waiter (Desoky / Gold style). */
    public static function defaultShowPrices(string $mode): bool
    {
        return $mode === 'order';
    }

    /** The short text shown on the hall list. */
    public static function notice(array $settings): string
    {
        $text = trim((string) ($settings['dine_in_notice'] ?? ''));

        return $text !== '' ? $text : __('This list is for showing the waiter. Hand it to the service team.');
    }
}
