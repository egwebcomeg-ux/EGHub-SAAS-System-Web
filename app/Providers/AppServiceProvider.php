<?php

namespace App\Providers;

use App\Billing\Gateways\KashierGateway;
use App\Billing\Gateways\PaymentGateway;
use App\Http\Middleware\ThrottlePerRoute;
use App\Models\Domain;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionAddon;
use App\Models\Tenant;
use App\Services\Geocoding\Geocoder;
use App\Services\Geocoding\NominatimGeocoder;
use App\Services\Geocoding\NullGeocoder;
use App\Services\Notify\ReservationNotifier;
use App\Services\Notify\WhatsAppReservationNotifier;
use App\Services\PageCache;
use App\Services\WhatsApp\Drivers\LogDriver;
use App\Services\WhatsApp\Drivers\MetaCloudDriver;
use App\Services\WhatsApp\Drivers\WebhookDriver;
use App\Services\WhatsApp\WhatsAppGateway;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // `throttle`: one budget per route, not one for every guest page of the domain (ThrottlePerRoute). Bound in the
        // framework class's place, so withoutMiddleware(ThrottleRequests::class) still switches it off.
        $this->app->bind(ThrottleRequests::class, ThrottlePerRoute::class);
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
        // the street of a delivery pin and the checkout map's search (config geocoding.driver): nominatim | none
        $this->app->bind(Geocoder::class, fn () => match (config('geocoding.driver')) {
            'nominatim' => new NominatimGeocoder,
            default => new NullGeocoder,
        });
        // one list of purges per request or command (App\Services\PageCache); a queue worker flushes it after each job
        $this->app->singleton(PageCache::class);
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
        // Guests placing orders from a menu, and calling the waiter / asking for the bill: per guest's browser, since a
        // whole café shares one Wi-Fi address, with a per-address ceiling for scripts that drop their cookies.
        $perGuest = fn (string $name, int $perGuest, int $perAddress) => fn (Request $r) => [
            Limit::perMinute($perGuest)->by($name.':'.($r->hasSession() ? $r->session()->getId() : $r->ip()).'|'.$r->path()),
            Limit::perMinute($perAddress)->by($name.'-ip:'.$r->ip().'|'.$r->path()),
        ];
        RateLimiter::for('menu-orders', $perGuest('mo', 6, 60));
        RateLimiter::for('table-calls', $perGuest('tc', 6, 60));
        // The checkout map asks the street of the pin (debounced while dragging) and searches places: per visitor
        RateLimiter::for('geo-reverse', fn (Request $r) => Limit::perMinute(30)->by('geor:'.$r->ip()));
        RateLimiter::for('geo-search', fn (Request $r) => Limit::perMinute(20)->by('geos:'.$r->ip()));
        // the same for the call center's map and address suggestions: per signed-in agent of the restaurant
        RateLimiter::for('staff-geo-reverse', fn (Request $r) => Limit::perMinute(40)->by('sgr:'.tenant()?->getTenantKey().'|'.$r->user()?->id));
        RateLimiter::for('staff-geo-search', fn (Request $r) => Limit::perMinute(30)->by('sgs:'.tenant()?->getTenantKey().'|'.$r->user()?->id));
        // The guest pager page polls every 5 s: a budget per page and device (many guests share the shop's Wi-Fi).
        RateLimiter::for('pager', fn (Request $r) => Limit::perMinute(40)->by($r->ip().'|'.$r->path()));
        // the pager's "picked up" and rating: per page too, so a busy café on one Wi-Fi never blocks its guests
        RateLimiter::for('pager-actions', fn (Request $r) => Limit::perMinute(10)->by('pa:'.$r->ip().'|'.$r->path()));
        // the EgHub driver app's background fixes (one every ~20 s per delivery, a few more after a dead zone)
        RateLimiter::for('native-track', fn (Request $r) => Limit::perMinute(12)->by('nt:'.$r->route('order')));
        // The website assistant costs money per answer: a chat's worth per visitor, and a daily ceiling for everyone.
        RateLimiter::for('assistant', fn (Request $r) => [
            Limit::perMinutes(10, 20)->by('as:'.$r->ip()),
            Limit::perDay(80)->by('asd:'.$r->ip()),
            Limit::perDay(3000)->by('as:all'),
        ]);

        $this->bootPageCache();

        // The admin plan pickers (new restaurant, a restaurant's plan change) never offer an archived plan; the
        // restaurant's own current plan stays listed so saving its page does not switch it by accident.
        // App\Models\Subscription refuses the move onto an archived plan anyway.
        View::composer(['admin.tenants.create', 'admin.tenants.show'], function ($view) {
            $plans = $view->getData()['plans'] ?? null;
            if ($plans instanceof Collection) {
                $current = $view->getData()['tenant']->subscription?->plan_id ?? null;
                $view->with('plans', $plans->reject(fn (Plan $p) => $p->isArchived() && $p->id !== $current)->values());
            }
        });
    }

    /**
     * The LiteSpeed page cache's purges (App\Services\PageCache): every write to a table the public pages read
     * queues the purge of the current restaurant (or of the platform pages, or of everything for plans and site
     * settings); the restaurant's central rows (the restaurant itself: suspension, plan, domains) purge it by their
     * own id, also from the admin where no restaurant is current. Queue jobs send theirs when each job ends;
     * `php artisan optimize` (every deploy) purges everything: the pages point at the new build files.
     */
    private function bootPageCache(): void
    {
        DB::listen(fn (QueryExecuted $query) => $this->app->make(PageCache::class)->recordWrite($query->sql));

        $purgeTenant = function (Model $model): void {
            $tenantId = $model instanceof Tenant ? $model->getKey() : $model->getAttribute('tenant_id');
            if (is_string($tenantId) && $tenantId !== '') {
                $cache = $this->app->make(PageCache::class);
                $cache->purgeTenant($tenantId);
                // the restaurant's card in the directory and on the home page showcase
                if ($model instanceof Tenant) {
                    $cache->purgeDirectory();
                    $cache->purgePlatform();
                }
            }
        };
        foreach ([Tenant::class, Subscription::class, SubscriptionAddon::class, Domain::class] as $model) {
            $model::saved($purgeTenant);
            $model::deleted($purgeTenant);
        }

        Queue::after(fn () => $this->app->make(PageCache::class)->flushOverHttp());
        Queue::failing(fn () => $this->app->make(PageCache::class)->flushOverHttp());

        $this->optimizes(optimize: 'egmenu:page-cache-purge', key: 'page-cache');
    }
}
