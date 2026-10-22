<?php

namespace App\Support;

/**
 * The one pinned copy of Leaflet (OpenStreetMap tiles) every map of the app loads from the CDN: the delivery zones
 * editor, the drivers page, the guest's tracking page (partials/leaflet) and, only when the guest taps "pin my
 * location", the menu's checkout (resources/js/menu.js loads it on demand from these same URLs).
 */
final class Leaflet
{
    public const VERSION = '1.9.4';

    public const CSS = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';

    public const CSS_INTEGRITY = 'sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=';

    public const JS = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';

    public const JS_INTEGRITY = 'sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=';

    /** @return array{css:string, cssIntegrity:string, js:string, jsIntegrity:string} */
    public static function assets(): array
    {
        return ['css' => self::CSS, 'cssIntegrity' => self::CSS_INTEGRITY, 'js' => self::JS, 'jsIntegrity' => self::JS_INTEGRITY];
    }
}
