<?php

return [

    /*
    | Platform admin (admin.<domain>) hardening.
    | admin_require_2fa: a signed-in admin without two-factor authentication can only open the security page
    | (to turn it on) and sign out. Relaxed in tests and on a local machine with ADMIN_REQUIRE_2FA=false.
    | admin_idle_minutes: an admin session with no request for this long is signed out (dashboard unaffected).
    */
    'admin_require_2fa' => (bool) env('ADMIN_REQUIRE_2FA', true),

    'admin_idle_minutes' => (int) env('ADMIN_IDLE_MINUTES', 120),

    // emails the owners when something breaks (App\Services\ErrorAlert)
    'error_alerts' => (bool) env('ERROR_ALERTS', env('APP_ENV') === 'production'),

    // the uptime monitor's key for /up?token=…: with it the probe also reports the scheduler and the failed jobs
    'monitor_token' => (string) env('MONITOR_TOKEN', ''),

];
