<?php

/*
 * WhatsApp channel (platform level): one sender account for every restaurant, paid per message from each
 * restaurant's credit wallet. Secrets only come from .env and are never logged.
 */
return [

    // log (dev/tests: nothing leaves the server) | meta (WhatsApp Business Cloud API) | webhook (other providers)
    'driver' => env('WHATSAPP_DRIVER', 'log'),

    /*
     * The platform's own WhatsApp number (digits, international form: 2010xxxxxxxx). Owners confirm their mobile
     * at signup by sending one prepared message to it (wa.me link). Falls back to the admin setting
     * support_whatsapp; with neither set, phone confirmation is simply not offered.
     */
    'number' => env('WHATSAPP_NUMBER'),

    /*
     * «ادخل بواتساب» in a menu's «حسابي» (App\Services\Customers\SignIn\WhatsAppSignIn): the guest sends a prepared
     * message with a 6-digit code to the number above and the inbound webhook confirms the sender's mobile. Offered
     * only when that number AND a verified inbound webhook (app secret, or the provider secret) are set; false here
     * hides it anyway.
     */
    'guest_sign_in' => (bool) env('WHATSAPP_GUEST_SIGN_IN', true),

    // Inbound webhook (GET/POST /webhooks/whatsapp on the main domain): Meta's verify token and the app secret
    // that signs every POST (X-Hub-Signature-256). Without the app secret every Meta payload is refused.
    'verify_token' => env('WHATSAPP_VERIFY_TOKEN'),
    'app_secret' => env('WHATSAPP_APP_SECRET'),

    'meta' => [
        'phone_id' => env('WHATSAPP_PHONE_ID'),
        'token' => env('WHATSAPP_TOKEN'),
        'version' => env('WHATSAPP_API_VERSION', 'v21.0'),
        'base_url' => 'https://graph.facebook.com',
    ],

    'webhook' => [
        'url' => env('WHATSAPP_WEBHOOK_URL'),
        // sent as the X-EgMenu-Secret header so the provider can reject anyone else; the same header authenticates
        // the provider's inbound POSTs {from, text} to /webhooks/whatsapp
        'secret' => env('WHATSAPP_WEBHOOK_SECRET'),
    ],

    /*
     * Approved template name per message key (Meta needs a pre-approved template for every business-initiated
     * message). The body parameters are sent in the order of App\Services\WhatsApp\Templates::PARAMS.
     */
    'templates' => [
        'order_received' => env('WHATSAPP_TPL_ORDER_RECEIVED', 'egmenu_order_received'),
        'order_ready' => env('WHATSAPP_TPL_ORDER_READY', 'egmenu_order_ready'),
        'order_on_way' => env('WHATSAPP_TPL_ORDER_ON_WAY', 'egmenu_order_on_way'),
        'reservation_confirmed' => env('WHATSAPP_TPL_RESERVATION_CONFIRMED', 'egmenu_reservation_confirmed'),
        'loyalty_reward' => env('WHATSAPP_TPL_LOYALTY_REWARD', 'egmenu_loyalty_reward'),
        'campaign' => env('WHATSAPP_TPL_CAMPAIGN', 'egmenu_campaign'),
        'automation' => env('WHATSAPP_TPL_AUTOMATION', 'egmenu_campaign'),       // free text {{1}}, same template as campaigns
        'order_staff' => env('WHATSAPP_TPL_ORDER_STAFF', 'egmenu_campaign'),     // new-order alert to the restaurant, free text {{1}}
        // "confirm your cash-on-delivery order" (category Utility): 6 body parameters and 3 quick-reply buttons, the
        // exact text to submit is above Templates::PARAMS['order_confirm']
        'order_confirm' => env('WHATSAPP_TPL_ORDER_CONFIRM', 'egmenu_order_confirm'),
    ],

    // Meta template language per restaurant language
    'languages' => ['ar' => 'ar', 'en' => 'en'],

    'timeout' => 15,
];
