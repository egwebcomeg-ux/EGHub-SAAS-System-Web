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
| self-hosted Nominatim instead.
|
| google: Google Maps for the delivery maps and their addresses (App\Services\Geocoding\MapProvider). It needs two
| keys of one Google Cloud project: a server key (Geocoding API, restricted to the server's IP) and a browser key
| (Maps JavaScript API, restricted to the sites below). Wherever Google cannot be used (a key missing, a host the
| browser key does not allow, the month's limit reached) OpenStreetMap takes over by itself.
|
*/

return [

    'driver' => env('GEOCODER', 'nominatim'),

    'google' => [
        'server_key' => env('GOOGLE_MAPS_SERVER_KEY') ?: env('GOOGLE_PLACES_KEY'),
        'browser_key' => env('GOOGLE_MAPS_BROWSER_KEY'),
        // restaurants' own domains the browser key allows (the main domains and their subdomains always are)
        'hosts' => array_values(array_filter(array_map(fn ($h) => mb_strtolower(trim($h)), explode(',', (string) env('GOOGLE_MAPS_HOSTS', ''))))),
        // Geocoding API calls a calendar month for the whole platform; past it OpenStreetMap answers until the next month
        'monthly_limit' => (int) env('GOOGLE_GEOCODING_MONTHLY_LIMIT', 9000),
        'timeout' => 5,
    ],

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
