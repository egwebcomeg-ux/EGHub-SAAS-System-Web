<?php

namespace App\Services\Geocoding;

/**
 * A geocoding provider (config geocoding.driver): the place at a point, and places matching a typed text. When the
 * provider is busy, slow or refuses, it throws GeocoderUnavailable (never cached) and the caller answers "nothing":
 * the checkout never depends on it, the guest can always type the street. Callers go through AddressLookup, which
 * caches the real answers (a place, or "nothing there").
 *
 * A place: array{street:?string, house_number:?string, area:?string, city:?string, formatted_ar:string, lat:float, lng:float}
 */
interface Geocoder
{
    /**
     * The place at this point, or null when there is nothing there.
     *
     * @return array{street:?string, house_number:?string, area:?string, city:?string, formatted_ar:string, lat:float, lng:float}|null
     *
     * @throws GeocoderUnavailable
     */
    public function reverse(float $lat, float $lng): ?array;

    /**
     * Up to $limit places in Egypt matching the text, those near $near first (a hint, not a limit).
     *
     * @param  array{0:float, 1:float}|null  $near
     * @return list<array{street:?string, house_number:?string, area:?string, city:?string, formatted_ar:string, lat:float, lng:float}>
     *
     * @throws GeocoderUnavailable
     */
    public function search(string $query, ?array $near, int $limit = 5): array;
}
