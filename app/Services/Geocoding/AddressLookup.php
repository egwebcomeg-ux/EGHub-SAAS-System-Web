<?php

namespace App\Services\Geocoding;

use Illuminate\Support\Facades\Cache;

/**
 * The street of a delivery pin and the checkout map's search, through the configured Geocoder, cached in the app
 * cache for every restaurant together (a street has the same name whoever asks; nothing about the guest is in the
 * key or the value). A pin is rounded to a ~25 m square (geocoding.precision) so the guest dragging it around their
 * building asks once. An answer the provider did not give (busy, refused, timed out) is never cached: "nothing".
 */
final class AddressLookup
{
    private const NONE = '__none__';

    public function __construct(private Geocoder $geocoder) {}

    public static function enabled(): bool
    {
        return config('geocoding.driver') !== 'none';
    }

    /**
     * The point snapped to the cache grid.
     *
     * @return array{0:float, 1:float}
     */
    public static function snap(float $lat, float $lng): array
    {
        $step = (float) config('geocoding.precision', 0.00025);

        return [round(round($lat / $step) * $step, 6), round(round($lng / $step) * $step, 6)];
    }

    /** @return array{street:?string, house_number:?string, area:?string, city:?string, formatted_ar:string, lat:float, lng:float}|null */
    public function reverse(float $lat, float $lng): ?array
    {
        [$lat, $lng] = self::snap($lat, $lng);
        $key = 'geo:rev:'.config('geocoding.driver').':'.$lat.','.$lng;
        $cached = Cache::get($key);
        if ($cached !== null) {
            return $cached === self::NONE ? null : $cached;
        }

        try {
            $place = $this->geocoder->reverse($lat, $lng);
        } catch (GeocoderUnavailable) {
            return null;
        }
        Cache::put($key, $place ?? self::NONE, now()->addDays((int) config('geocoding.reverse_cache_days', 30)));

        return $place;
    }

    /**
     * Places matching the text (an empty list: no match), or null when the provider did not answer this time.
     *
     * @param  array{0:float, 1:float}|null  $near
     * @return list<array{street:?string, house_number:?string, area:?string, city:?string, formatted_ar:string, lat:float, lng:float}>|null
     */
    public function search(string $query, ?array $near): ?array
    {
        $query = mb_substr(trim((string) preg_replace('/\s+/u', ' ', $query)), 0, 100);
        if (mb_strlen($query) < 3) {
            return [];
        }
        // the same words near the same branch (a ~10 km square) share one answer
        $around = $near ? round($near[0], 1).','.round($near[1], 1) : '-';
        $key = 'geo:search:'.config('geocoding.driver').':'.hash('sha256', mb_strtolower($query).'|'.$around);
        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $cached;
        }

        try {
            $places = $this->geocoder->search($query, $near, 5);
        } catch (GeocoderUnavailable) {
            return null;
        }
        Cache::put($key, $places, now()->addDays((int) config('geocoding.search_cache_days', 7)));

        return $places;
    }
}
