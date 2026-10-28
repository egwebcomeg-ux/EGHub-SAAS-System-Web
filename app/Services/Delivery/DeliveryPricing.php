<?php

namespace App\Services\Delivery;

use App\Models\Branch;
use App\Models\DeliveryZone;
use App\Support\OrderTracking;
use Illuminate\Support\Collection;

/**
 * What delivery to a point costs at a branch, decided on the server from the guest's pin (the client's fee is never
 * trusted). Two ways per branch (branches.delivery_pricing):
 *  - zones: the branch's active zones drawn on the map; the zone holding the point wins, a sub-zone over its main
 *    zone, then the smaller area (a street inside a district). Sub-zones inherit an empty fee / minimum / time.
 *  - distance: straight-line (haversine) distance from the branch pin, priced by the first ring reaching it; past
 *    the last ring the branch does not deliver. It is "as the crow flies", not the driving route.
 * resources/js/delivery-geo.js does the same sums in the browser for the live preview only.
 */
final class DeliveryPricing
{
    public const ZONES = 'zones';

    public const DISTANCE = 'distance';

    public const MAX_RINGS = 8;

    public const MAX_KM = 60;

    private const EARTH_KM = 6371.0088;

    public static function method(Branch $branch): string
    {
        return $branch->delivery_pricing === self::DISTANCE ? self::DISTANCE : self::ZONES;
    }

    /**
     * The branch's distance rings, nearest first.
     *
     * @return list<array{km:float, fee:float, min:float, minutes:?int}>
     */
    public static function rings(Branch $branch): array
    {
        return collect((array) $branch->delivery_rings)->filter(fn ($r) => is_array($r) && (float) ($r['km'] ?? 0) > 0)
            ->map(fn (array $r) => [
                'km' => round((float) $r['km'], 2), 'fee' => round(max(0, (float) ($r['fee'] ?? 0)), 2),
                'min' => round(max(0, (float) ($r['min'] ?? 0)), 2),
                'minutes' => isset($r['minutes']) && (int) $r['minutes'] > 0 ? (int) $r['minutes'] : null,
            ])->sortBy('km')->values()->all();
    }

    /** Distance pricing is usable: the branch has its pin and at least one ring. */
    public static function distanceReady(Branch $branch): bool
    {
        return self::method($branch) === self::DISTANCE && $branch->lat !== null && $branch->lng !== null && self::rings($branch) !== [];
    }

    /**
     * The guest can price delivery by pinning the map first: distance pricing is ready, or the zones are drawn on
     * the map (setting delivery_zone_mode "map" with at least one active drawn zone of this branch).
     *
     * @param  array<string,mixed>  $settings
     */
    public static function byLocation(Branch $branch, array $settings, ?Collection $zones = null): bool
    {
        if (self::method($branch) === self::DISTANCE) {
            return self::distanceReady($branch);
        }

        return ($settings['delivery_zone_mode'] ?? 'manual') === 'map'
            && self::drawnZones($branch, $zones)->isNotEmpty();
    }

    /**
     * The delivery to this point, or null when the branch does not deliver there.
     *
     * @return array{zone:?DeliveryZone, km:?float, fee:float, min:float, minutes:?int, label:string}|null
     */
    public static function quote(Branch $branch, float $lat, float $lng): ?array
    {
        if (self::method($branch) === self::DISTANCE) {
            if (! self::distanceReady($branch)) {
                return null;
            }
            $km = self::km((float) $branch->lat, (float) $branch->lng, $lat, $lng);
            $ring = collect(self::rings($branch))->first(fn (array $r) => $km <= $r['km']);

            return $ring ? [
                'zone' => null, 'km' => round($km, 1), 'fee' => $ring['fee'], 'min' => $ring['min'], 'minutes' => $ring['minutes'],
                'label' => self::distanceLabel($km),
            ] : null;
        }

        $zone = self::zoneAt(self::drawnZones($branch), $lat, $lng);

        return $zone ? [
            'zone' => $zone, 'km' => null, 'fee' => round((float) ($zone->effective('fee') ?? 0), 2),
            'min' => round((float) ($zone->effective('min_order') ?? 0), 2),
            'minutes' => ($m = (int) ($zone->effective('estimated_minutes') ?? 0)) > 0 ? $m : null,
            'label' => $zone->label(),
        ] : null;
    }

    /**
     * A distance ring chosen by hand (the call center knows the rough distance, not the pin): that ring's fee,
     * minimum and time, or null when the branch has no such ring. No distance is measured, so km stays null.
     *
     * @return array{zone:null, km:null, fee:float, min:float, minutes:?int, label:string, ring_km:float}|null
     */
    public static function ring(Branch $branch, int $index): ?array
    {
        $ring = self::distanceReady($branch) ? (self::rings($branch)[$index] ?? null) : null;

        return $ring ? [
            'zone' => null, 'km' => null, 'fee' => $ring['fee'], 'min' => $ring['min'], 'minutes' => $ring['minutes'],
            'label' => self::ringLabel($ring['km']), 'ring_km' => $ring['km'],
        ] : null;
    }

    /** «لحد 2 كم من المحل». */
    public static function ringLabel(float $km): string
    {
        return __('Up to :km km from the restaurant', ['km' => rtrim(rtrim(number_format($km, 1), '0'), '.')]);
    }

    /**
     * The zone holding the point: a sub-zone beats its main zone, then the smallest area.
     *
     * @param  Collection<int, DeliveryZone>  $zones
     */
    public static function zoneAt(Collection $zones, float $lat, float $lng): ?DeliveryZone
    {
        return $zones->filter(fn (DeliveryZone $z) => $z->contains($lat, $lng))
            ->sortBy(fn (DeliveryZone $z) => [$z->parent_id ? 0 : 1, self::area((array) $z->polygon)])
            ->first();
    }

    /** Straight-line (great-circle) distance in km. */
    public static function km(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * self::EARTH_KM * asin(min(1.0, sqrt($a)));
    }

    public static function distanceLabel(float $km): string
    {
        return __(':km km from the restaurant', ['km' => number_format($km, 1)]);
    }

    /**
     * The rings as the menu needs them for the live preview (fee, minimum, time to the door).
     *
     * @param  array<string,mixed>  $settings
     * @return list<array{km:float, fee:float, min:float, eta:?int}>
     */
    public static function ringsForMenu(Branch $branch, array $settings): array
    {
        return array_map(fn (array $r) => [
            'km' => $r['km'], 'fee' => $r['fee'], 'min' => $r['min'], 'eta' => OrderTracking::deliveryMinutes($r['minutes'], $settings),
        ], self::rings($branch));
    }

    /** @return Collection<int, DeliveryZone> */
    private static function drawnZones(Branch $branch, ?Collection $zones = null): Collection
    {
        $zones ??= DeliveryZone::query()->with('parent')->where('branch_id', $branch->id)->where('is_active', true)->get();

        return $zones->filter(fn (DeliveryZone $z) => $z->branch_id === $branch->id && $z->is_active && count((array) $z->polygon) >= 3)->values();
    }

    /** Shoelace area in squared degrees: only to rank overlapping zones, smallest first. */
    private static function area(array $points): float
    {
        $sum = 0.0;
        for ($i = 0, $n = count($points), $j = $n - 1; $i < $n; $j = $i++) {
            $sum += ((float) $points[$j][1] + (float) $points[$i][1]) * ((float) $points[$j][0] - (float) $points[$i][0]);
        }

        return abs($sum / 2);
    }
}
