<?php

use App\Billing\Lifecycle;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('egmenu:billing', function (Lifecycle $lifecycle) {
    $r = $lifecycle->run();
    $this->info("past_due: {$r['past_due']} · suspended: {$r['suspended']} · reminded: {$r['reminded']}");
})->purpose('Daily subscription lifecycle: trial/period end → grace → suspension, reminders');

Artisan::command('egmenu:loyalty-expire', function (App\Services\Loyalty\LoyaltyService $loyalty) {
    // runs per restaurant so the tenant scope and plan rights apply
    $n = 0;
    foreach (App\Models\Tenant::query()->where('status', 'active')->get() as $tenant) {
        tenancy()->initialize($tenant);
        $n += $loyalty->expireStamps();
        tenancy()->end();
    }
    $this->info("expired cards: $n");
})->purpose('Clear loyalty stamps older than each program allows');

// One cron line on the server runs everything: * * * * * php artisan schedule:run
Schedule::command('egmenu:billing')->dailyAt('03:00')->withoutOverlapping();
Schedule::command('egmenu:loyalty-expire')->dailyAt('03:30')->withoutOverlapping();
