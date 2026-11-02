<?php

namespace App\Services\Crm;

use App\Models\PlatformAdmin;
use App\Models\Prospect;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The map import of the sales team: restaurants, cafes, bakeries and sweets shops inside a box on the map from Google
 * Maps (GooglePlacesSearch, needs a key) or OpenStreetMap (the Overpass API, free, no key, few places in Egypt), or
 * rows of a CSV file (a Google Maps export or a list typed by hand).
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
        'area_01' => ['منوف', 'المنوفية', 30.427, 30.894, 30.507, 30.974],
        'area_02' => ['أشمون', 'المنوفية', 30.256, 30.936, 30.336, 31.016],
        'area_03' => ['قويسنا', 'المنوفية', 30.506, 31.094, 30.586, 31.174],
        'area_04' => ['تلا', 'المنوفية', 30.64, 30.903, 30.72, 30.983],
        'area_05' => ['الباجور', 'المنوفية', 30.409, 31.012, 30.459, 31.062],
        'area_06' => ['بركة السبع', 'المنوفية', 30.602, 31.059, 30.652, 31.109],
        'area_07' => ['مدينة السادات', 'المنوفية', 30.267, 30.502, 30.467, 30.702],
        'area_08' => ['الشهداء', 'المنوفية', 30.558, 30.855, 30.638, 30.935],
        'area_09' => ['دسوق', 'كفر الشيخ', 31.113, 30.635, 31.145, 30.665],
        'area_10' => ['فوه', 'كفر الشيخ', 31.18, 30.524, 31.23, 30.574],
        'area_11' => ['بيلا', 'كفر الشيخ', 31.133, 31.181, 31.213, 31.261],
        'area_12' => ['الحامول', 'كفر الشيخ', 31.281, 31.131, 31.38, 31.204],
        'area_13' => ['سيدي سالم', 'كفر الشيخ', 31.232, 30.747, 31.312, 30.827],
        'area_14' => ['بلطيم', 'كفر الشيخ', 31.534, 31.07, 31.574, 31.11],
        'area_15' => ['قلين', 'كفر الشيخ', 31.023, 30.829, 31.073, 30.879],
        'area_16' => ['طلخا', 'الدقهلية', 31.012, 31.333, 31.092, 31.413],
        'area_17' => ['ميت غمر', 'الدقهلية', 30.685, 31.25, 30.74, 31.292],
        'area_18' => ['السنبلاوين', 'الدقهلية', 30.841, 31.422, 30.921, 31.502],
        'area_19' => ['دكرنس', 'الدقهلية', 31.048, 31.555, 31.128, 31.635],
        'area_20' => ['بلقاس', 'الدقهلية', 31.193, 31.337, 31.233, 31.378],
        'area_21' => ['المنزلة', 'الدقهلية', 31.119, 31.896, 31.199, 31.976],
        'area_22' => ['شربين', 'الدقهلية', 31.167, 31.502, 31.217, 31.552],
        'area_23' => ['أجا', 'الدقهلية', 30.92, 31.263, 30.953, 31.32],
        'area_24' => ['كفر الدوار', 'البحيرة', 31.093, 30.094, 31.173, 30.174],
        'area_25' => ['رشيد', 'البحيرة', 31.301, 30.318, 31.501, 30.518],
        'area_26' => ['إيتاي البارود', 'البحيرة', 30.862, 30.64, 30.912, 30.69],
        'area_27' => ['أبو حمص', 'البحيرة', 31.061, 30.273, 31.141, 30.353],
        'area_28' => ['حوش عيسى', 'البحيرة', 30.873, 30.251, 30.953, 30.331],
        'area_29' => ['كوم حمادة', 'البحيرة', 30.719, 30.66, 30.799, 30.74],
        'area_30' => ['وادي النطرون', 'البحيرة', 30.338, 30.315, 30.418, 30.395],
        'area_31' => ['بنها', 'القليوبية', 30.362, 31.084, 30.562, 31.284],
        'area_32' => ['شبرا الخيمة', 'القليوبية', 30.029, 31.142, 30.229, 31.342],
        'area_33' => ['قليوب', 'القليوبية', 30.139, 31.166, 30.219, 31.246],
        'area_34' => ['الخانكة', 'القليوبية', 30.188, 31.341, 30.238, 31.391],
        'area_35' => ['العبور', 'القليوبية', 30.091, 31.351, 30.291, 31.551],
        'area_36' => ['طوخ', 'القليوبية', 30.314, 31.161, 30.394, 31.241],
        'area_37' => ['قها', 'القليوبية', 30.241, 31.166, 30.321, 31.246],
        'area_38' => ['كفر شكر', 'القليوبية', 30.531, 31.25, 30.558, 31.29],
        'area_39' => ['الزقازيق', 'الشرقية', 30.485, 31.404, 30.685, 31.604],
        'area_40' => ['العاشر من رمضان', 'الشرقية', 30.268, 31.681, 30.353, 31.786],
        'area_41' => ['بلبيس', 'الشرقية', 30.319, 31.465, 30.519, 31.665],
        'area_42' => ['منيا القمح', 'الشرقية', 30.506, 31.324, 30.532, 31.366],
        'area_43' => ['أبو حماد', 'الشرقية', 30.521, 31.666, 30.549, 31.691],
        'area_44' => ['فاقوس', 'الشرقية', 30.626, 31.698, 30.826, 31.898],
        'area_45' => ['ههيا', 'الشرقية', 30.65, 31.569, 30.69, 31.609],
        'area_46' => ['ديرب نجم', 'الشرقية', 30.651, 31.359, 30.851, 31.559],
        'area_47' => ['دمياط', 'دمياط', 31.317, 31.721, 31.517, 31.921],
        'area_48' => ['دمياط الجديدة', 'دمياط', 31.336, 31.578, 31.536, 31.778],
        'area_49' => ['رأس البر', 'دمياط', 31.473, 31.794, 31.553, 31.874],
        'area_50' => ['فارسكور', 'دمياط', 31.313, 31.703, 31.358, 31.733],
        'area_51' => ['سيدي جابر', 'الإسكندرية', 31.199, 29.912, 31.249, 29.962],
        'area_52' => ['سموحة', 'الإسكندرية', 31.191, 29.917, 31.241, 29.967],
        'area_53' => ['المنتزه', 'الإسكندرية', 31.26, 29.99, 31.31, 30.04],
        'area_54' => ['العجمي', 'الإسكندرية', 31.121, 29.765, 31.161, 29.805],
        'area_55' => ['محرم بك', 'الإسكندرية', 31.169, 29.897, 31.219, 29.947],
        'area_56' => ['ميامي', 'الإسكندرية', 31.241, 29.969, 31.291, 30.019],
        'area_57' => ['مدينة نصر', 'القاهرة', 30.032, 31.322, 30.072, 31.362],
        'area_58' => ['مصر الجديدة', 'القاهرة', 30.081, 31.313, 30.121, 31.353],
        'area_59' => ['المعادي', 'القاهرة', 29.935, 31.238, 29.985, 31.288],
        'area_60' => ['التجمع الخامس', 'القاهرة', 29.991, 31.403, 30.016, 31.446],
        'area_61' => ['وسط البلد', 'القاهرة', 30.03, 31.22, 30.07, 31.26],
        'area_62' => ['شبرا', 'القاهرة', 30.053, 31.218, 30.103, 31.268],
        'area_63' => ['حلوان', 'القاهرة', 29.75, 31.233, 29.95, 31.433],
        'area_64' => ['عين شمس', 'القاهرة', 30.11, 31.297, 30.15, 31.337],
        'area_65' => ['المقطم', 'القاهرة', 29.994, 31.27, 30.039, 31.295],
        'area_66' => ['مدينتي', 'القاهرة', 30.066, 31.589, 30.117, 31.699],
        'area_67' => ['الدقي', 'الجيزة', 30.019, 31.193, 30.059, 31.233],
        'area_68' => ['المهندسين', 'الجيزة', 30.03, 31.18, 30.07, 31.22],
        'area_69' => ['الهرم', 'الجيزة', 29.97, 31.12, 30.02, 31.19],
        'area_70' => ['فيصل', 'الجيزة', 29.992, 31.179, 30.042, 31.229],
        'area_71' => ['مدينة 6 أكتوبر', 'الجيزة', 29.91, 30.88, 30.02, 31.0],
        'area_72' => ['الشيخ زايد', 'الجيزة', 29.948, 30.883, 30.148, 31.083],
        'area_73' => ['إمبابة', 'الجيزة', 30.069, 31.196, 30.109, 31.236],
        'area_74' => ['بورسعيد', 'بورسعيد', 31.163, 32.206, 31.363, 32.406],
        'area_75' => ['الإسماعيلية', 'الإسماعيلية', 30.504, 32.177, 30.704, 32.377],
        'area_76' => ['السويس', 'السويس', 29.874, 32.437, 30.074, 32.637],
        'area_77' => ['الفيوم', 'الفيوم', 29.207, 30.74, 29.407, 30.94],
        'area_78' => ['بني سويف', 'بني سويف', 28.973, 30.998, 29.173, 31.198],
        'area_79' => ['المنيا', 'المنيا', 27.989, 30.657, 28.189, 30.857],
        'area_80' => ['ملوي', 'المنيا', 27.692, 30.801, 27.772, 30.881],
        'area_81' => ['أسيوط', 'أسيوط', 27.157, 31.147, 27.217, 31.212],
        'area_82' => ['سوهاج', 'سوهاج', 26.448, 31.599, 26.648, 31.799],
        'area_83' => ['قنا', 'قنا', 26.059, 32.616, 26.259, 32.816],
        'area_84' => ['الأقصر', 'الأقصر', 25.602, 32.547, 25.802, 32.747],
        'area_85' => ['أسوان', 'أسوان', 23.991, 32.797, 24.191, 32.997],
        'area_86' => ['الغردقة', 'البحر الأحمر', 27.123, 33.731, 27.323, 33.931],
        'area_87' => ['شرم الشيخ', 'جنوب سيناء', 27.764, 34.195, 27.964, 34.395],
        'area_88' => ['دهب', 'جنوب سيناء', 28.396, 34.415, 28.596, 34.615],
        'area_89' => ['العريش', 'شمال سيناء', 31.127, 33.806, 31.177, 33.856],
        'area_90' => ['مرسى مطروح', 'مطروح', 31.253, 27.14, 31.453, 27.34],
    ];

    public function __construct(private ProspectScore $score, private GooglePlacesSearch $google) {}

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
     * Imports the places of $categories inside the box from Google Maps.
     *
     * @param  array{0: float, 1: float, 2: float, 3: float}  $box  south, west, north, east
     * @param  list<string>  $categories
     * @return array{found: int, added: int, skipped: int, stopped: bool}
     */
    public function fromGoogle(array $box, array $categories, string $city, string $governorate, ?PlatformAdmin $admin): array
    {
        [$s, $w, $n, $e] = $box;
        if ($n <= $s || $e <= $w || $n - $s > self::MAX_SPAN || $e - $w > self::MAX_SPAN) {
            throw new RuntimeException(__('Zoom in: the area is too big for one import.'));
        }
        $result = $this->google->search($box, $categories);
        $rows = array_map(fn (array $row) => [...$row, 'city' => $city, 'governorate' => $governorate], $result['places']);

        return [...$this->store($rows, 'google', $admin), 'stopped' => $result['stopped']];
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
