<?php

use App\Billing\Lifecycle;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('egmenu:billing', function (Lifecycle $lifecycle) {
    $r = $lifecycle->run();
    $this->info("past_due: {$r['past_due']} · suspended: {$r['suspended']} · reminded: {$r['reminded']}");
})->purpose('Daily subscription lifecycle: trial/period end → grace → suspension, reminders');

// One cron line on the server runs everything: * * * * * php artisan schedule:run
Schedule::command('egmenu:billing')->dailyAt('03:00')->withoutOverlapping();
