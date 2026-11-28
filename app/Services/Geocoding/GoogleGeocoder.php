<?php

namespace App\Services\Geocoding;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Google's Geocoding API (config geocoding.google), called from the server with the server key: Arabic names, Egypt
 * only, the typed address looked for around the branch first. Every call is counted against the month's limit
 * (MapProvider); a refusal, a quota answer or a timeout throws GeocoderUnavailable. Its places are shown on a
 * Google map only (MapProvider::forMap).
 */
final class GoogleGeocoder implements Geocoder
{
    private const URL = 'https://maps.googleapis.com/maps/api/geocode/json';

    /** Names Google gives a street nobody named. */
    private const UNNAMED = ['unnamed road', 'طريق بدون اسم', 'طريق غير مسمى', 'طريق غير مسمّى'];

    public function reverse(float $lat, float $lng): ?array
    {
        $results = $this->get(['latlng' => $lat.','.$lng, 'result_type' => 'street_address|route|neighborhood|sublocality|locality|premise']);
        if ($results === []) {
            return null;
        }

        // the pin stays where the guest put it: only the names come from Google
        return self::place($results, $lat, $lng);
    }

    public function search(string $query, ?array $near, int $limit = 5): array
    {
        $params = ['address' => $query, 'components' => 'country:EG'];
        if ($near) {
            // about 25 km around the branch: places there first, the rest of Egypt still allowed
            [$lat, $lng] = $near;
            $params['bounds'] = round($lat - 0.25, 4).','.round($lng - 0.25, 4).'|'.round($lat + 0.25, 4).','.round($lng + 0.25, 4);
        }

        return collect($this->get($params))
            // "Egypt" alone is Google saying it found nothing more precise than the country
            ->reject(fn (array $row) => in_array('country', (array) ($row['types'] ?? []), true))
            ->filter(fn (array $row) => isset($row['geometry']['location']['lat'], $row['geometry']['location']['lng']))
            ->map(fn (array $row) => self::place([$row], (float) $row['geometry']['location']['lat'], (float) $row['geometry']['location']['lng']))
            ->filter(fn (array $place) => $place['formatted_ar'] !== '')->take($limit)->values()->all();
    }

    /**
     * Google's results (the most precise first) in our shape: each name from the first result that has it. The line
     * shown is "street، area، city" without repeating a name, or the place's own name when it has no street.
     *
     * @param  list<array<string,mixed>>  $results
     * @return array{street:?string, house_number:?string, area:?string, city:?string, formatted_ar:string, lat:float, lng:float}
     */
    public static function place(array $results, float $lat, float $lng): array
    {
        $pick = function (array $types) use ($results): ?string {
            foreach ($types as $type) {
                foreach ($results as $result) {
                    foreach ((array) ($result['address_components'] ?? []) as $component) {
                        $name = trim((string) ($component['long_name'] ?? ''));
                        if ($name !== '' && in_array($type, (array) ($component['types'] ?? []), true) && ! in_array(mb_strtolower($name), self::UNNAMED, true)) {
                            return mb_substr($name, 0, 120);
                        }
                    }
                }
            }

            return null;
        };
        $street = $pick(['route']);
        $area = $pick(['neighborhood', 'sublocality_level_1', 'sublocality', 'administrative_area_level_3']);
        $city = $pick(['locality', 'administrative_area_level_2', 'administrative_area_level_1']);
        // a named place without a street (a mall, a compound, a landmark)
        $name = $pick(['premise', 'point_of_interest', 'establishment']);

        $parts = [];
        foreach ([$street ?? $name, $area, $city] as $part) {
            if ($part !== null && ! collect($parts)->contains(fn (string $p) => str_contains($p, $part))) {
                $parts[] = $part;
            }
        }

        return [
            'street' => $street,
            'house_number' => $pick(['street_number']),
            'area' => $area,
            'city' => $city,
            'formatted_ar' => implode('، ', $parts),
            'lat' => round($lat, 6),
            'lng' => round($lng, 6),
        ];
    }

    /**
     * One counted call.
     *
     * @param  array<string,string>  $params
     * @return list<array<string,mixed>>
     *
     * @throws GeocoderUnavailable
     */
    private function get(array $params): array
    {
        if (! MapProvider::underLimit()) {
            throw new GeocoderUnavailable('Google geocoding: this month\'s limit is reached');
        }
        MapProvider::countCall();

        try {
            $response = Http::acceptJson()->timeout((int) config('geocoding.google.timeout', 5))->connectTimeout(3)
                ->get(self::URL, $params + ['language' => 'ar', 'region' => 'eg', 'key' => (string) config('geocoding.google.server_key')]);
        } catch (ConnectionException $e) {
            throw new GeocoderUnavailable('Google geocoding did not answer', 0, $e);
        }

        $status = (string) $response->json('status');
        if ($response->successful() && $status === 'ZERO_RESULTS') {
            return [];
        }
        if (! $response->successful() || $status !== 'OK') {
            // a refused key, billing off or the daily quota: worth a line in the log (never the key), not an error page
            Log::warning('Google geocoding refused a call', ['http' => $response->status(), 'status' => $status]);

            throw new GeocoderUnavailable('Google geocoding answered '.($status !== '' ? $status : $response->status()));
        }

        return array_values(array_filter((array) $response->json('results'), 'is_array'));
    }
}
