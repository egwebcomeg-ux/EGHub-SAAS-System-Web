<?php

return [

    /*
    | LiteSpeed page cache (App\Services\PageCache, App\Http\Middleware\CachePublicPage): the public marketing pages
    | and the restaurants' public menu pages are stored by the LiteSpeed web server for visitors without anything
    | personal on the page, steered by the X-LiteSpeed-* response headers only. Off unless PAGE_CACHE=true (local
    | machines and tests are unchanged).
    |
    | landing_max_age   marketing pages and the directory (purged on blog, settings and plan changes; never past
    |                   the next scheduled blog article)
    | menu_max_age      restaurant menu pages (purged on every change of what they show; the short age covers what
    |                   changes with the clock: open now, timed offers, schedules, the greeting)
    | tag_prefix        prefix of every cache tag, so a purge never touches another site on the same server
    | purge_over_http   after a change also call /_lscache/purge on each host that may hold the pages (the header
    |                   alone only reaches the virtual host that answered; queue jobs and commands have no response)
    | purge_hosts       extra hosts to send a full purge to (comma separated); main and earlier domains always get it
    | origin_ip         connect the purge calls straight to the server (skipping Cloudflare); empty = public DNS
    */
    'enabled' => (bool) env('PAGE_CACHE', false),

    'landing_max_age' => (int) env('PAGE_CACHE_LANDING_MAX_AGE', 3600),

    'menu_max_age' => (int) env('PAGE_CACHE_MENU_MAX_AGE', 300),

    'tag_prefix' => env('PAGE_CACHE_TAG_PREFIX', 'eg'),

    'purge_over_http' => (bool) env('PAGE_CACHE_PURGE_HTTP', true),

    'purge_hosts' => array_values(array_filter(array_map('trim', explode(',', (string) env('PAGE_CACHE_PURGE_HOSTS', ''))))),

    'origin_ip' => env('PAGE_CACHE_ORIGIN_IP'),

];
