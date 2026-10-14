<?php

use App\Http\Controllers\Platform\FeaturesController;
use App\Http\Controllers\Platform\LandingController;
use App\Http\Controllers\Platform\SignupController;
use Illuminate\Support\Facades\Route;

/*
| Hosts (first CENTRAL_DOMAINS entry, "localhost" while developing):
|   <domain>            landing pages; old menu links /{tenant}/... answer 301 to the subdomain
|   app.<domain>        restaurant dashboard (tenant = logged-in user)
|   admin.<domain>      EgWeb super admin
|   {tenant}.<domain>   public menu of one restaurant (canonical address, routes named menu.*)
|   any other host      a restaurant's verified custom domain
| Every group is bound to its host, so a path on one host never matches a route of another.
*/
$domain = config('tenancy.central_domains')[0] ?? 'localhost';
$slug = '[a-z0-9][a-z0-9-]{1,28}[a-z0-9]';

Route::domain("admin.$domain")->middleware('admin.locale')->name('admin.')->group(base_path('routes/admin.php'));

Route::domain("app.$domain")->name('dash.')->group(base_path('routes/dashboard.php'));

Route::domain("www.$domain")->get('/{any?}', fn (string $any = '') => redirect()->away(platform_url(null, $any), 301))
    ->where('any', '.*');

Route::domain($domain)->group(function () use ($slug) {
    Route::middleware('admin.locale')->group(function () {
        Route::get('/', [LandingController::class, 'home'])->name('landing');
        Route::get('/pricing', [LandingController::class, 'pricing'])->name('pricing');
        Route::get('/features', [FeaturesController::class, 'index'])->name('features');
        Route::get('/features/{slug}', [FeaturesController::class, 'show'])->where('slug', '[a-z-]{2,40}')->name('features.show');
        Route::get('/terms', [LandingController::class, 'terms'])->name('terms');
        Route::get('/privacy', [LandingController::class, 'privacy'])->name('privacy');
        Route::get('/register', [SignupController::class, 'show'])->name('register');
        Route::post('/register', [SignupController::class, 'store'])->middleware('throttle:signup');
        Route::get('/register/check', [SignupController::class, 'check'])->middleware('throttle:60,1')->name('register.check');
    });

    // Old menu address egmenu.app/{tenant}/...: GET → 301 to {tenant}.egmenu.app, POST still served (see
    // IdentifyTenantByPath). Platform paths above win; reserved slugs can never be registered as restaurants.
    Route::prefix('{tenant}')->where(['tenant' => $slug])
        ->middleware('tenant.path')->name('path.')->group(base_path('routes/menu.php'));
});

// Public menus on {tenant}.egmenu.app. The platform hosts (www, app, admin) are excluded here as well as by their
// own groups above, so a path those groups do not define is a 404 there, never a menu.
Route::domain('{tenant}.'.$domain)->where(['tenant' => '(?!(?:www|app|admin)\.)'.$slug])
    ->middleware('tenant.subdomain')->name('menu.')->group(base_path('routes/menu.php'));

// A restaurant's own domain (menu.cafe.com): the same menu routes. Registered last and not
// bound to a host, so it only catches hosts that none of the groups above own.
Route::middleware('tenant.domain')->name('custom.')->group(base_path('routes/menu.php'));
