<?php

/*
|--------------------------------------------------------------------------
| Trusted proxies
|--------------------------------------------------------------------------
|
| Production sits behind the Cloudflare proxy, so REMOTE_ADDR is a Cloudflare edge and the visitor's IP
| arrives in X-Forwarded-For. Only requests coming from these ranges may set that header; from anywhere
| else it is ignored, so a visitor cannot spoof their IP to dodge rate limits.
|
| TRUSTED_PROXIES: empty = the Cloudflare list below, "*" = trust whoever connects (only when the app is
| reachable solely through a proxy), or a comma separated list of IPs / CIDR ranges.
|
| Read by Illuminate\Http\Middleware\TrustProxies (the trusted headers are set in bootstrap/app.php).
| Source: https://www.cloudflare.com/ips/ — review it from time to time.
|
*/

$cloudflare = [
    // IPv4
    '173.245.48.0/20',
    '103.21.244.0/22',
    '103.22.200.0/22',
    '103.31.4.0/22',
    '141.101.64.0/18',
    '108.162.192.0/18',
    '190.93.240.0/20',
    '188.114.96.0/20',
    '197.234.240.0/22',
    '198.41.128.0/17',
    '162.158.0.0/15',
    '104.16.0.0/13',
    '104.24.0.0/14',
    '172.64.0.0/13',
    '131.0.72.0/22',
    // IPv6
    '2400:cb00::/32',
    '2606:4700::/32',
    '2803:f800::/32',
    '2405:b500::/32',
    '2405:8100::/32',
    '2a06:98c0::/29',
    '2c0f:f248::/32',
];

$configured = trim((string) env('TRUSTED_PROXIES', ''));

return [

    'cloudflare' => $cloudflare,

    'proxies' => match (true) {
        $configured === '' => $cloudflare,
        $configured === '*' => '*',
        default => array_values(array_filter(array_map(trim(...), explode(',', $configured)))),
    },

];
