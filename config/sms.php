<?php

/*
 * Bulk SMS for the manual campaigns (App\Services\Sms\Sms). Off unless a driver is set: the «ابعت SMS للشريحة» button
 * only shows when one is. EgHub picks no provider: "http" talks to any provider with a plain HTTP API (URL, field
 * names and a bearer token from the environment); "log" writes each message to the log (local tries).
 */
return [
    'driver' => env('SMS_DRIVER'),                         // null (off) | log | http

    'sender' => env('SMS_SENDER', ''),

    // what one SMS segment costs, shown as the estimate before a bulk send (the provider's own price)
    'cost_per_segment' => (float) env('SMS_COST_PER_SEGMENT', 0),
    'currency' => env('SMS_CURRENCY', 'EGP'),

    // messages per minute the queue sends (most providers throttle)
    'per_minute' => (int) env('SMS_PER_MINUTE', 60),

    'http' => [
        'url' => env('SMS_HTTP_URL'),
        'method' => env('SMS_HTTP_METHOD', 'post'),        // post | get
        'format' => env('SMS_HTTP_FORMAT', 'form'),         // form | json (post only)
        'token' => env('SMS_HTTP_TOKEN'),                   // sent as "Authorization: Bearer …" when set
        'to_field' => env('SMS_HTTP_TO_FIELD', 'to'),
        'text_field' => env('SMS_HTTP_TEXT_FIELD', 'message'),
        'sender_field' => env('SMS_HTTP_SENDER_FIELD', 'sender'),
        // extra fixed fields, "key=value&key2=value2" (an account id, a username…)
        'extra' => env('SMS_HTTP_EXTRA', ''),
        // the phone as the provider wants it: e164 (+2010…) | international (2010…) | local (010…)
        'phone_format' => env('SMS_HTTP_PHONE_FORMAT', 'international'),
        'timeout' => (int) env('SMS_HTTP_TIMEOUT', 10),
    ],
];
