<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Menu\GeoController;
use App\Models\Branch;
use App\Models\Customer;
use App\Services\Delivery\DeliveryPricing;
use App\Services\Delivery\KnownAddresses;
use App\Services\Geocoding\AddressLookup;
use App\Services\Settings;
use App\Support\OrderTracking;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The call center's "where is the customer" on the dashboard host, for a branch priced by distance: the street of
 * the pin and "اكتب عنوان العميل" with live suggestions. Both go through the same AddressLookup as the guest's
 * checkout (cached, the provider's one-call-a-second kept, nothing about the caller leaves the server), behind the
 * staff sign-in, the call-center permission and a per-user rate limit, and are never stored by a cache (no-store).
 * Every place carries what delivery there costs (DeliveryPricing); the order is priced again on the server.
 */
class CallCenterGeoController extends Controller
{
    /** The pin's street, area and building, and the delivery to it. */
    public function reverse(Request $request, AddressLookup $lookup): JsonResponse
    {
        $data = $request->validate([
            'lat' => 'required|numeric|between:-90,90',
            'lng' => 'required|numeric|between:-180,180',
        ]);
        $branch = PosController::branch($request);
        $lat = (float) $data['lat'];
        $lng = (float) $data['lng'];
        $place = AddressLookup::enabled() ? $lookup->reverse($lat, $lng) : null;

        return response()->json([
            'found' => $place !== null,
            'street' => $place['street'] ?? null,
            'house_number' => $place['house_number'] ?? null,
            'area' => $place['area'] ?? null,
            'city' => $place['city'] ?? null,
            'formatted_ar' => $place['formatted_ar'] ?? null,
        ] + self::delivery($branch, $lat, $lng), 200, GeoController::HEADERS);
    }

    /**
     * Suggestions for the typed address: the caller's saved addresses and the branch's previous delivery addresses
     * first (they already have their pin, no geocoder call); the geocoder near the branch when none of them matches,
     * or when the agent asks for it (Enter: more=1).
     */
    public function suggest(Request $request, AddressLookup $lookup): JsonResponse
    {
        $data = $request->validate([
            'q' => 'required|string|min:3|max:100',
            'phone' => 'nullable|string|max:20',
            'more' => 'nullable|boolean',
        ]);
        $branch = PosController::branch($request);
        $phone = normalize_phone((string) ($data['phone'] ?? ''));
        $customer = $phone ? Customer::query()->where('phone', $phone)->first() : null;

        $results = array_map(fn (array $a) => [
            'source' => $a['source'], 'label' => $a['label'] !== '' ? $a['label'].' · '.$a['text'] : $a['text'], 'text' => $a['text'],
            'address_label' => $a['label'], 'lat' => $a['lat'], 'lng' => $a['lng'],
            'street' => $a['parts']['street'] ?? null, 'house_number' => $a['parts']['building'] ?? null,
            'area' => $a['parts']['area'] ?? null, 'city' => null,
        ] + self::delivery($branch, $a['lat'], $a['lng']), KnownAddresses::match($branch, $data['q'], $customer));

        $ok = true;
        $searched = false;
        if (AddressLookup::enabled() && ($results === [] || $request->boolean('more'))) {
            $searched = true;
            $near = $branch->lat !== null && $branch->lng !== null ? [(float) $branch->lat, (float) $branch->lng] : null;
            $places = $lookup->search($data['q'], $near);
            $ok = $places !== null;
            foreach ($places ?? [] as $p) {
                $results[] = [
                    'source' => 'map', 'label' => $p['formatted_ar'], 'text' => null, 'address_label' => '', 'lat' => $p['lat'], 'lng' => $p['lng'],
                    'street' => $p['street'], 'house_number' => $p['house_number'], 'area' => $p['area'], 'city' => $p['city'],
                ] + self::delivery($branch, (float) $p['lat'], (float) $p['lng']);
            }
        }

        return response()->json([
            'ok' => $ok, 'searched' => $searched, 'geocoder' => AddressLookup::enabled(), 'results' => $results,
        ], 200, GeoController::HEADERS);
    }

    /**
     * What delivery to the point costs at the branch: {delivery: {km, fee, min, eta, label}, outside: false}, or no
     * delivery and outside: true when the branch does not deliver there.
     *
     * @return array{delivery: array{km:?float, fee:float, min:float, eta:?int, label:string}|null, outside: bool}
     */
    public static function delivery(Branch $branch, float $lat, float $lng): array
    {
        $quote = DeliveryPricing::quote($branch, $lat, $lng);

        return [
            'delivery' => $quote ? [
                'km' => $quote['km'], 'fee' => $quote['fee'], 'min' => $quote['min'],
                'eta' => OrderTracking::deliveryMinutes($quote['minutes'], Settings::all()), 'label' => $quote['label'],
            ] : null,
            'outside' => $quote === null,
        ];
    }
}
