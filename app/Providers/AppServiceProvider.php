<?php

namespace App\Providers;

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
        //
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
