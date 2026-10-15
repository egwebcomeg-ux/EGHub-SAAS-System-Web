<?php

namespace App\Providers;

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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Defined here, not in bootstrap/app.php's routing callback: that callback never runs when routes are
        // cached in production, which left every throttled route failing with "Rate limiter [...] is not defined".
        RateLimiter::for('login', fn (Request $r) => Limit::perMinute(8)->by($r->ip().'|'.$r->getHost()));
        RateLimiter::for('signup', fn (Request $r) => Limit::perHour(5)->by($r->ip()));
        // Guests placing orders from a menu: generous for a table of friends, tight for scripts.
        RateLimiter::for('menu-orders', fn (Request $r) => Limit::perMinute(6)->by($r->ip().'|'.$r->path()));
    }
}
