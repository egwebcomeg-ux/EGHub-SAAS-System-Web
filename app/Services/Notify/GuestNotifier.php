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
    ];

    /** Orders the guest placed without staff in front of them get a "we received it" message. */
    public const RECEIVED_SOURCES = ['online', 'callcenter', 'kiosk'];

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
