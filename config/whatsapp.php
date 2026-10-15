<?php

/*
 * WhatsApp channel (platform level): one sender account for every restaurant, paid per message from each
 * restaurant's credit wallet. Secrets only come from .env and are never logged.
 */
return [

    // log (dev/tests: nothing leaves the server) | meta (WhatsApp Business Cloud API) | webhook (other providers)
    'driver' => env('WHATSAPP_DRIVER', 'log'),

    'meta' => [
        'phone_id' => env('WHATSAPP_PHONE_ID'),
        'token' => env('WHATSAPP_TOKEN'),
        'version' => env('WHATSAPP_API_VERSION', 'v21.0'),
        'base_url' => 'https://graph.facebook.com',
    ],

    'webhook' => [
        'url' => env('WHATSAPP_WEBHOOK_URL'),
        // sent as the X-EgMenu-Secret header so the provider can reject anyone else
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
    ],

    // Meta template language per restaurant language
    'languages' => ['ar' => 'ar', 'en' => 'en'],

    'timeout' => 15,
];
