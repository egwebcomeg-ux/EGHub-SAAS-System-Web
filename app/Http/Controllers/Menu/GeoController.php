<?php

namespace App\Http\Controllers\Menu;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\DeliveryZone;
use App\Services\Delivery\DeliveryPricing;
use App\Services\Delivery\ZoneNameMatch;
use App\Services\Geocoding\AddressLookup;
use App\Services\Menu\MenuCatalog;
use App\Services\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The checkout map's helpers on the restaurant's own host (/geo/...): the street of the guest's pin and a search for
 * a street or a place. Both go through AddressLookup (cached, the provider's policy kept) so the guest's phone never
 * calls the provider and only the point or the typed words leave the server, never a name or a mobile. Answers are
 * never stored by a cache (no-store) and the visitor is rate limited. Only a branch delivering online here can use
 * them; the area found for the pin is a preview, the order is priced again on the server.
 */
class GeoController extends Controller
{
    public const HEADERS = CardAppController::PRIVATE_HEADERS;

    /** The pin's street and area, and the delivery area it falls in (drawn, ring, or an area named like it). */
    public function reverse(Request $request, AddressLookup $lookup): JsonResponse
    {
        $data = $request->validate([
            'lat' => 'required|numeric|between:-90,90',
            'lng' => 'required|numeric|between:-180,180',
            'branch' => 'nullable|integer',
        ]);
        $branch = $this->branch($data['branch'] ?? null);
        $lat = (float) $data['lat'];
        $lng = (float) $data['lng'];

        $place = $lookup->reverse($lat, $lng);
        $quote = DeliveryPricing::byLocation($branch, Settings::all()) ? DeliveryPricing::quote($branch, $lat, $lng) : null;

        return response()->json([
            'found' => $place !== null,
            'street' => $place['street'] ?? null,
            'house_number' => $place['house_number'] ?? null,
            'area' => $place['area'] ?? null,
            'city' => $place['city'] ?? null,
            'formatted_ar' => $place['formatted_ar'] ?? null,
            'delivery' => $quote ? [
                'zone_id' => $quote['zone']?->id, 'name' => $quote['label'], 'fee' => $quote['fee'], 'min' => $quote['min'], 'km' => $quote['km'],
            ] : null,
            'zone' => $this->zoneFor($branch, $lat, $lng, $place),
        ], 200, self::HEADERS);
    }

    /** "دوّر على شارع أو مكان": up to 5 places, those near the branch first. */
    public function search(Request $request, AddressLookup $lookup): JsonResponse
    {
        $data = $request->validate([
            'q' => 'required|string|min:3|max:100',
            'branch' => 'nullable|integer',
        ]);
        $branch = $this->branch($data['branch'] ?? null);
        $near = $branch->lat !== null && $branch->lng !== null ? [(float) $branch->lat, (float) $branch->lng] : null;
        $places = $lookup->search($data['q'], $near);

        return response()->json([
            'ok' => $places !== null,
            'results' => array_map(fn (array $p) => [
                'label' => $p['formatted_ar'], 'lat' => $p['lat'], 'lng' => $p['lng'],
                'street' => $p['street'], 'house_number' => $p['house_number'], 'area' => $p['area'], 'city' => $p['city'],
                'formatted_ar' => $p['formatted_ar'],
            ], $places ?? []),
        ], 200, self::HEADERS);
    }

    /** The branch of the cart, delivering online now; anything else is not found (no free geocoding for anyone). */
    private function branch(?int $id): Branch
    {
        abort_unless(AddressLookup::enabled(), 404);
        $branch = Branch::query()->where('is_active', true)->find($id ?? 0) ?? MenuCatalog::defaultBranch();
        $online = MenuController::onlineOptions($branch, Settings::all());
        abort_unless($online && $online['delivery'], 404);

        return $branch;
    }

    /**
     * The area of the list to put the guest on: the drawn area holding the pin, or else (areas typed by hand) the
     * one named like the pin's street, district or city when that is a confident match.
     *
     * @param  array<string,mixed>|null  $place
     * @return array{id:int, name:string, by:string}|null
     */
    private function zoneFor(Branch $branch, float $lat, float $lng, ?array $place): ?array
    {
        if (DeliveryPricing::method($branch) === DeliveryPricing::DISTANCE) {
            return null;
        }
        $zones = DeliveryZone::query()->with('parent')->where('branch_id', $branch->id)->where('is_active', true)->get();
        $drawn = $zones->filter(fn (DeliveryZone $z) => count((array) $z->polygon) >= 3)->values();
        if ($zone = DeliveryPricing::zoneAt($drawn, $lat, $lng)) {
            return ['id' => $zone->id, 'name' => $zone->label(), 'by' => 'area'];
        }
        if (! $place) {
            return null;
        }
        // a pin outside every drawn area is never matched to one of them by its name
        $zone = ZoneNameMatch::best($zones->reject(fn (DeliveryZone $z) => count((array) ($z->polygon ?: $z->parent?->polygon)) >= 3)->values(),
            [$place['street'] ?? null, $place['area'] ?? null, $place['city'] ?? null]);

        return $zone ? ['id' => $zone->id, 'name' => $zone->label(), 'by' => 'name'] : null;
    }
}
