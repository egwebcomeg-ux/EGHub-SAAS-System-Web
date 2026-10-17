<?php

namespace App\Services\Pwa;

use App\Services\Settings;
use Illuminate\Support\Facades\Storage;

/**
 * Home-screen icons of a restaurant's menu (manifest icons, apple-touch-icon), made with GD on the fly: the uploaded
 * logo on the menu's background color, else the first letter of the name on the brand color. Full-bleed squares
 * with the artwork inside the middle 70%, so they also work as "maskable" icons (Android cuts a circle out).
 */
final class PwaIcons
{
    public const SIZES = [192, 512];

    /** TrueType fonts for the letter icon, first one found wins (GD cannot read the woff2 files Vite ships). */
    private const FONTS = [
        'C:/Windows/Fonts/segoeuib.ttf', 'C:/Windows/Fonts/arialbd.ttf', 'C:/Windows/Fonts/segoeui.ttf', 'C:/Windows/Fonts/arial.ttf',
        '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf', '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
        '/usr/share/fonts/dejavu/DejaVuSans-Bold.ttf', '/usr/share/fonts/dejavu/DejaVuSans.ttf', '/usr/share/fonts/TTF/DejaVuSans.ttf',
        '/usr/share/fonts/truetype/noto/NotoSansArabic-Bold.ttf', '/usr/share/fonts/truetype/noto/NotoSans-Bold.ttf',
    ];

    public static function available(): bool
    {
        return extension_loaded('gd') && function_exists('imagecreatetruecolor') && function_exists('imagepng');
    }

    /** PNG bytes of the current restaurant's icon at $size × $size. */
    public function tenant(int $size): string
    {
        $s = Settings::all();
        $bg = is_hex_color($s['color_bg']) ? $s['color_bg'] : '#FFFFFF';
        $primary = is_hex_color($s['color_primary']) ? $s['color_primary'] : '#F26522';

        if ($s['logo'] !== '') {
            $path = Storage::disk('public')->path($s['logo']);
            if (is_file($path) && ($png = $this->fromLogo($path, $size, $bg)) !== null) {
                return $png;
            }
        }
        $name = trim(tr($s['brand_name'], $s['brand_name_en'])) ?: (string) tenant()->name;

        return $this->letter(mb_strtoupper(mb_substr($name, 0, 1)), $size, $primary);
    }

    /** What the icon depends on (ETag / cache key). */
    public function fingerprint(): string
    {
        $s = Settings::all();

        return md5(implode('|', [$s['logo'], $s['color_bg'], $s['color_primary'], $s['brand_name'], $s['brand_name_en'], (string) tenant()->name]));
    }

    private function fromLogo(string $path, int $size, string $bg): ?string
    {
        $src = @imagecreatefromstring((string) file_get_contents($path));
        if (! $src) {
            return null;
        }
        $canvas = $this->canvas($size, $bg);
        $box = (int) round($size * 0.7);
        $w = imagesx($src);
        $h = imagesy($src);
        $scale = min($box / $w, $box / $h);
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));
        imagecopyresampled($canvas, $src, intdiv($size - $nw, 2), intdiv($size - $nh, 2), 0, 0, $nw, $nh, $w, $h);
        imagedestroy($src);

        return $this->png($canvas);
    }

    private function letter(string $letter, int $size, string $bg): string
    {
        $canvas = $this->canvas($size, $bg);
        $white = imagecolorallocate($canvas, 255, 255, 255);
        $font = $this->font();
        if ($font !== null && function_exists('imagettftext') && $letter !== '') {
            $points = $size * 0.42;
            $box = imagettfbbox($points, 0, $font, $letter);
            if ($box !== false) {
                $tw = $box[2] - $box[0];
                $th = $box[1] - $box[7];
                imagettftext($canvas, $points, 0, (int) round(($size - $tw) / 2 - $box[0]), (int) round(($size - $th) / 2 - $box[7]), $white, $font, $letter);

                return $this->png($canvas);
            }
        }
        // no TrueType font on this server: a plain white plate keeps the brand color recognisable
        imagefilledellipse($canvas, intdiv($size, 2), intdiv($size, 2), (int) ($size * 0.5), (int) ($size * 0.5), $white);

        return $this->png($canvas);
    }

    private function canvas(int $size, string $hex): \GdImage
    {
        [$r, $g, $b] = sscanf(ltrim($hex, '#'), '%02x%02x%02x');
        $img = imagecreatetruecolor($size, $size);
        imagefill($img, 0, 0, imagecolorallocate($img, $r, $g, $b));

        return $img;
    }

    private function png(\GdImage $img): string
    {
        ob_start();
        imagepng($img, null, 6);
        imagedestroy($img);

        return (string) ob_get_clean();
    }

    private function font(): ?string
    {
        $own = resource_path('fonts/pwa-icon.ttf');
        foreach (array_merge([$own], self::FONTS) as $file) {
            if (is_file($file)) {
                return $file;
            }
        }

        return null;
    }
}
