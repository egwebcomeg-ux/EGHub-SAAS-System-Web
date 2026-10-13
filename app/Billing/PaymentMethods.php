<?php

namespace App\Billing;

use App\Models\PlatformSetting;

/**
 * How a restaurant can pay its subscription today. Manual methods are switched on in the super admin
 * (Payment settings). Online gateways (Paymob, Kashier, Geidea) plug in later behind the same list: each gets a
 * class implementing a `checkout(Invoice): url` + webhook that ends in Billing::markPaid(..., idempotencyKey).
 */
final class PaymentMethods
{
    /** @return list<array{key:string, label:string, account:string}> */
    public static function manual(): array
    {
        $out = [];
        if (PlatformSetting::get('pay_instapay_enabled') === '1') {
            $out[] = ['key' => 'instapay', 'label' => 'InstaPay', 'account' => (string) PlatformSetting::get('pay_instapay_handle', '')];
        }
        if (PlatformSetting::get('pay_vodafone_enabled') === '1') {
            $out[] = ['key' => 'vodafone_cash', 'label' => __('Vodafone Cash'), 'account' => (string) PlatformSetting::get('pay_vodafone_number', '')];
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
            default => ucfirst($key),
        };
    }
}
