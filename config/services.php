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

$guestCallback = function (string $provider): string {
    $appUrl = parse_url((string) env('APP_URL', 'http://localhost'));
    $domain = trim(explode(',', (string) env('CENTRAL_DOMAINS', 'localhost'))[0]) ?: 'localhost';
    $port = isset($appUrl['port']) ? ':'.$appUrl['port'] : '';

    return ($appUrl['scheme'] ?? 'https')."://$domain$port/auth/$provider/callback";
};

return [

    // Hidden folder of public/ holding Android app builds under test, listed on the admin home (temporary).
    'apps_beta' => [
        'dir' => env('APPS_BETA_DIR'),
    ],

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
        // restaurant guests ("الدخول بجوجل" in a menu's «حسابي»), whatever host the menu is on, all come back to ONE
        // address on the main domain: <scheme>://<first CENTRAL_DOMAINS entry>[:port]/auth/google/callback, e.g.
        // https://eghub.app/auth/google/callback (App\Http\Controllers\Platform\GuestOAuthController). It is a second
        // "Authorized redirect URI" of the same Google client; GOOGLE_GUEST_REDIRECT_URI overrides it.
        'guest_redirect' => env('GOOGLE_GUEST_REDIRECT_URI') ?: $guestCallback('google'),
    ],

    /*
    | Kashier (developers.kashier.io): restaurants pay their EgHub invoices online (App\Billing\Gateways\KashierGateway).
    | merchant_id (MID-xxx-xxx), api_key = the Payment API key (signs webhooks), secret_key = the merchant secret key
    | (opens payment sessions). mode: test | live. Which methods are offered is chosen in the super admin settings.
    */
    'kashier' => [
        'merchant_id' => env('KASHIER_MERCHANT_ID'),
        'api_key' => env('KASHIER_API_KEY'),
        'secret_key' => env('KASHIER_SECRET_KEY'),
        'mode' => env('KASHIER_MODE', 'test'),
        'base_url' => env('KASHIER_BASE_URL'),
    ],

    /*
    | The guest's loyalty card in Apple Wallet / Google Wallet (App\Services\Loyalty\WalletPasses). Each button shows
    | on the card page only when its keys are set and the files exist. Paths are absolute or relative to the app root.
    | Apple: the Pass Type ID (pass.app.egmenu...), the Team ID, the Pass Type ID certificate (.p12 + its password, or
    | a PEM with the certificate and its key) and Apple's WWDR intermediate certificate (PEM). OpenSSL 3 cannot read
    | Apple's older .p12 encryption: convert it once with `openssl pkcs12 -legacy -in pass.p12 -out pass.pem -nodes`.
    | Google: the Wallet issuer ID and the JSON key file of a service account that has access to that issuer.
    */
    /*
    | The EgHub Screens Android TV app (app.eghub.screens). Until its Google Play listing is live the setup steps install
    | the signed APK from eghub.app/tv.apk through Downloader; set the listing URL here and they point to Google Play.
    */
    'eghub_screens' => [
        'play_url' => env('EGHUB_SCREENS_PLAY_URL'),
    ],

    'wallet' => [
        'apple' => [
            'pass_type_id' => env('WALLET_APPLE_PASS_TYPE_ID'),
            'team_id' => env('WALLET_APPLE_TEAM_ID'),
            'certificate' => env('WALLET_APPLE_CERT_PATH'),
            'certificate_password' => env('WALLET_APPLE_CERT_PASSWORD'),
            'wwdr_certificate' => env('WALLET_APPLE_WWDR_CERT_PATH'),
        ],
        'google' => [
            'issuer_id' => env('WALLET_GOOGLE_ISSUER_ID'),
            'service_account' => env('WALLET_GOOGLE_SERVICE_ACCOUNT_PATH'),
        ],
    ],

    // Push notifications to the EgHub Android apps (restaurant, waiter): Firebase Cloud Messaging HTTP v1 with a
    // service account (App\Services\Push\FcmClient). Both empty = pushes are skipped (logged once).
    'fcm' => [
        'project_id' => env('FCM_PROJECT_ID'),
        'credentials' => env('FCM_CREDENTIALS'), // path to the service-account JSON (outside public/)
    ],

    'facebook' => [
        'client_id' => env('FACEBOOK_CLIENT_ID'),
        'client_secret' => env('FACEBOOK_CLIENT_SECRET'),
        'redirect' => env('FACEBOOK_REDIRECT_URI') ?: $socialCallback('facebook'),
    ],

];
