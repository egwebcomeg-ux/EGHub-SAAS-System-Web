<?php

namespace App\Services\Notify;

use App\Models\Customer;
use App\Models\Order;
use App\Services\Entitlements;
use App\Services\Settings;
use App\Services\WhatsApp\WhatsApp;
use Illuminate\Support\Carbon;

/**
 * Transactional WhatsApp messages to guests (plan feature whatsapp_notifications, or the WhatsApp add-on).
 * Each message type has its own on/off setting; every message costs one credit (App\Billing\Credits).
 * Runs inside the restaurant's tenant context.
 */
final class GuestNotifier
{
    /** message key => setting that switches it on */
    public const TOGGLES = [
        'order_received' => 'wa_order_received',
        'order_ready' => 'wa_order_ready',
        'order_on_way' => 'wa_order_on_way',
        'reservation_confirmed' => 'wa_reservation_confirmed',
        'loyalty_reward' => 'wa_loyalty_reward',
        'order_staff' => 'wa_order_to_staff',
    ];

    /** Orders the guest placed without staff in front of them get a "we received it" message. */
    public const RECEIVED_SOURCES = ['online', 'callcenter', 'kiosk'];

    /** Orders nobody at the counter typed in: the branch's WhatsApp gets a "new order" alert (setting wa_order_to_staff). */
    public const STAFF_ALERT_SOURCES = ['qr', 'online', 'whatsapp', 'kiosk'];

    public function __construct(private WhatsApp $whatsapp) {}

    public function enabled(string $key): bool
    {
        return isset(self::TOGGLES[$key]) && Entitlements::current()->allows('whatsapp_notifications')
            && Settings::get(self::TOGGLES[$key]) === '1';
    }

    /** Name the messages are signed with: the "sender name" setting, else the restaurant's name. */
    public static function senderName(): string
    {
        $brand = WhatsApp::locale() === 'en' && Settings::get('brand_name_en') !== '' ? Settings::get('brand_name_en') : Settings::get('brand_name');

        return trim(Settings::get('wa_sender_name')) ?: (trim($brand) ?: (string) tenant()?->name);
    }

    public function orderPlaced(Order $order): void
    {
        if (in_array($order->source, self::RECEIVED_SOURCES, true)) {
            $this->order($order, 'order_received');
        }
        if (in_array($order->source, self::STAFF_ALERT_SOURCES, true)) {
            $this->orderToStaff($order);
        }
    }

    /**
     * "New order" alert to the restaurant itself: the branch's WhatsApp number, else the restaurant's. The order
     * also shows on the branch's live board; this is for the owner who is not in front of a screen.
     */
    public function orderToStaff(Order $order): void
    {
        $phone = (string) ($order->branch?->whatsapp ?: Settings::get('whatsapp'));
        $this->send('order_staff', $phone, function () use ($order) {
            $order->loadMissing(['items', 'table', 'branch']);
            $en = WhatsApp::locale() === 'en';
            $type = Order::TYPES[$order->type][$en ? 1 : 0] ?? $order->type;
            $where = $order->table ? ($en ? 'Table '.$order->table->name : 'ترابيزة '.$order->table->name) : $type;
            $lines = $order->items->map(fn ($i) => $i->quantity.'× '.$i->name)->implode($en ? ', ' : '، ');
            $who = trim($order->customer_name.' '.$order->customer_phone);
            $text = ($en ? "New order #{$order->number} · {$where}" : "طلب جديد #{$order->number} · {$where}")
                .($order->branch ? ' · '.tr($order->branch->name, $order->branch->name_en) : '')
                ."\n{$lines}"
                ."\n".($en ? 'Total: ' : 'الإجمالي: ').money($order->total).' '.($en ? 'EGP' : 'ج.م')
                .($who !== '' ? "\n".($en ? 'Guest: ' : 'العميل: ').$who : '')
                .($order->delivery_address ? "\n📍 ".trim(($order->delivery_address['zone'] ?? '').' - '.($order->delivery_address['text'] ?? ''), ' -') : '')
                .($order->notes ? "\n📝 ".$order->notes : '')
                ."\n".platform_url('app', 'orders');

            return ['text' => $text];
        }, null);
    }

    public function orderMoved(Order $order, string $to): void
    {
        match ($to) {
            'ready' => $this->order($order, 'order_ready'),
            'on_way' => $this->order($order, 'order_on_way'),
            default => null,
        };
    }

    public function rewardEarned(?Customer $customer, string $reward): void
    {
        if (! $customer) {
            return;
        }
        $this->send('loyalty_reward', $customer->phone, fn () => [
            'name' => $customer->name, 'restaurant' => self::senderName(), 'reward' => $reward,
            'link' => tenant()->url('card/'.$customer->card_token),
        ], $customer->id);
    }

    /** @param array{phone: string, name?: string, at?: string|\DateTimeInterface, guests?: int, customer_id?: int|null} $reservation */
    public function reservationConfirmed(array $reservation): void
    {
        $this->send('reservation_confirmed', (string) ($reservation['phone'] ?? ''), function () use ($reservation) {
            $at = $reservation['at'] ?? null;

            return [
                'name' => $reservation['name'] ?? '', 'restaurant' => self::senderName(), 'guests' => (string) ($reservation['guests'] ?? ''),
                'date' => $at ? Carbon::parse($at)->locale(WhatsApp::locale())->translatedFormat('l j F · h:i A') : '',
            ];
        }, isset($reservation['customer_id']) ? (int) $reservation['customer_id'] : null);
    }

    private function order(Order $order, string $key): void
    {
        $this->send($key, $order->customer_phone, fn () => [
            'name' => $order->customer_name, 'restaurant' => self::senderName(), 'number' => (string) $order->number,
            'link' => tenant()->url('o/'.$order->tracking_token),
        ], $order->customer_id);
    }

    /** A message problem is logged, never allowed to break the order, loyalty or booking that triggered it. */
    private function send(string $key, string $phone, \Closure $values, ?int $customerId): void
    {
        try {
            if ($phone === '' || ! $this->enabled($key)) {
                return;
            }
            $this->whatsapp->queue($phone, $key, $values(), $customerId);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
