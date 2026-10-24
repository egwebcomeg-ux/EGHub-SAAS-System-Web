<?php

namespace App\Services\Seo;

use App\Models\PlatformSetting;
use App\Services\BrandAssets;
use App\Services\ImageService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The second logo strip on the eghub.app home page: real clients of EgWeb (the company behind EgHub), titled
 * "Restaurants and cafés that trust EgWeb". It never says they use EgHub: that is App\Services\Seo\SiteShowcase.
 *
 * The list is edited in admin › branding and kept as JSON in the platform setting SETTING_KEY. Until it was saved
 * once, the bundled set in public/img/egweb-clients (DEFAULTS, taken from egweb.com.eg) is used: food and
 * hospitality brands first and shown, the others listed but hidden until the owner switches them on. Uploaded logos
 * live on the public disk under platform/clients (raster re-encoded to WebP, SVG rebuilt by BrandAssets::sanitize).
 * The strip shows only from MIN_LOGOS active logos up.
 */
final class EgwebClients
{
    public const SETTING_KEY = 'egweb_clients';

    public const MIN_LOGOS = 4;

    public const MAX_LOGOS = 40;

    /** Where uploaded and imported logos are stored on the public disk. */
    public const DIR = 'platform/clients';

    /** Where the bundled default logos are, under public/. */
    public const BUNDLED_DIR = 'img/egweb-clients';

    /** key => [name, link, shown, width, height]; the order is the strip's order. */
    public const DEFAULTS = [
        'gold-sushi-club' => ['Gold Sushi Club', 'https://www.goldsushiclub.com', true, 480, 56],
        'desoky-soda' => ['Desoky & Soda', '', true, 349, 139],
        'filetto' => ['Filetto', '', true, 181, 160],
        'vasko' => ['VASKO Coffee', 'https://www.vasko.coffee', true, 397, 100],
        'roseraie' => ['Roseraie', 'https://roseraie-co.com', false, 480, 107],
        'delta-misr' => ['Delpack (Delta Misr)', 'https://delpack-eg.com', false, 480, 119],
        'sevengear' => ['SevenGear', 'https://sevengear.net', false, 480, 95],
        'mega' => ['Mega', 'https://mega.com.eg', false, 122, 140],
        'myway' => ['MyWay', '', false, 480, 147],
        'ibn-sina-hospital' => ['Ibn Sina Hospital', '', false, 140, 160],
        'medic8' => ['Medic8', '', false, 80, 160],
        'elfateh-panels' => ['Elfateh Panels', '', false, 167, 81],
        'clawstitch' => ['ClawStitch', 'https://clawstich.shop', false, 127, 91],
    ];

    public const MAX_SVG_BYTES = 500_000;

    public function __construct(private readonly ImageService $images) {}

    /**
     * Every logo in the admin's order, hidden ones included.
     *
     * @return list<array{key: string, name: string, path: string, url: string, active: bool, width: int, height: int}>
     */
    public static function all(): array
    {
        $saved = PlatformSetting::get(self::SETTING_KEY);
        if ($saved === null) {
            return self::defaults();
        }
        $list = json_decode($saved, true);

        return is_array($list) ? array_values(array_filter(array_map(self::normalize(...), $list))) : self::defaults();
    }

    /**
     * The logos of the home page strip; [] under MIN_LOGOS active ones.
     *
     * @return list<array{name: string, logo: string, url: string, width: int, height: int}>
     */
    public static function strip(): array
    {
        $logos = self::active();

        return count($logos) >= self::MIN_LOGOS ? array_slice($logos, 0, self::MAX_LOGOS) : [];
    }

    /**
     * Every logo the admin switched on, however few (the home page strip joins them to the EgHub places).
     *
     * @return list<array{name: string, logo: string, url: string, width: int, height: int}>
     */
    public static function active(): array
    {
        return array_map(fn (array $c) => [
            'name' => $c['name'], 'logo' => self::src($c['path']), 'url' => $c['url'], 'width' => $c['width'], 'height' => $c['height'],
        ], array_values(array_filter(self::all(), fn (array $c) => $c['active'])));
    }

    /** @param  list<array<string, mixed>>  $list */
    public static function save(array $list): void
    {
        PlatformSetting::put(self::SETTING_KEY, json_encode(array_values($list), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** Address of a logo: bundled ones from public/, the others from /storage. */
    public static function src(string $path): string
    {
        return str_starts_with($path, self::BUNDLED_DIR.'/') ? '/'.$path : '/storage/'.$path;
    }

    /**
     * The bundled set.
     *
     * @return list<array{key: string, name: string, path: string, url: string, active: bool, width: int, height: int}>
     */
    public static function defaults(): array
    {
        $out = [];
        foreach (self::DEFAULTS as $key => [$name, $url, $active, $width, $height]) {
            $out[] = ['key' => $key, 'name' => $name, 'path' => self::BUNDLED_DIR."/$key.webp", 'url' => $url,
                'active' => $active, 'width' => $width, 'height' => $height];
        }

        return $out;
    }

    /**
     * Stores an uploaded logo (PNG / JPEG / WebP re-encoded to WebP, SVG sanitised) and returns where it went.
     *
     * @return array{path: string, width: int, height: int}
     */
    public function storeImage(UploadedFile $file, ?string $name = null): array
    {
        if (BrandAssets::looksLikeSvg($file)) {
            if ($file->getSize() > self::MAX_SVG_BYTES) {
                throw ValidationException::withMessages(['image' => __('The SVG file is too big (500 KB at most).')]);
            }
            $clean = BrandAssets::sanitize((string) file_get_contents($file->getRealPath()));
            if ($clean === null) {
                throw ValidationException::withMessages(['image' => __('This SVG file could not be read safely. Upload a PNG instead.')]);
            }
            $path = self::DIR.'/'.($name ?? (string) Str::uuid()).'.svg';
            Storage::disk('public')->put($path, $clean);

            return ['path' => $path] + self::svgSize($clean);
        }

        $path = $this->images->store($file, 'client_logo');
        if ($name !== null) {
            $named = self::DIR."/$name.webp";
            Storage::disk('public')->delete($named);
            Storage::disk('public')->move($path, $named);
            $path = $named;
        }
        $size = @getimagesizefromstring((string) Storage::disk('public')->get($path));

        return ['path' => $path, 'width' => (int) ($size[0] ?? 160), 'height' => (int) ($size[1] ?? 64)];
    }

    /** Removes a stored logo file; bundled files and anything outside DIR are left alone. */
    public function deleteImage(string $path): void
    {
        if (str_starts_with($path, self::DIR.'/') && ! str_contains($path, '..')) {
            Storage::disk('public')->delete($path);
        }
    }

    /** @return array{width: int, height: int} from the viewBox (or width/height), 160 × 64 when neither is readable */
    private static function svgSize(string $svg): array
    {
        if (preg_match('/viewBox\s*=\s*["\']\s*[-\d.]+[\s,]+[-\d.]+[\s,]+([\d.]+)[\s,]+([\d.]+)/i', $svg, $m) && (float) $m[1] > 0 && (float) $m[2] > 0) {
            return ['width' => max(1, (int) round((float) $m[1])), 'height' => max(1, (int) round((float) $m[2]))];
        }

        return ['width' => 160, 'height' => 64];
    }

    /** @return array{key: string, name: string, path: string, url: string, active: bool, width: int, height: int}|null */
    private static function normalize(mixed $c): ?array
    {
        if (! is_array($c) || ! isset($c['key'], $c['path']) || ! is_string($c['path']) || str_contains($c['path'], '..')) {
            return null;
        }

        return [
            'key' => (string) $c['key'], 'name' => (string) ($c['name'] ?? ''), 'path' => $c['path'], 'url' => (string) ($c['url'] ?? ''),
            'active' => (bool) ($c['active'] ?? false), 'width' => max(1, (int) ($c['width'] ?? 160)), 'height' => max(1, (int) ($c['height'] ?? 64)),
        ];
    }
}
