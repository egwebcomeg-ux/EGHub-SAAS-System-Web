<?php

namespace App\Providers;

use App\Billing\Gateways\KashierGateway;
use App\Billing\Gateways\PaymentGateway;
use App\Services\Notify\ReservationNotifier;
use App\Services\Notify\WhatsAppReservationNotifier;
use App\Services\WhatsApp\Drivers\LogDriver;
use App\Services\WhatsApp\Drivers\MetaCloudDriver;
use App\Services\WhatsApp\Drivers\WebhookDriver;
use App\Services\WhatsApp\WhatsAppGateway;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // WhatsApp provider from WHATSAPP_DRIVER: log (default, nothing leaves the server) | meta | webhook
        $this->app->bind(WhatsAppGateway::class, fn () => match (config('whatsapp.driver')) {
            'meta' => new MetaCloudDriver,
            'webhook' => new WebhookDriver,
            default => new LogDriver,
        });
        // Reservations (built separately) call this when a booking is confirmed; it is a no-op unless the
        // restaurant has WhatsApp notifications and the "reservation confirmed" toggle on.
        $this->app->bind(ReservationNotifier::class, WhatsAppReservationNotifier::class);
        // Online subscription payments: Kashier is the gateway EgWeb has a contract with (App\Billing\OnlinePayments)
        $this->app->bind(PaymentGateway::class, KashierGateway::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Defined here, not in bootstrap/app.php's routing callback: that callback never runs when routes are
        // cached in production, which left every throttled route failing with "Rate limiter [...] is not defined".
        // Login (dashboard "login" field, admin "email" field): per visitor, and per account so a botnet rotating IPs
        // still gets only 5 tries a minute at one email or phone.
        RateLimiter::for('login', function (Request $r) {
            $account = $r->input('login', $r->input('email'));

            return [
                Limit::perMinute(8)->by('ip:'.$r->ip().'|'.$r->getHost()),
                Limit::perMinute(5)->by('acct:'.(is_string($account) ? mb_strtolower(trim($account)) : '').'|'.$r->getHost()),
            ];
        });
        RateLimiter::for('signup', fn (Request $r) => Limit::perHour(5)->by($r->ip()));
        // Guests placing orders from a menu: generous for a table of friends, tight for scripts.
        RateLimiter::for('menu-orders', fn (Request $r) => Limit::perMinute(6)->by($r->ip().'|'.$r->path()));
        // The guest pager page polls every 5 s: a budget per page and device (many guests share the shop's Wi-Fi).
        RateLimiter::for('pager', fn (Request $r) => Limit::perMinute(40)->by($r->ip().'|'.$r->path()));
        // The website assistant costs money per answer: a chat's worth per visitor, and a daily ceiling for everyone.
        RateLimiter::for('assistant', fn (Request $r) => [
            Limit::perMinutes(10, 20)->by('as:'.$r->ip()),
            Limit::perDay(80)->by('asd:'.$r->ip()),
            Limit::perDay(3000)->by('as:all'),
        ]);
    }
}
