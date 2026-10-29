<?php

use App\Billing\Lifecycle;
use App\Models\CorporateBooking;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Services\Corporate\CorporateBookings;
use App\Services\Entitlements;
use App\Services\Loyalty\LoyaltyService;
use App\Services\Loyalty\Packages;
use App\Services\Marketing\Automations;
use App\Services\Marketing\Campaigns;
use App\Services\Orders\AutoCancel;
use App\Services\Orders\OrderFlow;
use App\Services\Orders\OrderService;
use App\Services\Reports\DailyDigest;
use App\Services\Reports\WeeklyReportMailer;
use App\Services\Settings;
use App\Services\Wallet\WalletService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

/**
 * Runs $fn inside each restaurant's tenant context. One restaurant's failure is logged and skipped so it never stops
 * the others, and the context and per-restaurant caches are always cleared. Returns how many restaurants failed.
 *
 * @param  iterable<Tenant>  $tenants
 */
$forEachTenant = function (string $command, iterable $tenants, Closure $fn): int {
    $failed = 0;
    foreach ($tenants as $tenant) {
        try {
            tenancy()->initialize($tenant);
            $fn();
        } catch (Throwable $e) {
            $failed++;
            Log::error('scheduled tenant run failed', ['command' => $command, 'tenant' => $tenant->id, 'error' => $e->getMessage()]);
        } finally {
            if (tenancy()->initialized) {
                tenancy()->end();
            }
            Settings::flush();
            Entitlements::flush();
        }
    }

    return $failed;
};

Artisan::command('egmenu:billing', function (Lifecycle $lifecycle) {
    $r = $lifecycle->run();
    $this->info("past_due: {$r['past_due']} · downgraded to free: {$r['downgraded']} · reminded: {$r['reminded']} · launch offer reminders: {$r['offer_reminded']}");
})->purpose('Daily subscription lifecycle: trial/period end → grace → suspension, reminders');

Artisan::command('egmenu:auto-serve', function (OrderFlow $flow, OrderService $orders) use ($forEachTenant) {
    // only restaurants with a paid order left ready (no table, not a delivery): the rest have nothing to close
    $tenants = Tenant::query()->where('status', 'active')->whereIn('id', Order::query()->withoutGlobalScopes()->where('status', 'ready')
        ->where('payment_status', 'paid')->whereNull('dining_table_id')->where('type', '!=', 'delivery')->select('tenant_id'))->orderBy('id')->get();
    $n = 0;
    $failed = $forEachTenant('egmenu:auto-serve', $tenants, function () use ($flow, $orders, &$n) {
        $n += $flow->autoServe($orders);
    });
    $this->info("closed ready orders: $n · failed restaurants: $failed");
})->purpose('Close paid orders left ready past each restaurant\'s order_auto_serve_minutes');

Artisan::command('egmenu:corporate-visits', function (CorporateBookings $bookings) use ($forEachTenant) {
    $n = 0;
    $failed = $forEachTenant('egmenu:corporate-visits', Tenant::query()->where('status', 'active')->whereIn('id', CorporateBooking::query()->withoutGlobalScopes()
        ->where('status', 'confirmed')->select('tenant_id'))->orderBy('id')->get(), function () use ($bookings, &$n) {
            // even when the plan no longer has the feature: what is left of the meals is the company's money
            $n += $bookings->closePast();
        });
    $this->info("closed company visits: $n · failed restaurants: $failed");
})->purpose('Close the company visits of past days: what the meals did not use goes onto the company\'s voucher');

Artisan::command('egmenu:loyalty-expire', function (LoyaltyService $loyalty, Packages $packages, WalletService $wallet) use ($forEachTenant) {
    // runs per restaurant so the tenant scope and plan rights apply
    $n = $p = $c = 0;
    $failed = $forEachTenant('egmenu:loyalty-expire', Tenant::query()->where('status', 'active')->orderBy('id')->get(), function () use ($loyalty, $packages, $wallet, &$n, &$p, &$c) {
        $n += $loyalty->expireStamps();
        $p += $packages->expire();
        $c += $wallet->expireCashback();
    });
    $this->info("expired cards: $n · expired packages: $p · expired cashback lines: $c · failed restaurants: $failed");
})->purpose('Clear loyalty stamps older than each program allows, close prepaid packages past their date, take back expired cashback');

Artisan::command('egmenu:campaigns', function (Campaigns $campaigns) {
    $this->info('campaigns started: '.$campaigns->startDue());
})->purpose('Start the WhatsApp campaigns scheduled until now');
Artisan::command('egmenu:daily-digest', function (DailyDigest $digest) {
    $this->info('daily digests sent: '.$digest->run());
})->purpose('Send owners and managers yesterday\'s digest: sales, cancellations, refunds, cash, open orders, visits, low stock');

Artisan::command('egmenu:weekly-report', function (WeeklyReportMailer $mailer) {
    $this->info('weekly reports sent: '.$mailer->run());
})->purpose('Email last week\'s numbers to the owners who turned the weekly report on (advanced reports)');

Artisan::command('egmenu:auto-cancel', function (AutoCancel $autoCancel) use ($forEachTenant) {
    // every minute, so only the restaurants that set online_auto_cancel_minutes (0 = off, the default) are visited
    $tenantIds = TenantSetting::query()->withoutGlobalScopes()->where('key', 'online_auto_cancel_minutes')
        ->whereNotIn('value', ['', '0'])->pluck('tenant_id');
    $n = 0;
    $failed = $forEachTenant('egmenu:auto-cancel', Tenant::query()->whereIn('id', $tenantIds)->where('status', 'active')->orderBy('id')->get(), function () use ($autoCancel, &$n) {
        $n += $autoCancel->run();
    });
    $this->info("auto-cancelled orders: $n · failed restaurants: $failed");
})->purpose('Cancel the online orders nobody accepted within the restaurant\'s time limit (wallet money goes back)');

// One cron line on the server runs everything: * * * * * php artisan schedule:run
// withoutOverlapping(N): the lock expires after N minutes, so a run killed half-way (deploy, OOM) never blocks the
// command for the default 24 hours. Daily/weekly jobs get a lock as long as they can reasonably take.
Schedule::command('egmenu:billing')->dailyAt('03:00')->withoutOverlapping(60);
Schedule::command('egmenu:auto-serve')->everyFiveMinutes()->withoutOverlapping(10);
Schedule::command('egmenu:corporate-visits')->dailyAt('04:00')->withoutOverlapping(60);
Schedule::command('egmenu:loyalty-expire')->dailyAt('03:30')->withoutOverlapping(60);
Schedule::command('egmenu:backup')->dailyAt('02:30')->withoutOverlapping(120)->runInBackground();
// per-restaurant backup zips the owners download (plans with the `backups` feature), Sunday night after the platform backup
Schedule::command('egmenu:tenant-backups')->weeklyOn(0, '03:30')->withoutOverlapping(180)->runInBackground();
Schedule::command('egmenu:campaigns')->everyMinute()->withoutOverlapping(10);
Schedule::command('egmenu:auto-cancel')->everyMinute()->withoutOverlapping(10);
Schedule::command('egmenu:daily-digest')->dailyAt('07:40')->withoutOverlapping(60);
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
