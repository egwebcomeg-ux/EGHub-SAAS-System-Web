<?php

/*
 * The website assistant ("اسألنا" on egmenu.app): answers visitors' questions about EgMenu with Claude from what
 * the site itself says (plans, add-ons, modules, FAQ) and hands over to the sales WhatsApp when a person is needed.
 * No key = the button opens WhatsApp directly. The key is EgWeb's own and never leaves the server.
 */
return [
    'key' => env('ANTHROPIC_API_KEY', ''),
    'model' => env('ASSISTANT_MODEL', 'claude-sonnet-5-5'),
    'max_tokens' => (int) env('ASSISTANT_MAX_TOKENS', 900),
    'timeout' => 40,
];
