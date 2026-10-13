<?php

use App\Http\Controllers\Platform\LandingController;
use Illuminate\Support\Facades\Route;

/*
| Hosts (first CENTRAL_DOMAINS entry, "localhost" while developing):
|   <domain>            landing pages + public menus at /{tenant}
|   app.<domain>        restaurant dashboard (tenant = logged-in user)
|   admin.<domain>      EgWeb super admin
| Every group is bound to its host, so a path on one host never matches a route of another.
*/
$domain = config('tenancy.central_domains')[0] ?? 'localhost';

Route::domain("admin.$domain")->middleware('admin.locale')->name('admin.')->group(base_path('routes/admin.php'));

Route::domain("app.$domain")->name('dash.')->group(base_path('routes/dashboard.php'));

Route::domain("www.$domain")->get('/{any?}', fn (string $any = '') => redirect()->away(platform_url(null, $any), 301))
    ->where('any', '.*');

Route::domain($domain)->group(function () {
    Route::middleware('admin.locale')->group(function () {
        Route::get('/', [LandingController::class, 'home'])->name('landing');
    });

    // Public menus. Platform paths above win; reserved slugs can never be registered as restaurants.
    Route::prefix('{tenant}')->where(['tenant' => '[a-z0-9][a-z0-9-]{1,28}[a-z0-9]'])
        ->middleware('tenant.path')->name('menu.')->group(base_path('routes/menu.php'));
});
