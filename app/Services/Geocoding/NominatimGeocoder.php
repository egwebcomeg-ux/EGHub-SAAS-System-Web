<?php

namespace App\Services\Geocoding;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * OpenStreetMap's Nominatim (config geocoding.nominatim), within its usage policy: a real User-Agent, Arabic names
 * first then English, and one call at a time for the whole platform. The turn is a cache lock nobody releases: it
 * expires after the interval, so two calls are never closer than that whichever server or restaurant makes them. A
 * call that cannot get its turn in a moment, a 429, an error or a timeout throws GeocoderUnavailable.
 */
final class NominatimGeocoder implements Geocoder
{
    private const SLOT = 'geocoder:nominatim:slot';

    public function reverse(float $lat, float $lng): ?array
    {
        $body = $this->get('/reverse', [
            'lat' => $lat, 'lon' => $lng, 'zoom' => 18, 'addressdetails' => 1,
        ]);
        if (! is_array($body) || isset($body['error']) || ! isset($body['lat'], $body['lon'])) {
            return null; // "Unable to geocode": nothing at this point (the sea, the desert)
        }

        return self::place($body);
    }

    public function search(string $query, ?array $near, int $limit = 5): array
    {
        $params = ['q' => $query, 'countrycodes' => 'eg', 'limit' => $limit, 'addressdetails' => 1, 'bounded' => 0];
        if ($near) {
            // a box of about 25 km around the branch: results there first, the rest of Egypt still allowed
            [$lat, $lng] = $near;
            $params['viewbox'] = implode(',', [round($lng - 0.25, 4), round($lat + 0.25, 4), round($lng + 0.25, 4), round($lat - 0.25, 4)]);
        }
        $body = $this->get('/search', $params);

        return collect(is_array($body) ? $body : [])->filter(fn ($row) => is_array($row) && isset($row['lat'], $row['lon']))
            ->map(fn (array $row) => self::place($row))->take($limit)->values()->all();
    }

    /**
     * Nominatim's place in our shape. The area is the most local name (neighbourhood, quarter, suburb, district,
     * village); the line shown to the guest is "street، area، city" without repeating a name ("قسم أول طنطا" already
     * says Tanta).
     *
     * @param  array<string,mixed>  $row
     * @return array{street:?string, house_number:?string, area:?string, city:?string, formatted_ar:string, lat:float, lng:float}
     */
    public static function place(array $row): array
    {
        $a = is_array($row['address'] ?? null) ? $row['address'] : [];
        $pick = function (array $keys) use ($a): ?string {
            foreach ($keys as $key) {
                $value = trim((string) ($a[$key] ?? ''));
                if ($value !== '') {
                    return mb_substr($value, 0, 120);
                }
            }

            return null;
        };
        $street = $pick(['road', 'pedestrian', 'footway', 'path', 'square']);
        $area = $pick(['neighbourhood', 'quarter', 'suburb', 'city_district', 'village', 'hamlet', 'residential']);
        $city = $pick(['city', 'town', 'municipality', 'county', 'state_district', 'state']);
        $name = trim((string) ($row['name'] ?? ''));

        $parts = [];
        foreach ([$street ?? ($name !== '' ? $name : null), $area, $city] as $part) {
            if ($part !== null && ! collect($parts)->contains(fn (string $p) => str_contains($p, $part))) {
                $parts[] = $part;
            }
        }

        return [
            'street' => $street,
            'house_number' => $pick(['house_number']),
            'area' => $area,
            'city' => $city,
            'formatted_ar' => implode('، ', $parts),
            'lat' => round((float) $row['lat'], 6),
            'lng' => round((float) $row['lon'], 6),
        ];
    }

    /**
     * One call to Nominatim when the platform's turn is free.
     *
     * @param  array<string,mixed>  $params
     *
     * @throws GeocoderUnavailable
     */
    private function get(string $path, array $params): mixed
    {
        $config = config('geocoding.nominatim');
        $this->takeTurn((int) $config['interval'], (int) $config['wait']);

        try {
            $response = Http::withHeaders(['User-Agent' => (string) $config['user_agent'], 'Accept-Language' => 'ar,en'])
                ->acceptJson()->timeout((int) $config['timeout'])->connectTimeout(3)
                ->get($config['url'].$path, $params + ['format' => 'jsonv2', 'accept-language' => 'ar,en']
                    + (filled($config['email']) ? ['email' => $config['email']] : []));
        } catch (ConnectionException $e) {
            throw new GeocoderUnavailable('Nominatim did not answer: '.$e->getMessage(), 0, $e);
        }

        if (! $response->successful()) {
            $this->report($response);

            throw new GeocoderUnavailable('Nominatim answered '.$response->status());
        }

        return $response->json();
    }

    /** @throws GeocoderUnavailable */
    private function takeTurn(int $interval, int $wait): void
    {
        // held one second longer than the interval: a lock kept in whole seconds (database, file) could otherwise
        // expire a moment after it was taken
        $lock = Cache::lock(self::SLOT, max(1, $interval) + 1);
        try {
            $got = $wait > 0 ? $lock->block($wait) : $lock->get();
        } catch (LockTimeoutException) {
            $got = false;
        }
        if (! $got) {
            throw new GeocoderUnavailable('Nominatim is busy: another call has this second');
        }
    }

    private function report(Response $response): void
    {
        // a 429 / 403 means we went past the policy: worth a line in the log, not an error page
        if (in_array($response->status(), [403, 429], true)) {
            Log::warning('Nominatim refused a call', ['status' => $response->status()]);
        }
    }
}
