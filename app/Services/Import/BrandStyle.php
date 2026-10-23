<?php

namespace App\Services\Import;

use App\Models\MenuItem;
use App\Services\Audit;
use App\Services\Entitlements;
use App\Services\ImageService;
use App\Services\Settings;
use App\Support\MenuFonts;
use Illuminate\Support\Facades\Storage;

/**
 * The look of the restaurant as the AI saw it on the uploaded menu (colours, heading font, language, where the logo
 * sits), checked strictly before it is shown on the review page, and applied with the import when the owner keeps
 * "apply this style" ticked. Colours, font and logo are the `full_branding` plan feature, same as in the settings.
 *
 *   ['primary', 'secondary', 'background', 'text' => '#RRGGBB'|null, 'font_style' => one of FONT_STYLES|null,
 *    'font' => MenuFonts family|null, 'language' => 'ar'|'en'|'both'|null,
 *    'logo_box' => ['page' => 1.., 'x', 'y', 'w', 'h' => fractions 0..1 of the page]|null, 'logo' => stored path|null]
 */
final class BrandStyle
{
    public const FEATURE = 'full_branding';

    /** Templates whose header keeps its own colour whatever the brand (menu/themes/styles.blade.php). */
    public const OWN_HEADER_THEMES = ['cafe', 'bistro', 'fastfood', 'oriental'];

    public const FONT_STYLES = ['serif', 'sans', 'rounded', 'script', 'display', 'arabic_naskh', 'arabic_kufi'];

    public const LANGUAGES = ['ar', 'en', 'both'];

    public const COLORS = ['primary', 'secondary', 'background', 'text'];

    /** The part of MenuDraft::schema() the AI fills; every field always present (strict structured output), "" when unknown. */
    public static function schema(): array
    {
        $fraction = ['type' => 'number'];

        return ['type' => 'object', 'properties' => [
            'primary' => ['type' => 'string'], 'secondary' => ['type' => 'string'],
            'background' => ['type' => 'string'], 'text' => ['type' => 'string'],
            'heading_font_style' => ['type' => 'string', 'enum' => [...self::FONT_STYLES, '']],
            'language' => ['type' => 'string', 'enum' => self::LANGUAGES],
            'logo_box' => ['type' => 'object', 'properties' => [
                'found' => ['type' => 'boolean'], 'page' => ['type' => 'integer'],
                'x' => $fraction, 'y' => $fraction, 'w' => $fraction, 'h' => $fraction,
            ], 'required' => ['found', 'page', 'x', 'y', 'w', 'h'], 'additionalProperties' => false],
        ], 'required' => ['primary', 'secondary', 'background', 'text', 'heading_font_style', 'language', 'logo_box'], 'additionalProperties' => false];
    }

    /**
     * Never trusts the model: colours must be hex, enums must match, the logo box must be a sane part of a page.
     *
     * @return array{primary:?string, secondary:?string, background:?string, text:?string, font_style:?string, font:?string, language:?string, logo_box:?array{page:int, x:float, y:float, w:float, h:float}, logo:?string}|null
     */
    public static function normalize(mixed $raw): ?array
    {
        if (! is_array($raw)) {
            return null;
        }
        $out = [];
        foreach (self::COLORS as $key) {
            $out[$key] = self::hex($raw[$key] ?? null);
        }
        $style = $raw['heading_font_style'] ?? $raw['font_style'] ?? null;
        $out['font_style'] = is_string($style) && in_array($style, self::FONT_STYLES, true) ? $style : null;
        $language = $raw['language'] ?? null;
        $out['language'] = is_string($language) && in_array($language, self::LANGUAGES, true) ? $language : null;
        $out['font'] = $out['font_style'] !== null ? MenuFonts::forStyle($out['font_style'], $out['language']) : null;
        $out['logo_box'] = self::box($raw['logo_box'] ?? null);
        // a logo already cut out by the job (a path inside this restaurant's folder only)
        $logo = $raw['logo'] ?? null;
        $out['logo'] = is_string($logo) && self::ownPath($logo) && preg_match('#^[\w./-]+\.webp$#', $logo) ? $logo : null;

        $useful = array_filter($out, fn ($v, $k) => $v !== null && $k !== 'logo_box', ARRAY_FILTER_USE_BOTH);

        return $useful === [] && $out['logo_box'] === null ? null : $out;
    }

    /** "#abc", "aabbcc", "#AABBCC" → "#AABBCC"; anything else → null. */
    public static function hex(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^#?([0-9a-f]{3}|[0-9a-f]{6})$/i', trim($value), $m)) {
            return null;
        }
        $hex = strlen($m[1]) === 3 ? preg_replace('/(.)/', '$1$1', $m[1]) : $m[1];

        return '#'.strtoupper((string) $hex);
    }

    /** WCAG relative luminance of a #RRGGBB colour (0 black … 1 white). */
    public static function luminance(string $hex): float
    {
        [$r, $g, $b] = array_map(function (int $c): float {
            $c /= 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, sscanf(ltrim($hex, '#'), '%02x%02x%02x'));

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }

    /** Whether the plan lets the restaurant pick its colours, font and logo. */
    public static function allowed(): bool
    {
        return Entitlements::current()->allows(self::FEATURE);
    }

    /**
     * The settings this style would change, already made safe for the guest menu: the header (white text on
     * `color_secondary`) stays dark enough to read, the page background stays light (the light templates draw dark
     * text on it), the main colour is never near-white.
     *
     * @return array<string, string>
     */
    public static function settingsFor(array $brand, bool $replaceLogo): array
    {
        $values = [];
        $primary = $brand['primary'] ?? null;
        if ($primary !== null && self::luminance($primary) < 0.8) {
            $values['color_primary'] = $primary;
        }
        foreach ([$brand['secondary'] ?? null, $brand['text'] ?? null, $primary] as $candidate) {
            if ($candidate !== null && self::luminance($candidate) <= 0.3) {
                $values['color_secondary'] = $candidate;
                break;
            }
        }
        $background = $brand['background'] ?? null;
        if ($background !== null && self::luminance($background) >= 0.6) {
            $values['color_bg'] = $background;
        }
        if (($brand['font'] ?? null) !== null && in_array($brand['font'], MenuFonts::NAMES, true)) {
            $values['font'] = $brand['font'];
        }
        if (($brand['language'] ?? null) === 'en') {
            $values['default_locale'] = 'en';
            $values['english_enabled'] = '1';
        }
        $logo = $brand['logo'] ?? null;
        if ($logo !== null && self::ownPath($logo) && Storage::disk('public')->exists($logo) && ($replaceLogo || (string) Settings::get('logo') === '')) {
            $values['logo'] = $logo;
        }

        return $values;
    }

    /**
     * Applies the style (only with the plan feature) and logs it; the logo the job cut out is removed when it was
     * not used, so unused candidates do not pile up in the restaurant's folder.
     *
     * @return list<string> the settings that changed
     */
    public static function apply(array $brand, bool $replaceLogo, string $subject = ''): array
    {
        $values = self::allowed() ? self::settingsFor($brand, $replaceLogo) : [];
        // templates that paint their own header colour (resources/views/menu/themes/styles.blade.php) would hide the
        // brand's colours: the file's style moves the menu to the classic template, which follows them
        if (isset($values['color_secondary']) && in_array((string) Settings::get('menu_theme'), self::OWN_HEADER_THEMES, true)) {
            $values['menu_theme'] = 'classic';
        }
        if ($values !== []) {
            // a menu brought as a PDF / photo design has no item photos yet: no empty placeholder tiles, the price
            // on the name's line (menu/items/classic.blade.php); they come back once photos are added
            if (! MenuItem::query()->whereNotNull('image')->where('image', '!=', '')->exists()) {
                $values['item_placeholder'] = '0';
            }
            // the cut-out logo is drawn in the brand colour, on a header of the brand colour: a light tile behind it
            if (isset($values['logo'])) {
                $values['logo_background'] = 'white';
            }
        }
        if (isset($values['logo'])) {
            $old = (string) Settings::get('logo');
            if ($old !== '' && $old !== $values['logo']) {
                app(ImageService::class)->delete($old);
            }
        }
        if ($values !== []) {
            Settings::put($values);
            Audit::record('branding.from_import', $subject, ['keys' => array_keys($values)]);
        }
        self::discardLogo($brand, $values['logo'] ?? null);

        return array_keys($values);
    }

    /** Deletes the cut-out logo of a draft unless it became the restaurant's logo. */
    public static function discardLogo(?array $brand, ?string $kept = null): void
    {
        $logo = $brand['logo'] ?? null;
        if (is_string($logo) && $logo !== $kept && $logo !== (string) Settings::get('logo')) {
            app(ImageService::class)->delete($logo);
        }
    }

    /** A stored path inside the current restaurant's own public folder. */
    public static function ownPath(string $path): bool
    {
        return tenant() !== null && str_starts_with($path, 'tenants/'.tenant()->getTenantKey().'/') && ! str_contains($path, '..');
    }

    /** @return array{page:int, x:float, y:float, w:float, h:float}|null */
    private static function box(mixed $raw): ?array
    {
        if (! is_array($raw) || ($raw['found'] ?? true) !== true) {
            return null;
        }
        $n = fn (string $k): ?float => isset($raw[$k]) && is_numeric($raw[$k]) ? (float) $raw[$k] : null;
        [$x, $y, $w, $h] = [$n('x'), $n('y'), $n('w'), $n('h')];
        $page = isset($raw['page']) && is_numeric($raw['page']) ? (int) $raw['page'] : 1;
        if ($x === null || $y === null || $w === null || $h === null || $page < 1 || $page > 50) {
            return null;
        }
        if ($x < 0 || $y < 0 || $x >= 1 || $y >= 1 || $w < 0.02 || $h < 0.02 || $w > 1 || $h > 1) {
            return null;
        }

        return ['page' => $page, 'x' => round($x, 4), 'y' => round($y, 4), 'w' => round(min($w, 1 - $x), 4), 'h' => round(min($h, 1 - $y), 4)];
    }
}
