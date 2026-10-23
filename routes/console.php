<?php

use App\Billing\Lifecycle;
use App\Models\Tenant;
use App\Services\Loyalty\LoyaltyService;
use App\Services\Loyalty\Packages;
use App\Services\Marketing\Automations;
use App\Services\Marketing\Campaigns;
use App\Services\Orders\AutoCancel;
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

Artisan::command('egmenu:auto-cancel', function (AutoCancel $autoCancel) {
    // per restaurant: only those that set online_auto_cancel_minutes have anything to cancel
    $n = 0;
    foreach (Tenant::query()->where('status', 'active')->get() as $tenant) {
        tenancy()->initialize($tenant);
        $n += $autoCancel->run();
        tenancy()->end();
    }
    $this->info("auto-cancelled orders: $n");
})->purpose('Cancel the online orders nobody accepted within the restaurant\'s time limit (wallet money goes back)');

// One cron line on the server runs everything: * * * * * php artisan schedule:run
// withoutOverlapping(N): the lock expires after N minutes, so a run killed half-way (deploy, OOM) never blocks the
// command for the default 24 hours. Daily/weekly jobs get a lock as long as they can reasonably take.
Schedule::command('egmenu:billing')->dailyAt('03:00')->withoutOverlapping(60);
Schedule::command('egmenu:loyalty-expire')->dailyAt('03:30')->withoutOverlapping(60);
Schedule::command('egmenu:backup')->dailyAt('02:30')->withoutOverlapping(120)->runInBackground();
// per-restaurant backup zips the owners download (plans with the `backups` feature), Sunday night after the platform backup
Schedule::command('egmenu:tenant-backups')->weeklyOn(0, '03:30')->withoutOverlapping(180)->runInBackground();
Schedule::command('egmenu:campaigns')->everyMinute()->withoutOverlapping(10);
Schedule::command('egmenu:auto-cancel')->everyMinute()->withoutOverlapping(10);
Schedule::command('egmenu:weekly-report')->weeklyOn(1, '08:00')->withoutOverlapping(60);
// background jobs (AI menu import) without a separate worker process: drained every minute by the same cron, in the
// background so the minute's other entries (and the heartbeat) are not held up; a failed job is retried twice, 30 s apart
Schedule::command('queue:work --stop-when-empty --max-time=55 --tries=3 --backoff=30')->everyMinute()->withoutOverlapping(5)->runInBackground();
// heartbeat read by egmenu:doctor to confirm the cron line is installed
Schedule::call(fn () => cache()->put('egmenu:scheduler_at', now()->timestamp, now()->addDay()))->everyMinute()->name('scheduler-heartbeat');

// Marketing automations (welcome / winback / birthday): every 15 minutes, per restaurant, inside each one's send window
Artisan::command('egmenu:automations', function (Automations $automations) {
    $this->info('automation messages sent: '.$automations->runAll());
})->purpose('Send the due welcome, winback and birthday WhatsApp messages of every restaurant');
Schedule::command('egmenu:automations')->everyFifteenMinutes()->withoutOverlapping(10);
