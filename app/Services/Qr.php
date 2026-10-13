<?php

namespace App\Services;

use BaconQrCode\Renderer\Color\Rgb;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\Fill;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/** QR codes rendered here as SVG (no network, prints sharp at any size). */
final class Qr
{
    /** @param string $color hex like #2C5B80 */
    public static function svg(string $data, int $size = 400, string $color = '#000000', int $margin = 1): string
    {
        [$r, $g, $b] = sscanf(ltrim(is_hex_color($color) ? $color : '#000000', '#'), '%02x%02x%02x');
        $style = new RendererStyle($size, $margin, null, null, Fill::uniformColor(new Rgb(255, 255, 255), new Rgb($r, $g, $b)));
        $svg = (new Writer(new ImageRenderer($style, new SvgImageBackEnd())))->writeString($data);
        return (string) preg_replace('/^<\?xml[^>]*>\s*/', '', $svg);
    }

    /** data: URL for an <img>. */
    public static function dataUrl(string $data, int $size = 400, string $color = '#000000'): string
    {
        return 'data:image/svg+xml;base64,' . base64_encode(self::svg($data, $size, $color));
    }
}
