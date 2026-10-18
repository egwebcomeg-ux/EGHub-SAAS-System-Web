<?php

namespace App\Support;

/**
 * Coordinates inside a Google Maps link: ".../@30.0444,31.2357,17z", "...!3d30.0444!4d31.2357", "?q=30.0444,31.2357",
 * "ll=" / "query=" / "destination=". Short links (maps.app.goo.gl) carry none, so they give null.
 */
final class MapsLink
{
    private const NUMBER = '(-?\d{1,3}\.\d+)';

    /** @return array{lat:float, lng:float}|null */
    public static function coordinates(?string $url): ?array
    {
        $url = urldecode(trim((string) $url));
        $patterns = [
            '/!3d'.self::NUMBER.'!4d'.self::NUMBER.'/',
            '/@'.self::NUMBER.','.self::NUMBER.'/',
            '/[?&](?:q|ll|query|destination|center|daddr)='.self::NUMBER.',\s*'.self::NUMBER.'/',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $url, $m)) {
                $lat = (float) $m[1];
                $lng = (float) $m[2];
                if (abs($lat) <= 90 && abs($lng) <= 180 && ($lat !== 0.0 || $lng !== 0.0)) {
                    return ['lat' => round($lat, 7), 'lng' => round($lng, 7)];
                }
            }
        }

        return null;
    }

    /** Great-circle distance in km (haversine), the same formula the menu uses in the browser. */
    public static function distanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $rad = M_PI / 180;
        $a = sin(($lat2 - $lat1) * $rad / 2) ** 2 + cos($lat1 * $rad) * cos($lat2 * $rad) * sin(($lng2 - $lng1) * $rad / 2) ** 2;

        return 6371 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
