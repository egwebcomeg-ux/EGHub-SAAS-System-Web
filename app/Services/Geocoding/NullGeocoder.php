<?php

namespace App\Services\Geocoding;

/** GEOCODER=none: no provider, nothing leaves the server, the guest types the street. */
final class NullGeocoder implements Geocoder
{
    public function reverse(float $lat, float $lng): ?array
    {
        return null;
    }

    public function search(string $query, ?array $near, int $limit = 5): array
    {
        return [];
    }
}
