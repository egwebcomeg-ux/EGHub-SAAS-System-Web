<?php

namespace App\Services\Orders;

use App\Services\Settings;

/**
 * The money rule of a bill, written once: the service charge is taken in the hall only, on the food after every
 * discount; the tax on that food plus the service; the delivery fee comes on top of both. The pages compute the same
 * figures while the guest or cashier builds the cart (resources/js/bill.js); the order is always priced here.
 */
final class Bill
{
    /**
     * @param  float  $food  the lines' total after discounts
     * @return array{service: float, tax: float}
     */
    public static function charges(float $food, bool $dineIn, ?float $servicePercent = null, ?float $taxPercent = null): array
    {
        $service = $dineIn ? round($food * ($servicePercent ?? self::rate('service_percent')), 2) : 0.0;
        $tax = round(($food + $service) * ($taxPercent ?? self::rate('tax_percent')), 2);

        return ['service' => $service, 'tax' => $tax];
    }

    /** The restaurant's percentage as a fraction (0.12 for 12%), never below 0 or above 100%. */
    public static function rate(string $key): float
    {
        return max(0.0, min(100.0, (float) Settings::get($key))) / 100;
    }
}
