<?php

namespace App\Services\Geocoding;

use Illuminate\Support\Facades\Cache;

/**
 * Whose maps and addresses this request uses (config geocoding.driver): OpenStreetMap (Leaflet tiles and Nominatim),
 * or Google (Maps JavaScript API in the browser, Geocoding API from the server). Google's terms keep its addresses
 * on its own map, so the two always go together: a screen that shows a Google map says so with map=google and gets
 * Google's addresses, any other screen gets Nominatim's.
 *
 * Google is used only when it can be, and OpenStreetMap takes over otherwise: both keys are set, the page's host is
 * one the browser key allows (the main domains and their subdomains, plus geocoding.google.hosts for restaurants'
 * own domains) and this month's geocoding calls are under the limit that keeps the platform inside the free tier.
 */
final class MapProvider
{
    public const GOOGLE = 'google';

    public const NOMINATIM = 'nominatim';

    public const NONE = 'none';

    /** The provider for a page served on $host (this request's host when null). */
    public static function name(?string $host = null): string
    {
        return match ((string) config('geocoding.driver')) {
            self::GOOGLE => self::googleReady() && self::hostAllowed($host ?? request()->getHost()) && self::underLimit() ? self::GOOGLE : self::NOMINATIM,
            self::NOMINATIM => self::NOMINATIM,
            default => self::NONE,
        };
    }

    /** The provider of the addresses for a screen showing the map $shown ("google" or anything else). */
    public static function forMap(?string $shown): string
    {
        $name = self::name();

        return $name === self::GOOGLE && $shown !== self::GOOGLE ? self::NOMINATIM : $name;
    }

    public static function geocoder(string $name): Geocoder
    {
        return match ($name) {
            self::GOOGLE => new GoogleGeocoder,
            self::NOMINATIM => new NominatimGeocoder,
            default => new NullGeocoder,
        };
    }

    /**
     * What the browser needs to draw a Google map (resources/js/gmaps.js), or null when this page uses Leaflet.
     *
     * @return array{key:string, language:string}|null
     */
    public static function client(): ?array
    {
        return self::name() === self::GOOGLE
            ? ['key' => (string) config('geocoding.google.browser_key'), 'language' => app()->getLocale() === 'en' ? 'en' : 'ar']
            : null;
    }

    public static function googleReady(): bool
    {
        return filled(config('geocoding.google.server_key')) && filled(config('geocoding.google.browser_key'));
    }

    /** The main domains, their subdomains (app., a restaurant's own) and the restaurants' own domains listed in the config. */
    public static function hostAllowed(string $host): bool
    {
        $host = mb_strtolower($host);
        foreach ((array) config('tenancy.central_domains', []) as $domain) {
            if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                return true;
            }
        }

        return in_array($host, (array) config('geocoding.google.hosts', []), true);
    }

    public static function underLimit(): bool
    {
        return self::usedThisMonth() < (int) config('geocoding.google.monthly_limit');
    }

    public static function usedThisMonth(): int
    {
        return (int) Cache::get(self::usageKey(), 0);
    }

    /** One call to Google's Geocoding API was made (answers from our cache are not counted: they cost nothing). */
    public static function countCall(): void
    {
        Cache::add(self::usageKey(), 0, now()->addDays(40));
        Cache::increment(self::usageKey());
    }

    private static function usageKey(): string
    {
        return 'geo:google:calls:'.local_at()->format('Y-m');
    }
}
