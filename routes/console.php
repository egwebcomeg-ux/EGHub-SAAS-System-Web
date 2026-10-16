<?php

use App\Billing\Lifecycle;
use App\Models\Tenant;
use App\Services\Loyalty\LoyaltyService;
use App\Services\Loyalty\Packages;
use App\Services\Marketing\Campaigns;
use App\Services\Reports\WeeklyReportMailer;
use App\Services\Wallet\WalletService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('egmenu:billing', function (Lifecycle $lifecycle) {
    $r = $lifecycle->run();
    $this->info("past_due: {$r['past_due']} · downgraded to free: {$r['downgraded']} · reminded: {$r['reminded']}");
})->purpose('Daily subscription lifecycle: trial/period end → grace → suspension, reminders');

Artisan::command('egmenu:loyalty-expire', function (LoyaltyService $loyalty, Packages $packages, WalletService $wallet) {
    // runs per restaurant so the tenant scope and plan rights apply
    $n = $p = $c = 0;
    foreach (Tenant::query()->where('status', 'active')->get() as $tenant) {
        tenancy()->initialize($tenant);
        $n += $loyalty->expireStamps();
        $p += $packages->expire();
        $c += $wallet->expireCashback();
        tenancy()->end();
    }
    $this->info("expired cards: $n · expired packages: $p · expired cashback lines: $c");
})->purpose('Clear loyalty stamps older than each program allows, close prepaid packages past their date, take back expired cashback');

Artisan::command('egmenu:campaigns', function (Campaigns $campaigns) {
    $this->info('campaigns started: '.$campaigns->startDue());
})->purpose('Start the WhatsApp campaigns scheduled until now');
Artisan::command('egmenu:weekly-report', function (WeeklyReportMailer $mailer) {
    $this->info('weekly reports sent: '.$mailer->run());
})->purpose('Email last week\'s numbers to the owners who turned the weekly report on (advanced reports)');

// One cron line on the server runs everything: * * * * * php artisan schedule:run
Schedule::command('egmenu:billing')->dailyAt('03:00')->withoutOverlapping();
Schedule::command('egmenu:loyalty-expire')->dailyAt('03:30')->withoutOverlapping();
Schedule::command('egmenu:backup')->dailyAt('02:30')->withoutOverlapping();
Schedule::command('egmenu:campaigns')->everyMinute()->withoutOverlapping();
Schedule::command('egmenu:weekly-report')->weeklyOn(1, '08:00')->withoutOverlapping();
// background jobs (AI menu import) without a separate worker process: drained every minute by the same cron
Schedule::command('queue:work --stop-when-empty --max-time=55 --tries=1')->everyMinute()->withoutOverlapping();
// heartbeat read by egmenu:doctor to confirm the cron line is installed
Schedule::call(fn () => cache()->put('egmenu:scheduler_at', now()->timestamp, now()->addDay()))->everyMinute()->name('scheduler-heartbeat');
