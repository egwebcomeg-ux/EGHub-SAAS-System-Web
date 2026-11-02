<?php

namespace App\Services\Crm;

use App\Models\PlatformSetting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Finds places inside a box on Google Maps (Places API Text Search, key GOOGLE_PLACES_KEY). One search gives at most
 * 60 places, so a box that fills them is cut into four and searched again. Google bills every page, so one import and
 * one calendar month each have a limit of requests (config services.google_places): at a limit the search stops and
 * keeps what it found.
 */
final class GooglePlacesSearch
{
    public const URL = 'https://places.googleapis.com/v1/places:searchText';

    private const FIELDS = 'places.id,places.displayName,places.formattedAddress,places.location,places.primaryType,places.types,places.businessStatus,places.nationalPhoneNumber,places.internationalPhoneNumber,nextPageToken';

    private const PAGE_SIZE = 20;

    /** Google's most for one search over all of its pages. */
    private const MOST_PER_SEARCH = 60;

    /** A box this small (degrees, about 500 m) is not cut any more. */
    private const SMALLEST_SPAN = 0.005;

    /** Stops before the proxy in front of the site gives up on the request. */
    private const MOST_SECONDS = 75;

    private const USAGE_KEY = 'google_places_usage';

    /** Our category => what is searched for. */
    private const QUERIES = ['restaurant' => 'مطاعم', 'cafe' => 'كافيهات', 'bakery' => 'مخابز', 'sweets' => 'محلات حلويات'];

    /** Google's place type => our category. */
    private const TYPES = [
        'bakery' => 'bakery',
        'dessert_shop' => 'sweets', 'dessert_restaurant' => 'sweets', 'confectionery' => 'sweets', 'candy_store' => 'sweets', 'ice_cream_shop' => 'sweets', 'donut_shop' => 'sweets',
        'cafe' => 'cafe', 'coffee_shop' => 'cafe', 'tea_house' => 'cafe', 'cafeteria' => 'cafe',
        'restaurant' => 'restaurant', 'meal_takeaway' => 'restaurant', 'meal_delivery' => 'restaurant', 'food_court' => 'restaurant', 'diner' => 'restaurant',
        'sandwich_shop' => 'restaurant', 'fast_food_restaurant' => 'restaurant',
    ];

    /** Types that say nothing about the kind of place: many small Egyptian listings have only these. */
    private const GENERIC_TYPES = ['point_of_interest', 'establishment', 'food', 'store'];

    public static function isConfigured(): bool
    {
        return filled(config('services.google_places.key'));
    }

    /**
     * @param  array{0: float, 1: float, 2: float, 3: float}  $box  south, west, north, east
     * @param  list<string>  $categories
     * @return array{places: list<array<string, mixed>>, stopped: bool}
     */
    public function search(array $box, array $categories): array
    {
        $key = (string) config('services.google_places.key');
        if ($key === '') {
            throw new RuntimeException(__('Google import is not set up yet.'));
        }
        $queue = [];
        foreach (array_intersect_key(self::QUERIES, array_flip($categories)) as $category => $text) {
            $queue[] = [$category, $text, $box];
        }
        $places = [];
        $requests = 0;
        $startedAt = microtime(true);
        $stopped = false;
        while ($queue !== [] && ! $stopped) {
            [$category, $text, $area] = array_shift($queue);
            $pageToken = null;
            $found = 0;
            do {
                if ($requests >= (int) config('services.google_places.import_limit')
                    || microtime(true) - $startedAt > self::MOST_SECONDS || ! $this->useOneRequest()) {
                    $stopped = true;
                    break;
                }
                $requests++;
                $page = $this->page($key, $text, $area, $pageToken, $places !== []);
                if ($page === null) {
                    $stopped = true;
                    break;
                }
                foreach ((array) ($page['places'] ?? []) as $place) {
                    $found++;
                    $id = (string) ($place['id'] ?? '');
                    $row = $id === '' ? null : $this->row($place, $category);
                    if ($row !== null && in_array($row['category'], $categories, true)) {
                        $places[$id] = $row;
                    }
                }
                $pageToken = $page['nextPageToken'] ?? null;
            } while ($pageToken !== null);

            if (! $stopped && $found >= self::MOST_PER_SEARCH) {
                foreach ($this->quarters($area) as $quarter) {
                    $queue[] = [$category, $text, $quarter];
                }
            }
        }

        return ['places' => array_values($places), 'stopped' => $stopped];
    }

    /**
     * One page of results; null when Google stops answering after some places were already found.
     *
     * @param  array{0: float, 1: float, 2: float, 3: float}  $box
     * @return array<string, mixed>|null
     */
    private function page(string $key, string $text, array $box, ?string $pageToken, bool $hasPlaces): ?array
    {
        [$s, $w, $n, $e] = $box;
        try {
            $response = Http::withHeaders(['X-Goog-Api-Key' => $key, 'X-Goog-FieldMask' => self::FIELDS])
                ->timeout(20)->post(self::URL, array_filter([
                    'textQuery' => $text, 'languageCode' => 'ar', 'regionCode' => 'EG', 'pageSize' => self::PAGE_SIZE,
                    'locationRestriction' => ['rectangle' => ['low' => ['latitude' => $s, 'longitude' => $w], 'high' => ['latitude' => $n, 'longitude' => $e]]],
                    'pageToken' => $pageToken,
                ]));
        } catch (ConnectionException) {
            if ($hasPlaces) {
                return null;
            }
            throw new RuntimeException(__('Google Maps did not answer. Try again in a minute.'));
        }
        if ($response->successful()) {
            return (array) $response->json();
        }
        if (in_array($response->status(), [400, 401, 403], true)) {
            throw new RuntimeException(__('Google refused the key. Check that Places API (New) and billing are on for it.'));
        }
        if ($hasPlaces) {
            return null;
        }
        throw new RuntimeException(__('Google Maps did not answer. Try again in a minute.'));
    }

    /** Counts one request against this month's limit; false when the limit is reached. */
    private function useOneRequest(): bool
    {
        $month = local_at()->format('Y-m');
        $used = self::usedThisMonth();
        if ($used >= (int) config('services.google_places.monthly_limit')) {
            return false;
        }
        PlatformSetting::put(self::USAGE_KEY, $month.':'.($used + 1));

        return true;
    }

    public static function usedThisMonth(): int
    {
        [$month, $used] = array_pad(explode(':', (string) PlatformSetting::get(self::USAGE_KEY, '')), 2, 0);

        return $month === local_at()->format('Y-m') ? (int) $used : 0;
    }

    /**
     * @param  array<string, mixed>  $place
     * @return array<string, mixed>|null
     */
    private function row(array $place, string $searchedCategory): ?array
    {
        $name = trim((string) ($place['displayName']['text'] ?? ''));
        $category = $this->category($place, $searchedCategory);
        if ($name === '' || $category === null || ($place['businessStatus'] ?? '') === 'CLOSED_PERMANENTLY') {
            return null;
        }

        return [
            'external_ref' => 'google:'.$place['id'],
            'name' => mb_substr($name, 0, 150), 'category' => $category,
            'phone' => (string) ($place['nationalPhoneNumber'] ?? $place['internationalPhoneNumber'] ?? ''),
            'address' => (string) ($place['formattedAddress'] ?? ''), 'area' => '',
            'lat' => isset($place['location']['latitude']) ? (float) $place['location']['latitude'] : null,
            'lng' => isset($place['location']['longitude']) ? (float) $place['location']['longitude'] : null,
        ];
    }

    /**
     * The kind of a place from its types. A place with only generic types is what it was searched for; one with other
     * types (a pharmacy that matched the words) is not ours.
     *
     * @param  array<string, mixed>  $place
     */
    private function category(array $place, string $searchedCategory): ?string
    {
        $types = array_values(array_unique(array_filter([(string) ($place['primaryType'] ?? ''), ...(array) ($place['types'] ?? [])])));
        foreach ($types as $type) {
            if (isset(self::TYPES[$type])) {
                return self::TYPES[$type];
            }
            if (str_ends_with($type, '_restaurant')) {
                return 'restaurant';
            }
        }

        return array_diff($types, self::GENERIC_TYPES) === [] ? $searchedCategory : null;
    }

    /**
     * @param  array{0: float, 1: float, 2: float, 3: float}  $box
     * @return list<array{0: float, 1: float, 2: float, 3: float}>
     */
    private function quarters(array $box): array
    {
        [$s, $w, $n, $e] = $box;
        if ($n - $s <= self::SMALLEST_SPAN || $e - $w <= self::SMALLEST_SPAN) {
            return [];
        }
        $middleLat = ($s + $n) / 2;
        $middleLng = ($w + $e) / 2;

        return [[$s, $w, $middleLat, $middleLng], [$s, $middleLng, $middleLat, $e], [$middleLat, $w, $n, $middleLng], [$middleLat, $middleLng, $n, $e]];
    }
}
