<?php

/*
 * The website assistant ("اسألنا" on egmenu.app): answers visitors' questions about EgMenu from what the site itself
 * says (plans, add-ons, modules, FAQ) and hands over to the sales WhatsApp when a person is needed. Either Claude
 * (Anthropic) or Google Gemini answers, picked by ASSISTANT_PROVIDER. No key for the chosen provider = the button
 * opens WhatsApp directly. Keys are EgWeb's own and never leave the server.
 */
return [
    'provider' => env('ASSISTANT_PROVIDER', 'anthropic'),   // anthropic | gemini

    'key' => env('ANTHROPIC_API_KEY', ''),
    'model' => env('ASSISTANT_MODEL', 'claude-sonnet-5-5'),

    'gemini' => [
        'key' => env('GEMINI_API_KEY', ''),
        'model' => env('ASSISTANT_GEMINI_MODEL', 'gemini-3.1-flash-lite'),
    ],

    'max_tokens' => (int) env('ASSISTANT_MAX_TOKENS', 900),
    'timeout' => 40,
];
