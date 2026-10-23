<?php

namespace App\Billing;

use App\Models\PlatformSetting;

/**
 * How a restaurant can pay its subscription by hand. Manual methods are switched on in the super admin
 * (Payment settings) and confirmed by EgWeb. Paying online (Kashier) is App\Billing\OnlinePayments: a gateway
 * behind App\Billing\Gateways\PaymentGateway whose signed webhook ends in Billing::markPaid(..., idempotencyKey).
 */
final class PaymentMethods
{
    /**
     * The manual methods a restaurant is offered: switched on, and with the account to send the money to (a method
     * without its handle / number is never shown, there would be nowhere to pay).
     *
     * @return list<array{key:string, label:string, account:string}>
     */
    public static function manual(): array
    {
        $out = [];
        $instapay = trim((string) PlatformSetting::get('pay_instapay_handle', ''));
        if (PlatformSetting::get('pay_instapay_enabled') === '1' && $instapay !== '') {
            $out[] = ['key' => 'instapay', 'label' => 'InstaPay', 'account' => $instapay];
        }
        $vodafone = trim((string) PlatformSetting::get('pay_vodafone_number', ''));
        if (PlatformSetting::get('pay_vodafone_enabled') === '1' && $vodafone !== '') {
            $out[] = ['key' => 'vodafone_cash', 'label' => __('Vodafone Cash'), 'account' => $vodafone];
        }

        return $out;
    }

    public static function instructions(): string
    {
        return (string) PlatformSetting::get('pay_instructions', '');
    }

    public static function label(string $key): string
    {
        return match ($key) {
            'instapay' => 'InstaPay',
            'vodafone_cash' => __('Vodafone Cash'),
            'manual' => __('Manual'),
            'kashier' => 'Kashier',
            'cash' => __('Cash'),
            'card' => __('Card'),
            'wallet' => __('Mobile wallet'),
            'credit' => __('Wallet balance'),
            'corporate' => __('Corporate voucher'),
            'split' => __('Split payment'),
            default => ucfirst($key),
        };
    }
}
