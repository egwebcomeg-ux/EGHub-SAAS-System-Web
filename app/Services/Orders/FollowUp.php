<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\User;
use App\Services\Settings;

/**
 * «متابعة العملاء»: staff message the guest of any order (every source: the menu, the cashier, the call center, the
 * kiosk, WhatsApp) from WhatsApp Web, with a message ready for where the order is now. Nothing is sent by EgHub: the
 * link opens the staff member's own WhatsApp Web with the text filled in, and they press send. Opening it marks the
 * order as followed up by that staff member, so a second agent sees it was done.
 */
final class FollowUp
{
    /** What the message is about, from the order's status and type. */
    public static function stage(Order $order): string
    {
        return match (true) {
            $order->status === 'cancelled' => 'cancelled',
            $order->status === 'served' => 'served',
            $order->status === 'on_way', $order->status === 'ready' && $order->type === 'delivery' => 'on_way',
            $order->status === 'ready' => 'ready',
            default => 'preparing',
        };
    }

    /** Only a mobile can have WhatsApp; a landline the call center typed has no follow-up link. */
    public static function phone(Order $order): ?string
    {
        $mobile = normalize_phone((string) $order->customer_phone);

        return $mobile !== null ? whatsapp_number($mobile) : null;
    }

    public static function message(Order $order): string
    {
        $name = trim((string) $order->customer_name);
        $hello = $name !== '' ? __('Hello :name,', ['name' => $name]) : __('Hello,');
        $restaurant = tr(Settings::get('brand_name'), Settings::get('brand_name_en')) ?: (string) tenant('id');
        $replace = ['restaurant' => $restaurant, 'n' => $order->number, 'link' => tenant()->url('o/'.$order->tracking_token)];

        $body = match (self::stage($order)) {
            'preparing' => __('this is :restaurant. We got your order #:n and it is being prepared. You can follow it here: :link', $replace),
            'ready' => __('your order #:n from :restaurant is ready for you to pick up.', $replace),
            'on_way' => __('your order #:n from :restaurant is on its way to you. You can follow it here: :link', $replace),
            'served' => __('thank you for ordering from :restaurant. Did order #:n reach you well? We would love to hear what you thought of it.', $replace),
            'cancelled' => __('this is :restaurant about order #:n. We are sorry it was cancelled. Can we help you order again?', $replace),
        };

        return $hello.' '.$body;
    }

    /**
     * The WhatsApp Web link with the message filled in; null when the order has no mobile. The page swaps it for the
     * wa.me link on a phone, where WhatsApp Web does not open.
     */
    public static function webUrl(Order $order): ?string
    {
        $phone = self::phone($order);

        return $phone !== null ? 'https://web.whatsapp.com/send?phone='.$phone.'&text='.rawurlencode(self::message($order)) : null;
    }

    public static function appUrl(Order $order): ?string
    {
        $phone = self::phone($order);

        return $phone !== null ? 'https://wa.me/'.$phone.'?text='.rawurlencode(self::message($order)) : null;
    }

    /** The first staff member who followed up keeps the mark; opening the chat again does not move it. */
    public static function mark(Order $order, User $by): void
    {
        if ($order->followed_up_at === null) {
            $order->forceFill(['followed_up_at' => now(), 'followed_up_by' => $by->id])->save();
        }
    }
}
