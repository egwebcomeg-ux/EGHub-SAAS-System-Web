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
     * Restaurants' messages from the EgHub number (order updates, campaigns, credits sold). Off until their templates are
     * approved at Meta: nothing is sold or charged, and a restaurant's own connected number keeps working.
     */
    'platform_sending' => (bool) env('WHATSAPP_PLATFORM_SENDING', false),

    // the Meta account whose templates `eghub:whatsapp-templates` follows
    'business_account_id' => env('WHATSAPP_BUSINESS_ACCOUNT_ID'),

    /*
     * «ادخل بواتساب» in a menu's «حسابي» (App\Services\Customers\SignIn\WhatsAppSignIn): the guest sends a prepared
     * message with a 6-digit code to the number above and the inbound webhook confirms the sender's mobile. Offered
     * only when that number AND a verified inbound webhook (app secret, or the provider secret) are set; false here
     * hides it anyway.
     */
    'guest_sign_in' => (bool) env('WHATSAPP_GUEST_SIGN_IN', true),

    /*
     * «ابعتلي كود» from the EgHub number for a restaurant that chose it (setting guest_code_channel): one credit per code
     * from its wallet (App\Services\Customers\SignIn\PhoneCodeSignIn), while Meta bills EgHub. Turn it on only once
     * the sign_in_code template is approved in Arabic on the EgHub account and it has a payment method.
     */
    'platform_sign_in_code' => (bool) env('WHATSAPP_PLATFORM_SIGN_IN_CODE', false),

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

    // The EgHub Facebook page and its Instagram account in the team's inbox (/webhooks/meta). The page's token comes
    // from the Meta app that signs the webhook (app_secret above); app_id tells our own answers from the team's.
    'page' => [
        'id' => env('META_PAGE_ID'),
        'instagram_id' => env('META_INSTAGRAM_ID'),
        'token' => env('META_PAGE_TOKEN'),
        'app_id' => env('META_MESSAGING_APP_ID'),
    ],

    'webhook' => [
        'url' => env('WHATSAPP_WEBHOOK_URL'),
        // sent as the X-EgHub-Secret header so the provider can reject anyone else; the same header authenticates
        // the provider's inbound POSTs {from, text} to /webhooks/whatsapp
        'secret' => env('WHATSAPP_WEBHOOK_SECRET'),
    ],

    /*
     * Approved template name per message key (Meta needs a pre-approved template for every business-initiated
     * message). The body parameters are sent in the order of App\Services\WhatsApp\Templates::PARAMS.
     */
    'templates' => [
        'order_received' => env('WHATSAPP_TPL_ORDER_RECEIVED', 'eghub_order_received'),
        'order_ready' => env('WHATSAPP_TPL_ORDER_READY', 'eghub_order_ready'),
        'order_on_way' => env('WHATSAPP_TPL_ORDER_ON_WAY', 'eghub_order_on_way'),
        'reservation_confirmed' => env('WHATSAPP_TPL_RESERVATION_CONFIRMED', 'eghub_reservation_confirmed'),
        'loyalty_reward' => env('WHATSAPP_TPL_LOYALTY_REWARD', 'eghub_loyalty_reward'),
        'campaign' => env('WHATSAPP_TPL_CAMPAIGN', 'egmenu_campaign'),
        'automation' => env('WHATSAPP_TPL_AUTOMATION', 'egmenu_campaign'),       // free text {{1}}, same template as campaigns
        'order_staff' => env('WHATSAPP_TPL_ORDER_STAFF', 'egmenu_campaign'),     // new-order alert to the restaurant, free text {{1}}
        // "confirm your cash-on-delivery order" (category Utility): 6 body parameters and 3 quick-reply buttons, the
        // exact text to submit is above Templates::PARAMS['order_confirm']
        'order_confirm' => env('WHATSAPP_TPL_ORDER_CONFIRM', 'egmenu_order_confirm'),
        // the guest's sign-in code (category Authentication, a copy-code button), from a restaurant's OWN number when
        // it has one (Meta bills its account), else from the EgHub number with platform_sign_in_code on (Meta bills EgHub)
        'sign_in_code' => env('WHATSAPP_TPL_SIGN_IN_CODE', 'egmenu_sign_in_code'),
        // the first message the team sends a new customer from «محادثات واتساب», {{1}} their name
        'welcome' => env('WHATSAPP_TPL_WELCOME', 'egmenu_welcome'),
    ],

    // what one authentication message is estimated to cost the restaurant on Meta (shown on the customers page)
    'auth_cost' => (float) env('WHATSAPP_AUTH_COST', 0),

    // Meta template language per restaurant language
    'languages' => ['ar' => 'ar', 'en' => 'en'],

    'timeout' => 15,
];
