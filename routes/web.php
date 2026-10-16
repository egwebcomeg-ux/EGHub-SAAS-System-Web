<?php

use App\Http\Controllers\Platform\BlogController;
use App\Http\Controllers\Platform\DirectoryController;
use App\Http\Controllers\Platform\FeaturesController;
use App\Http\Controllers\Platform\LandingController;
use App\Http\Controllers\Platform\SeoController;
use App\Http\Controllers\Platform\ServicesController;
use App\Http\Controllers\Platform\SignupController;
use App\Http\Controllers\Platform\WhatsAppWebhookController;
use Illuminate\Support\Facades\Route;

/*
| Hosts (first CENTRAL_DOMAINS entry, "localhost" while developing):
|   <domain>            landing pages, restaurant directory, sitemap; /{tenant}/... = a restaurant's menu (canonical)
|   app.<domain>        restaurant dashboard (tenant = logged-in user)
|   admin.<domain>      EgWeb super admin
|   {tenant}.<domain>   the same menu on a short alias (routes named menu.*, canonical to <domain>/{tenant})
|   any other host      a restaurant's verified custom domain
| Every group is bound to its host, so a path on one host never matches a route of another.
*/
$domain = config('tenancy.central_domains')[0] ?? 'localhost';
$slug = '[a-z0-9][a-z0-9-]{1,28}[a-z0-9]';

// Dashboard and admin are never indexed.
Route::domain("admin.$domain")->get('/robots.txt', [SeoController::class, 'robotsPrivate']);
Route::domain("app.$domain")->get('/robots.txt', [SeoController::class, 'robotsPrivate']);

Route::domain("admin.$domain")->middleware('admin.locale')->name('admin.')->group(base_path('routes/admin.php'));

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
        // blog for cafe and restaurant owners (App\Models\Post), plus its RSS feed
        Route::get('/blog', [BlogController::class, 'index'])->name('blog');
        Route::get('/blog/{slug}', [BlogController::class, 'show'])->where('slug', '[a-z0-9-]{2,120}')->name('blog.show');
        Route::get('/rss.xml', [BlogController::class, 'rss'])->name('blog.rss');
        Route::get('/terms', [LandingController::class, 'terms'])->name('terms');
        Route::get('/privacy', [LandingController::class, 'privacy'])->name('privacy');
        Route::get('/register', [SignupController::class, 'show'])->name('register');
        Route::post('/register', [SignupController::class, 'store'])->middleware('throttle:signup');
        Route::get('/register/check', [SignupController::class, 'check'])->middleware('throttle:60,1')->name('register.check');
        // restaurant directory (only places that opted in, see App\Services\Seo\Directory)
        Route::get('/restaurants', [DirectoryController::class, 'index'])->name('directory');
        Route::get('/restaurants/{city}', [DirectoryController::class, 'city'])->where('city', '[a-z0-9-]{2,40}')->name('directory.city');
        Route::get('/restaurants/{city}/{cuisine}', [DirectoryController::class, 'cuisine'])
            ->where(['city' => '[a-z0-9-]{2,40}', 'cuisine' => '[a-z]{2,30}'])->name('directory.cuisine');
    });
    Route::get('/sitemap.xml', [SeoController::class, 'sitemap'])->name('sitemap');
    Route::get('/robots.txt', [SeoController::class, 'robots'])->name('robots');
    // Inbound WhatsApp (Meta Cloud API handshake + signed payloads, or another provider with X-EgMenu-Secret).
    Route::get('/webhooks/whatsapp', [WhatsAppWebhookController::class, 'verify'])->name('webhooks.whatsapp');
    Route::post('/webhooks/whatsapp', [WhatsAppWebhookController::class, 'receive'])->middleware('throttle:600,1');

    // The canonical menu address egmenu.app/{tenant}/... (IdentifyTenantByPath). Platform paths above win;
    // reserved slugs (Tenant::RESERVED) can never be registered as restaurants.
    Route::prefix('{tenant}')->where(['tenant' => $slug])
        ->middleware('tenant.path')->name('path.')->group(base_path('routes/menu.php'));
});

// Public menus on {tenant}.egmenu.app (alias of egmenu.app/{tenant}). The platform hosts (www, app, admin) are excluded here as well as by their
// own groups above, so a path those groups do not define is a 404 there, never a menu.
Route::domain('{tenant}.'.$domain)->where(['tenant' => '(?!(?:www|app|admin)\.)'.$slug])
    ->middleware('tenant.subdomain')->name('menu.')->group(base_path('routes/menu.php'));

// robots.txt of restaurant subdomains and custom domains (the hosts above have their own).
Route::get('/robots.txt', [SeoController::class, 'robotsMenu']);

// A restaurant's own domain (menu.cafe.com): the same menu routes. Registered last and not
// bound to a host, so it only catches hosts that none of the groups above own.
Route::middleware('tenant.domain')->name('custom.')->group(base_path('routes/menu.php'));
