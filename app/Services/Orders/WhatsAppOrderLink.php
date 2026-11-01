<?php

namespace App\Services\Orders;

use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Customers\GuestAccount;
use App\Services\Settings;

/**
 * «ابعت الطلب على واتساب» (setting order_whatsapp_handoff, off by default): after a guest places an order from the
 * QR or online menu, the confirmation page opens the restaurant's WhatsApp with the order prefilled and the guest
 * sends it from their own account. It is only a wa.me link: nothing goes through the WhatsApp Cloud API
 * (App\Services\WhatsApp) and no credit is used. Everything in the message is read from the saved order.
 *
 * Only reached from the tracking page and the order placement, so the access rule is the page's: the current
 * restaurant's order (TenantModel scope, checked again here) and its unguessable tracking token.
 */
final class WhatsAppOrderLink
{
    public const SETTING = 'order_whatsapp_handoff';

    /** Orders the guest placed from their own phone; the cashier, call center and kiosk have nothing to hand over. */
    private const SOURCES = ['qr', 'online'];

    /** Above this the link risks being cut by the browser or WhatsApp: the message drops each line's details. */
    private const MAX_URL_LENGTH = 6000;

    public static function enabled(): bool
    {
        return Settings::get(self::SETTING) === '1' || GuestAccount::hidden();
    }

    /**
     * The link for this order, or null when the guest should only get the normal confirmation: the switch is off,
     * the order is another restaurant's, is not the guest's own, is already closed, or there is no number to open.
     */
    public static function url(Order $order): ?string
    {
        if (! self::enabled() || ! in_array($order->source, self::SOURCES, true) || in_array($order->status, ['served', 'cancelled'], true)) {
            return null;
        }
        if (! tenant() || (string) $order->tenant_id !== (string) tenant()->getTenantKey()) {
            return null;
        }
        $number = self::number($order->branch);
        if ($number === null) {
            return null;
        }
        $base = 'https://wa.me/'.$number.'?text=';
        $url = $base.rawurlencode(self::message($order));

        return strlen($url) > self::MAX_URL_LENGTH ? $base.rawurlencode(self::message($order, false)) : $url;
    }

    /** The number the order goes to, like the tracking page's: the branch's own, else the restaurant's. */
    public static function number(?Branch $branch): ?string
    {
        return self::wa((string) ($branch?->whatsapp ?: Settings::get('whatsapp')));
    }

    /** Whether any order could open WhatsApp (the settings page warns when none can). */
    public static function hasNumber(): bool
    {
        return self::wa((string) Settings::get('whatsapp')) !== null
            || Branch::query()->where('is_active', true)->pluck('whatsapp')->contains(fn (?string $number) => self::wa((string) $number) !== null);
    }

    /** The message in the guest's menu language; $details false leaves out each line's choices and notes. */
    public static function message(Order $order, bool $details = true): string
    {
        $settings = Settings::all();
        $currency = tr($settings['currency'], $settings['currency_en']);
        $lines = [__('Order #:n', ['n' => $order->number]), __('Order type').': '.self::type($order), ''];

        foreach ($order->items as $item) {
            $lines[] = $item->quantity.'× '.$item->name;
            if ($details) {
                array_push($lines, ...self::itemDetails($item));
            }
        }
        if (! $details) {
            $lines[] = __('The full details are with the order at the restaurant.');
        }
        if ($details && $order->notes) {
            $lines[] = __('Notes').': '.$order->notes;
        }
        $lines[] = '';

        // same rule as the tracking page: a restaurant can hide the bill total from guests in the hall
        if (! ($order->type === 'dine_in' && ($settings['dine_in_hide_total'] ?? '0') === '1')) {
            $lines[] = __('Total').': '.money($order->total).' '.$currency;
        }
        if ($payment = self::payment($order)) {
            $lines[] = __('Payment').': '.$payment;
        }
        if ($order->customer_name) {
            $lines[] = __('Name').': '.$order->customer_name;
        }
        if ($order->customer_phone) {
            $lines[] = __('Mobile').': '.$order->customer_phone;
        }
        if ($order->type === 'delivery' && $order->delivery_address) {
            $place = array_filter([$order->delivery_address['zone'] ?? '', $order->delivery_address['text'] ?? '']);
            $lines[] = __('Address').': '.implode(' - ', $place);
        }

        return trim(implode("\n", $lines));
    }

    private static function wa(string $number): ?string
    {
        return strlen(preg_replace('/\D+/', '', $number) ?? '') >= 8 ? whatsapp_number($number) : null;
    }

    private static function type(Order $order): string
    {
        $type = Order::TYPES[$order->type][app()->getLocale() === 'en' ? 1 : 0] ?? (string) $order->type;

        return $order->table ? $type.' - '.__('Table :name', ['name' => $order->table->name]) : $type;
    }

    /**
     * The choices of a line by their group (a size is a choice group too), then the line's note.
     *
     * @return list<string>
     */
    private static function itemDetails(OrderItem $item): array
    {
        $details = collect($item->options ?: [])->groupBy('group')
            ->map(fn ($options, $group) => '  - '.($group !== '' ? $group.': ' : '').$options->pluck('name')->implode('، '))
            ->values()->all();
        if ($item->notes) {
            $details[] = '  - '.__('Notes').': '.$item->notes;
        }

        return $details;
    }

    /** Known only once something is paid; a guest's order usually is not at this point. */
    private static function payment(Order $order): ?string
    {
        if ($order->payment_status !== 'paid') {
            return null;
        }

        return match ($order->payment_method) {
            'credit' => __('Paid from my balance'),
            'cash' => __('Cash'),
            'card' => __('Card'),
            'instapay' => __('InstaPay'),
            'wallet' => __('Wallet'),
            default => null,
        };
    }
}
