<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The brand pieces of the "mascot" intro and the header (setting keys in PIECES): the character, its optional
 * speed-lines layer, a slogan picture and a pattern tile. PNG / WebP / JPEG go through ImageService (re-encoded to
 * WebP); SVG is accepted because brand artwork is usually vector, but only after sanitize() rebuilt it without
 * scripts, event handlers, foreignObject, animation of links or any external reference. SVGs are then served by
 * App\Http\Controllers\Menu\BrandAssetController with a no-script CSP, never from /storage directly.
 * Files live in the restaurant's own folder: tenants/{tenant}/brand/{uuid}.svg|webp.
 */
final class BrandAssets
{
    /** setting key => label (translation key) */
    public const PIECES = [
        'intro_mascot' => 'Brand character',
        'intro_mascot_speed' => 'Second layer of the character (speed lines)',
        'intro_slogan' => 'Slogan picture',
        'intro_pattern' => 'Pattern tile',
    ];

    public const MAX_SVG_BYTES = 1_500_000;

    /** Elements dropped with everything inside them. */
    private const BANNED_ELEMENTS = ['script', 'foreignobject', 'iframe', 'object', 'embed', 'audio', 'video', 'canvas', 'handler',
        'listener', 'set', 'animate', 'animatemotion', 'animatetransform', 'animatecolor', 'discard'];

    public function __construct(private readonly ImageService $images) {}

    /** Stores an upload (SVG sanitised, or a raster re-encoded) and returns its path on the public disk. */
    public function store(UploadedFile $file): string
    {
        if (self::looksLikeSvg($file)) {
            if ($file->getSize() > self::MAX_SVG_BYTES) {
                throw ValidationException::withMessages(['image' => __('The SVG file is too big (1.5 MB at most).')]);
            }
            $clean = self::sanitize((string) file_get_contents($file->getRealPath()));
            if ($clean === null) {
                throw ValidationException::withMessages(['image' => __('This SVG file could not be read safely. Upload a PNG instead.')]);
            }
            $path = self::base().'/'.Str::uuid().'.svg';
            Storage::disk('public')->put($path, $clean);

            return $path;
        }

        return $this->images->store($file, 'mascot');
    }

    public function delete(?string $path): void
    {
        if ($path && str_ends_with($path, '.svg')) {
            if (str_starts_with($path, self::base().'/') && ! str_contains($path, '..')) {
                Storage::disk('public')->delete($path);
            }

            return;
        }
        $this->images->delete($path);
    }

    /** Where a guest page loads a piece from: SVGs through the CSP route, rasters from /storage. */
    public static function url(?string $path): ?string
    {
        if (! $path) {
            return null;
        }
        if (str_ends_with($path, '.svg')) {
            return tenant() ? tenant()->url('brand/'.basename($path)) : null;
        }

        return '/storage/'.$path;
    }

    /** A stored piece of the current restaurant (own folder, our own naming), or null. */
    public static function ownSvg(string $file): ?string
    {
        if (! tenant() || ! preg_match('/^[0-9a-f-]{36}\.svg$/', $file)) {
            return null;
        }
        $path = self::base().'/'.$file;

        return Storage::disk('public')->exists($path) ? $path : null;
    }

    public static function looksLikeSvg(UploadedFile $file): bool
    {
        $head = (string) file_get_contents($file->getRealPath(), false, null, 0, 1024);

        return strtolower((string) $file->getClientOriginalExtension()) === 'svg'
            || str_contains((string) $file->getMimeType(), 'svg')
            || preg_match('/^\s*(<\?xml[^>]*>\s*)?(<!--.*?-->\s*)*<svg[\s>]/is', $head) === 1;
    }

    /**
     * Rebuilds an SVG keeping only drawing: no DOCTYPE / entities (XXE, billion laughs), no script-capable elements,
     * no on* handlers, links only to "#fragments" or embedded raster data, no external url() / @import in styles.
     * Returns null when the file is not a well-formed SVG.
     */
    public static function sanitize(string $svg): ?string
    {
        if (preg_match('/<!DOCTYPE|<!ENTITY/i', $svg)) {
            return null;
        }
        $previous = libxml_use_internal_errors(true);
        $doc = new \DOMDocument;
        $ok = $doc->loadXML($svg, LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $root = $doc->documentElement;
        if (! $ok || ! $root || strtolower($root->localName) !== 'svg') {
            return null;
        }
        // processing instructions (an xml-stylesheet one) can pull outside files in
        foreach (iterator_to_array((new \DOMXPath($doc))->query('//processing-instruction()')) as $pi) {
            $pi->parentNode?->removeChild($pi);
        }
        $elements = [];
        foreach ($doc->getElementsByTagName('*') as $el) {
            $elements[] = $el;
        }
        foreach ($elements as $el) {
            if (! $el->parentNode) {
                continue; // inside an element removed already
            }
            if (strtolower($el->localName) === 'a') {
                // a link around artwork: keep the drawing, drop the link
                while ($el->firstChild) {
                    $el->parentNode->insertBefore($el->firstChild, $el);
                }
                $el->parentNode->removeChild($el);

                continue;
            }
            if (in_array(strtolower($el->localName), self::BANNED_ELEMENTS, true)) {
                $el->parentNode->removeChild($el);

                continue;
            }
            if (strtolower($el->localName) === 'style' && ! self::safeCss($el->textContent)) {
                $el->parentNode->removeChild($el);

                continue;
            }
            // a plain list: href and xlink:href share a local name, so a keyed copy would lose one of them
            $attributes = [];
            foreach ($el->attributes as $attr) {
                $attributes[] = $attr;
            }
            foreach ($attributes as $attr) {
                $name = strtolower($attr->nodeName);
                $local = strtolower($attr->localName);
                $value = trim($attr->value);
                $drop = str_starts_with($local, 'on')
                    || (($local === 'href' || $name === 'xlink:href') && ! self::safeLink($value))
                    || ($local === 'style' && ! self::safeCss($value))
                    || preg_match('/url\s*\(\s*[\'"]?\s*(?!#)/i', $value) === 1
                    || preg_match('/javascript:|vbscript:|data:text/i', $value) === 1;
                if ($drop) {
                    $el->removeAttributeNode($attr);
                }
            }
        }

        return $doc->saveXML($root) ?: null;
    }

    private static function safeLink(string $value): bool
    {
        return str_starts_with($value, '#') || preg_match('#^data:image/(png|jpe?g|webp|gif);base64,[A-Za-z0-9+/=\s]+$#i', $value) === 1;
    }

    private static function safeCss(string $css): bool
    {
        return preg_match('/@import|expression\s*\(|javascript:|behavior\s*:|-moz-binding/i', $css) !== 1
            && preg_match('/url\s*\(\s*[\'"]?\s*(?!#)/i', $css) !== 1;
    }

    private static function base(): string
    {
        return 'tenants/'.tenant()->getTenantKey().'/brand';
    }
}
