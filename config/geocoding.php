<?php

/*
|--------------------------------------------------------------------------
| Geocoding (the street name of a delivery pin, and the address search on the checkout map)
|--------------------------------------------------------------------------
|
| App\Services\Geocoding\AddressLookup asks the provider below through the restaurant's own host (the guest's phone
| never calls it), caches every answer for a while and keeps to the provider's usage policy. "none" turns it off:
| the checkout still works, the guest types the street.
|
| nominatim: OpenStreetMap's public geocoder (https://operations.osmfoundation.org/policies/nominatim/): at most one
| request a second for the whole platform, a real User-Agent, results cached. The base URL can point at a paid or
| self-hosted Nominatim instead. A Google provider can be added later behind the same Geocoder interface.
|
*/

return [

    'driver' => env('GEOCODER', 'nominatim'),

    // one cached answer per ~25 m square (degrees; 0.00025° of latitude ≈ 28 m)
    'precision' => 0.00025,

    'reverse_cache_days' => (int) env('GEOCODER_CACHE_DAYS', 30),

    'search_cache_days' => 7,

    'nominatim' => [
        'url' => rtrim((string) env('NOMINATIM_URL', 'https://nominatim.openstreetmap.org'), '/'),
        'user_agent' => env('NOMINATIM_USER_AGENT', 'EgHub/1.0 (+https://eghub.app; info@eghub.app)'),
        // optional contact address sent with each request, as the policy suggests for heavier use
        'email' => env('NOMINATIM_EMAIL'),
        'timeout' => 4,
        // seconds between two calls across the whole platform (the public server allows one a second)
        'interval' => (int) env('NOMINATIM_INTERVAL', 1),
        // how long a request waits for its turn before answering "nothing found"
        'wait' => 2,
    ],

];
