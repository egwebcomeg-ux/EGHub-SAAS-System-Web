<?php

use App\Http\Controllers\Platform\AppsController;
use App\Http\Controllers\Platform\AssistantController;
use App\Http\Controllers\Platform\BillingWebhookController;
use App\Http\Controllers\Platform\BlogController;
use App\Http\Controllers\Platform\DirectoryController;
use App\Http\Controllers\Platform\FeaturesController;
use App\Http\Controllers\Platform\LandingController;
use App\Http\Controllers\Platform\QrToolController;
use App\Http\Controllers\Platform\ScreenPlayerController;
use App\Http\Controllers\Platform\SeoController;
use App\Http\Controllers\Platform\ServicesController;
use App\Http\Controllers\Platform\SignupController;
use App\Http\Controllers\Platform\SupportedScreensController;
use App\Http\Controllers\Platform\WhatsAppWebhookController;
use App\Models\Payment;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/*
| Hosts (first CENTRAL_DOMAINS entry, "localhost" while developing):
|   <domain>            landing pages, restaurant directory, sitemap; /{tenant}/... = a restaurant's menu (canonical)
|   app.<domain>        restaurant dashboard (tenant = logged-in user)
|   admin.<domain>      EgWeb super admin
|   {tenant}.<domain>   the same menu on a short alias (routes named menu.*, canonical to <domain>/{tenant})
|   any other host      a restaurant's verified custom domain
| Earlier main domains (CENTRAL_DOMAINS after the first, legacy_domains()): the restaurant directory on the first of
| them; every other address redirects permanently to the same one on the main domain.
| Every group is bound to its host, so a path on one host never matches a route of another.
*/
$domain = config('tenancy.central_domains')[0] ?? 'localhost';
$slug = '[a-z0-9][a-z0-9-]{1,28}[a-z0-9]';

// Dashboard and admin are never indexed.
Route::domain("admin.$domain")->get('/robots.txt', [SeoController::class, 'robotsPrivate']);
Route::domain("app.$domain")->get('/robots.txt', [SeoController::class, 'robotsPrivate']);

Route::domain("admin.$domain")->middleware(['admin.locale', 'admin.idle'])->name('admin.')->group(base_path('routes/admin.php'));

Route::domain("app.$domain")->name('dash.')->group(base_path('routes/dashboard.php'));

Route::domain("www.$domain")->get('/{any?}', fn (string $any = '') => redirect()->away(platform_url(null, $any), 301))
    ->where('any', '.*');

Route::domain($domain)->group(function () use ($slug) {
    Route::middleware('admin.locale')->group(function () {
        Route::get('/', [LandingController::class, 'home'])->name('landing');
        Route::get('/pricing', [LandingController::class, 'pricing'])->name('pricing');
        Route::get('/services', [ServicesController::class, 'index'])->name('services');
        Route::post('/services', [ServicesController::class, 'store'])->middleware('throttle:5,1')->name('services.request');
        Route::get('/features', [FeaturesController::class, 'index'])->name('features');
        Route::get('/features/{slug}', [FeaturesController::class, 'show'])->where('slug', '[a-z-]{2,40}')->name('features.show');
        // the EgHub Android apps: what each does and whether it can be downloaded yet (App\Support\EgHubApps)
        Route::get('/apps', [AppsController::class, 'index'])->name('apps');
        // blog for cafe and restaurant owners (App\Models\Post), plus its RSS feed
        Route::get('/blog', [BlogController::class, 'index'])->name('blog');
        Route::get('/blog/{slug}', [BlogController::class, 'show'])->where('slug', '[a-z0-9-]{2,120}')->name('blog.show');
        Route::get('/rss.xml', [BlogController::class, 'rss'])->name('blog.rss');
        // the website assistant ("اسألنا"): one chat turn per request, costs money → its own limiter
        Route::post('/assistant', [AssistantController::class, 'chat'])->middleware('throttle:assistant')->name('assistant');
        // free tools: the styled QR code generator (the browser draws the code, the server only returns its module matrix)
        Route::get('/qr-code-generator', [QrToolController::class, 'show'])->name('tools.qr');
        Route::get('/supported-screens', SupportedScreensController::class)->name('screens.supported');
        Route::post('/qr-code-generator/matrix', [QrToolController::class, 'matrix'])->middleware('throttle:60,1')->name('tools.qr.matrix');
        Route::get('/terms', [LandingController::class, 'terms'])->name('terms');
        Route::get('/privacy', [LandingController::class, 'privacy'])->name('privacy');
        Route::get('/refund-policy', [LandingController::class, 'refund'])->name('refund');
        Route::get('/register', [SignupController::class, 'show'])->name('register');
        Route::post('/register', [SignupController::class, 'store'])->middleware('throttle:signup');
        Route::get('/register/check', [SignupController::class, 'check'])->middleware('throttle:60,1')->name('register.check');
        // restaurant directory (only places that opted in, see App\Services\Seo\Directory); it moves to the earlier
        // domain when there is one (below), and its old addresses here follow it
        if (legacy_domains() === []) {
            Route::get('/restaurants', [DirectoryController::class, 'index'])->name('directory');
            Route::get('/restaurants/{city}', [DirectoryController::class, 'city'])->where('city', '[a-z0-9-]{2,40}')->name('directory.city');
            Route::get('/restaurants/{city}/{cuisine}', [DirectoryController::class, 'cuisine'])
                ->where(['city' => '[a-z0-9-]{2,40}', 'cuisine' => '[a-z]{2,30}'])->name('directory.cuisine');
        } else {
            Route::get('/restaurants/{any?}', fn (Request $request) => redirect()->away(
                scheme().'://'.directory_domain().port_suffix().'/'.$request->path().(($q = $request->getQueryString()) ? '?'.$q : ''), 301
            ))->where('any', '.*');
        }
    });
    // EgHub screens: the universal TV player (App\Http\Controllers\Platform\ScreenPlayerController). /tv is the short
    // address typed with a remote ("tv" can never be a restaurant: slugs have 3+ characters). Its data calls carry the
    // device token, not a session: no session cookie, no CSRF.
    Route::get('/tv', fn (Request $request) => redirect('/screen'.(($q = $request->getQueryString()) ? '?'.$q : '')));
    Route::get('/screen', [ScreenPlayerController::class, 'show'])->name('screen')
        ->withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, PreventRequestForgery::class]);
    Route::prefix('/screen')->name('screen.')->withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, PreventRequestForgery::class])
        ->group(function () {
            Route::get('/sw.js', [ScreenPlayerController::class, 'serviceWorker'])->name('sw');
            Route::post('/pair', [ScreenPlayerController::class, 'startPairing'])->middleware('throttle:10,1')->name('pair');
            Route::get('/pair/status', [ScreenPlayerController::class, 'pairStatus'])->middleware('throttle:40,1')->name('pair.status');
            Route::get('/playlist', [ScreenPlayerController::class, 'playlist'])->middleware('throttle:30,1')->name('playlist');
            Route::get('/version', [ScreenPlayerController::class, 'version'])->middleware('throttle:120,1')->name('version');
            Route::get('/ready', [ScreenPlayerController::class, 'ready'])->middleware('throttle:120,1')->name('ready');
            Route::post('/heartbeat', [ScreenPlayerController::class, 'heartbeat'])->middleware('throttle:20,1')->name('heartbeat');
            Route::post('/rotate', [ScreenPlayerController::class, 'rotate'])->middleware('throttle:5,1')->name('rotate');
        });
    Route::get('/sitemap.xml', [SeoController::class, 'sitemap'])->name('sitemap');
    Route::get('/robots.txt', [SeoController::class, 'robots'])->name('robots');
    // Inbound WhatsApp (Meta Cloud API handshake + signed payloads, or another provider with X-EgMenu-Secret).
    Route::get('/webhooks/whatsapp', [WhatsAppWebhookController::class, 'verify'])->name('webhooks.whatsapp');
    Route::post('/webhooks/whatsapp', [WhatsAppWebhookController::class, 'receive'])->middleware('throttle:600,1');
    // A restaurant's own WhatsApp number: its own Meta app calls this URL (shown on its dashboard) and signs with its
    // own app secret (App\Models\WhatsAppConnection).
    Route::get('/webhooks/whatsapp/{key}', [WhatsAppWebhookController::class, 'verifyOwn'])->where('key', '[a-f0-9]{32}')
        ->middleware('throttle:60,1')->name('webhooks.whatsapp.own');
    Route::post('/webhooks/whatsapp/{key}', [WhatsAppWebhookController::class, 'receiveOwn'])->where('key', '[a-f0-9]{32}')
        ->middleware('throttle:600,1');

    // The canonical menu address eghub.app/{tenant}/... (IdentifyTenantByPath). Platform paths above win;
    // reserved slugs (Tenant::RESERVED) can never be registered as restaurants.
    Route::prefix('{tenant}')->where(['tenant' => $slug])
        ->middleware('tenant.path')->name('path.')->group(base_path('routes/menu.php'));
});

// Public menus on {tenant}.egmenu.app (alias of eghub.app/{tenant}). The platform hosts (www, app, admin) are excluded here as well as by their
// own groups above, so a path those groups do not define is a 404 there, never a menu.
Route::domain('{tenant}.'.$domain)->where(['tenant' => '(?!(?:www|app|admin)\.)'.$slug])
    ->middleware('tenant.subdomain')->name('menu.')->group(base_path('routes/menu.php'));

/*
| Earlier main domains (egmenu.app after the move to eghub.app). The first one keeps the public restaurant directory
| (its home page) and its old /restaurants/{city} addresses; everything else, including every printed QR code
| ({tenant}.egmenu.app/..., eghub.app/{tenant}/...), the dashboard and the admin, answers with a permanent redirect
| to the same address on the main domain (308 for forms, so the method survives). The payment webhook is still
| served here: a payment started before the move reports to the address it was given.
*/
$toMainDomain = fn (Request $request, ?string $sub = null) => redirect()->away(
    platform_url($sub, $request->path() === '/' ? '/' : $request->path()).(($q = $request->getQueryString()) ? '?'.$q : ''),
    in_array($request->method(), ['GET', 'HEAD'], true) ? 301 : 308
);
foreach (legacy_domains() as $index => $legacy) {
    if ($index === 0) {
        Route::domain($legacy)->group(function () {
            Route::middleware('admin.locale')->group(function () {
                Route::get('/', [DirectoryController::class, 'index'])->name('directory');
                Route::get('/restaurants', fn (Request $request) => redirect()->away(
                    route('directory').(($q = $request->getQueryString()) ? '?'.$q : ''), 301
                ));
                Route::get('/restaurants/{city}', [DirectoryController::class, 'city'])->where('city', '[a-z0-9-]{2,40}')->name('directory.city');
                Route::get('/restaurants/{city}/{cuisine}', [DirectoryController::class, 'cuisine'])
                    ->where(['city' => '[a-z0-9-]{2,40}', 'cuisine' => '[a-z]{2,30}'])->name('directory.cuisine');
            });
            Route::get('/sitemap.xml', [SeoController::class, 'directorySitemap'])->name('directory.sitemap');
            Route::get('/robots.txt', [SeoController::class, 'directoryRobots']);
        });
    }
    Route::domain("app.$legacy")->post('/billing/{gateway}/webhook', [BillingWebhookController::class, 'receive'])
        ->whereIn('gateway', Payment::ONLINE_GATEWAYS)->middleware('throttle:120,1');
    Route::domain("www.$legacy")->any('/{any?}', fn (Request $request) => redirect()->away(
        scheme().'://'.$legacy.port_suffix().'/'.ltrim($request->path(), '/'), 301
    ))->where('any', '.*');
    Route::domain('{sub}.'.$legacy)->where(['sub' => '[a-z0-9][a-z0-9-]{0,61}'])
        ->any('/{any?}', fn (Request $request, string $sub) => $toMainDomain($request, $sub))->where('any', '.*');
    Route::domain($legacy)->any('/{any?}', fn (Request $request) => $toMainDomain($request))->where('any', '.*');
}

// robots.txt of restaurant subdomains and custom domains (the hosts above have their own).
Route::get('/robots.txt', [SeoController::class, 'robotsMenu']);

// A restaurant's own domain (menu.cafe.com): the same menu routes. Registered last and not
// bound to a host, so it only catches hosts that none of the groups above own.
Route::middleware('tenant.domain')->name('custom.')->group(base_path('routes/menu.php'));
