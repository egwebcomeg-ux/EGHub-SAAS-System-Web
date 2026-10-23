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

];
