<?php

namespace App\Services\Customers\SignIn;

use App\Models\Customer;
use App\Models\Order;

/**
 * "رقم موبايلي + رقم طلب سابق": the number of an order of THIS restaurant from the last 90 days that was delivered /
 * picked up (status served) and belongs to the guest with that mobile. A delivered order cannot be planted by
 * someone who only typed another person's mobile into the menu, and a guest with money on the prepaid wallet only
 * opens from their own link (as the loyalty card does). One query by order number, then the mobile is compared in
 * constant time, so an unknown mobile and a wrong number take the same path and give the same answer.
 */
final class OrderNumberSignIn implements GuestSignInMethod
{
    public const DAYS = 90;

    public function key(): string
    {
        return 'order_number';
    }

    public function attempt(string $phone, array $input): ?Customer
    {
        $number = latin_digits(ltrim(trim((string) ($input['number'] ?? '')), '#'));
        // numbers start again every shift (OrderNumbers): the guest's own served order with that number, the latest
        $order = ctype_digit($number) && strlen($number) <= 9
            ? Order::query()->where('number', (int) $number)->where('status', 'served')
                ->where('created_at', '>=', now()->subDays(self::DAYS))
                ->whereIn('customer_id', Customer::query()->where('phone', $phone)->select('id'))->latest('id')->first()
            : null;
        $customer = $order ? Customer::query()->find($order->customer_id) : null;
        if (! $customer) {
            return null;
        }
        if ((float) $customer->credit_balance != 0.0 || $customer->walletTransactions()->exists()) {
            return null;
        }

        return $customer;
    }
}
