<?php

namespace App\Services\Crm;

use App\Models\PlatformAdmin;
use App\Models\Prospect;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The map import of the sales team: restaurants, cafes, bakeries and sweets shops from OpenStreetMap (the Overpass
 * API, free, no key) inside a box on the map, or rows of a CSV file (a Google Maps export or a list typed by hand).
 * A place already in the system (same map id or same mobile) is skipped; every new one is scored (ProspectScore).
 */
final class PlaceImporter
{
    public const OVERPASS_URL = 'https://overpass-api.de/api/interpreter';

    /** Biggest box (degrees on each side) one import may ask for: about a city, not a governorate. */
    public const MAX_SPAN = 0.35;

    public const MAX_CSV_ROWS = 2000;

    /** OSM tag => our category */
    public const OSM_KINDS = [
        'amenity=restaurant' => 'restaurant',
        'amenity=fast_food' => 'restaurant',
        'amenity=cafe' => 'cafe',
        'amenity=ice_cream' => 'sweets',
        'shop=bakery' => 'bakery',
        'shop=pastry' => 'sweets',
        'shop=confectionery' => 'sweets',
    ];

    /** Ready-made areas: key => [arabic name, governorate, south, west, north, east]. */
    public const PRESETS = [
        'tanta' => ['طنطا', 'الغربية', 30.755, 30.955, 30.830, 31.050],
        'mahalla' => ['المحلة الكبرى', 'الغربية', 30.940, 31.135, 31.000, 31.200],
        'kafr_zayat' => ['كفر الزيات', 'الغربية', 30.805, 30.790, 30.845, 30.840],
        'zefta' => ['زفتى', 'الغربية', 30.695, 31.225, 30.730, 31.265],
        'santa' => ['السنطة', 'الغربية', 30.730, 31.110, 30.760, 31.150],
        'samanoud' => ['سمنود', 'الغربية', 30.945, 31.225, 30.975, 31.260],
        'basyoun' => ['بسيون', 'الغربية', 30.925, 30.800, 30.960, 30.840],
        'qutur' => ['قطور', 'الغربية', 30.955, 30.940, 30.990, 30.995],
        'shebin' => ['شبين الكوم', 'المنوفية', 30.535, 30.985, 30.580, 31.030],
        'kafr_sheikh' => ['كفر الشيخ', 'كفر الشيخ', 31.090, 30.920, 31.130, 30.970],
        'mansoura' => ['المنصورة', 'الدقهلية', 31.020, 31.340, 31.070, 31.420],
        'damanhour' => ['دمنهور', 'البحيرة', 31.020, 30.440, 31.060, 30.490],
    ];

    public function __construct(private ProspectScore $score) {}

    /**
     * Imports the places of $categories inside the box from OpenStreetMap.
     *
     * @param  array{0: float, 1: float, 2: float, 3: float}  $box  south, west, north, east
     * @param  list<string>  $categories
     * @return array{found: int, added: int, skipped: int}
     */
    public function fromMap(array $box, array $categories, string $city, string $governorate, ?PlatformAdmin $admin): array
    {
        [$s, $w, $n, $e] = $box;
        if ($n <= $s || $e <= $w || $n - $s > self::MAX_SPAN || $e - $w > self::MAX_SPAN) {
            throw new RuntimeException(__('Zoom in: the area is too big for one import.'));
        }
        $tags = array_keys(array_filter(self::OSM_KINDS, fn (string $category) => in_array($category, $categories, true)));
        if ($tags === []) {
            return ['found' => 0, 'added' => 0, 'skipped' => 0];
        }
        $bbox = implode(',', array_map(fn (float $v) => round($v, 5), [$s, $w, $n, $e]));
        $parts = '';
        foreach ($tags as $tag) {
            [$key, $value] = explode('=', $tag);
            $parts .= "node[\"$key\"=\"$value\"]($bbox);way[\"$key\"=\"$value\"]($bbox);";
        }
        try {
            $response = Http::withHeaders(['User-Agent' => 'EgHub-Sales/1.0 (+https://eghub.app; info@eghub.app)'])
                ->timeout(60)->asForm()->post(self::OVERPASS_URL, ['data' => "[out:json][timeout:50];($parts);out center tags;"]);
        } catch (ConnectionException) {
            throw new RuntimeException(__('The map service did not answer. Try again in a minute.'));
        }
        if (! $response->successful()) {
            throw new RuntimeException(__('The map service is busy. Try again in a minute.'));
        }

        $rows = [];
        foreach ((array) $response->json('elements', []) as $el) {
            $tags = (array) ($el['tags'] ?? []);
            $name = trim((string) ($tags['name:ar'] ?? $tags['name'] ?? $tags['name:en'] ?? ''));
            $lat = $el['lat'] ?? $el['center']['lat'] ?? null;
            $lng = $el['lon'] ?? $el['center']['lon'] ?? null;
            $category = $this->osmCategory($tags);
            if ($name === '' || $category === null || $lat === null || $lng === null) {
                continue;
            }
            $rows[] = [
                'external_ref' => 'osm:'.($el['type'] ?? 'node').'/'.($el['id'] ?? ''),
                'name' => $name, 'category' => $category,
                'phone' => (string) ($tags['phone'] ?? $tags['contact:phone'] ?? $tags['mobile'] ?? ''),
                'address' => trim(implode(' ', array_filter([$tags['addr:street'] ?? '', $tags['addr:housenumber'] ?? '']))),
                'area' => (string) ($tags['addr:suburb'] ?? $tags['addr:district'] ?? ''),
                'city' => (string) ($tags['addr:city'] ?? $city),
                'governorate' => $governorate, 'lat' => (float) $lat, 'lng' => (float) $lng,
            ];
        }

        return $this->store($rows, 'map', $admin);
    }

    /**
     * Imports a CSV file: a header row naming the columns (name, phone, address, category, lat, lng, city, area,
     * governorate; Google Maps exports' title / phoneNumber / latitude / longitude work too).
     *
     * @return array{found: int, added: int, skipped: int}
     */
    public function fromCsv(string $path, string $city, string $governorate, ?PlatformAdmin $admin): array
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new RuntimeException(__('The file could not be read.'));
        }
        $header = fgetcsv($handle, escape: '\\');
        if (! is_array($header)) {
            fclose($handle);
            throw new RuntimeException(__('The file is empty.'));
        }
        $aliases = [
            'name' => ['name', 'title', 'الاسم', 'اسم المكان'], 'phone' => ['phone', 'phonenumber', 'phone_number', 'mobile', 'التليفون', 'الموبايل', 'رقم التليفون'],
            'address' => ['address', 'street', 'العنوان'], 'category' => ['category', 'categoryname', 'type', 'النوع', 'التصنيف'],
            'lat' => ['lat', 'latitude'], 'lng' => ['lng', 'lon', 'long', 'longitude'], 'city' => ['city', 'المدينة'],
            'area' => ['area', 'neighborhood', 'المنطقة'], 'governorate' => ['governorate', 'state', 'المحافظة'],
        ];
        $columns = [];
        foreach ($header as $i => $title) {
            $title = mb_strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $title) ?? ''));
            foreach ($aliases as $field => $names) {
                if (! isset($columns[$field]) && in_array($title, $names, true)) {
                    $columns[$field] = $i;
                }
            }
        }
        if (! isset($columns['name'])) {
            fclose($handle);
            throw new RuntimeException(__('The file needs a "name" column.'));
        }
        $rows = [];
        while (($line = fgetcsv($handle, escape: '\\')) !== false && count($rows) < self::MAX_CSV_ROWS) {
            $get = fn (string $field): string => isset($columns[$field]) ? trim((string) ($line[$columns[$field]] ?? '')) : '';
            if ($get('name') === '') {
                continue;
            }
            $lat = is_numeric($get('lat')) ? (float) $get('lat') : null;
            $lng = is_numeric($get('lng')) ? (float) $get('lng') : null;
            $rows[] = [
                'external_ref' => 'csv:'.substr(sha1(mb_strtolower($get('name')).'|'.(normalize_phone($get('phone')) ?? '').'|'.$get('address')), 0, 32),
                'name' => mb_substr($get('name'), 0, 150), 'category' => $this->textCategory($get('category').' '.$get('name')),
                'phone' => $get('phone'), 'address' => $get('address'), 'area' => $get('area'),
                'city' => $get('city') ?: $city, 'governorate' => $get('governorate') ?: $governorate,
                'lat' => $lat !== null && abs($lat) <= 90 ? $lat : null, 'lng' => $lng !== null && abs($lng) <= 180 ? $lng : null,
            ];
        }
        fclose($handle);

        return $this->store($rows, 'manual', $admin);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{found: int, added: int, skipped: int}
     */
    private function store(array $rows, string $source, ?PlatformAdmin $admin): array
    {
        $refs = Prospect::query()->whereIn('external_ref', array_column($rows, 'external_ref'))->pluck('external_ref')->flip();
        $phones = array_values(array_filter(array_map(fn ($r) => normalize_phone((string) $r['phone']), $rows)));
        $known = Prospect::query()->whereIn('phone', $phones)->pluck('phone')
            ->merge(Prospect::query()->whereIn('phone2', $phones)->pluck('phone2'))->flip();
        $added = [];
        DB::transaction(function () use ($rows, $source, $admin, $refs, &$known, &$added) {
            foreach ($rows as $row) {
                $phone = normalize_phone((string) $row['phone']);
                if (isset($refs[$row['external_ref']]) || ($phone && isset($known[$phone]))) {
                    continue;
                }
                $prospect = Prospect::query()->create([
                    'name' => $row['name'], 'category' => $row['category'], 'phone' => $phone,
                    'address' => mb_substr((string) $row['address'], 0, 255), 'area' => mb_substr((string) $row['area'], 0, 100),
                    'city' => mb_substr((string) $row['city'], 0, 60), 'governorate' => mb_substr((string) $row['governorate'], 0, 60),
                    'lat' => $row['lat'], 'lng' => $row['lng'], 'source' => $source, 'external_ref' => $row['external_ref'],
                    'created_by' => $admin?->id,
                ]);
                $prospect->events()->create(['type' => 'import', 'admin_id' => $admin?->id, 'meta' => ['source' => $source]]);
                $refs[$row['external_ref']] = true;
                if ($phone) {
                    $known[$phone] = true;
                }
                $added[] = $prospect->id;
            }
        });
        if ($added !== []) {
            // the new places also make their neighbours worth more: rescore every open place
            $this->score->rescore();
        }

        return ['found' => count($rows), 'added' => count($added), 'skipped' => count($rows) - count($added)];
    }

    /** @param array<string, mixed> $tags */
    private function osmCategory(array $tags): ?string
    {
        foreach (self::OSM_KINDS as $tag => $category) {
            [$key, $value] = explode('=', $tag);
            if (($tags[$key] ?? null) === $value) {
                return $category;
            }
        }

        return null;
    }

    private function textCategory(string $text): string
    {
        $text = mb_strtolower($text);

        return match (true) {
            (bool) preg_match('/حلوا|حلويات|sweet|dessert|pastry|patisserie|كنافة|ice cream|آيس/u', $text) => 'sweets',
            (bool) preg_match('/مخبز|مخبوزات|فرن|bakery|bread/u', $text) => 'bakery',
            (bool) preg_match('/كافيه|كافي|قهوة|coffee|cafe|café/u', $text) => 'cafe',
            default => 'restaurant',
        };
    }
}
