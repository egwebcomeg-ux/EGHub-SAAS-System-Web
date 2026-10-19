<?php

/*
| "Continue with Google / Facebook" comes back to the dashboard host: <APP_URL scheme>://app.<first CENTRAL_DOMAINS
| entry>[:port]/auth/{provider}/callback, e.g. https://app.egmenu.app/auth/google/callback. This exact address goes
| into the provider's console. *_REDIRECT_URI overrides it when ever needed.
*/
$socialCallback = function (string $provider): string {
    $appUrl = parse_url((string) env('APP_URL', 'http://localhost'));
    $domain = trim(explode(',', (string) env('CENTRAL_DOMAINS', 'localhost'))[0]) ?: 'localhost';
    $port = isset($appUrl['port']) ? ':'.$appUrl['port'] : '';

    return ($appUrl['scheme'] ?? 'https')."://app.$domain$port/auth/$provider/callback";
};

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Sign-in buttons for restaurant staff; each one shows only when both of its keys are set (SocialProviders).
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI') ?: $socialCallback('google'),
    ],

    'facebook' => [
        'client_id' => env('FACEBOOK_CLIENT_ID'),
        'client_secret' => env('FACEBOOK_CLIENT_SECRET'),
        'redirect' => env('FACEBOOK_REDIRECT_URI') ?: $socialCallback('facebook'),
    ],

];
