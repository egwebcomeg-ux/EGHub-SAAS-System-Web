<?php

namespace App\Services\Delivery;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Order;
use App\Support\DeliveryAddress;

/**
 * "اكتب عنوان العميل" at the call center: before asking the geocoder, the typed words are tried against the places
 * this restaurant already delivered to, which already carry their pin. First the caller's own saved addresses, then
 * the addresses of the branch's recent delivery orders. Customers and orders are tenant models (scoped to the
 * current restaurant) and the orders are the branch's own, so one restaurant never sees another's addresses.
 * Only the address itself is returned, never who ordered there.
 */
final class KnownAddresses
{
    /** How many recent delivery orders of the branch are looked through. */
    private const RECENT_ORDERS = 400;

    /**
     * @return list<array{source:string, label:string, text:string, lat:float, lng:float, parts:array<string,string>}>
     */
    public static function match(Branch $branch, string $query, ?Customer $customer, int $limit = 4): array
    {
        $wanted = array_filter(explode(' ', ZoneNameMatch::fold($query)));
        if ($wanted === [] || mb_strlen(implode('', $wanted)) < 3) {
            return [];
        }
        $hits = [];
        $seen = [];
        $take = function (string $source, array $a) use ($wanted, &$hits, &$seen): void {
            $text = trim((string) ($a['text'] ?? ''));
            if ($text === '' || ! isset($a['lat'], $a['lng']) || ! is_numeric($a['lat']) || ! is_numeric($a['lng'])) {
                return;
            }
            $parts = DeliveryAddress::clean(is_array($a['parts'] ?? null) ? $a['parts'] : null);
            $haystack = ZoneNameMatch::fold($text.' '.implode(' ', $parts).' '.($a['label'] ?? ''));
            foreach ($wanted as $word) {
                if (! str_contains($haystack, $word)) {
                    return;
                }
            }
            $key = ZoneNameMatch::fold($text);
            if (isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;
            $hits[] = [
                'source' => $source, 'label' => (string) ($a['label'] ?? ''), 'text' => $text,
                'lat' => round((float) $a['lat'], 6), 'lng' => round((float) $a['lng'], 6), 'parts' => $parts,
            ];
        };

        foreach ((array) ($customer?->addresses ?? []) as $address) {
            if (is_array($address)) {
                $take('saved', $address);
            }
        }
        if (count($hits) < $limit) {
            Order::query()->where('branch_id', $branch->id)->where('type', 'delivery')->whereNotNull('delivery_address')
                ->latest('id')->limit(self::RECENT_ORDERS)->get(['id', 'delivery_address'])
                ->each(function (Order $order) use ($take, &$hits, $limit) {
                    $take('previous', ['label' => ''] + (array) $order->delivery_address);

                    return count($hits) < $limit;
                });
        }

        return array_slice($hits, 0, $limit);
    }
}
