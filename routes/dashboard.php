<?php

use App\Http\Controllers\Dashboard\AuthController;
use App\Http\Controllers\Dashboard\BillingController;
use App\Http\Controllers\Dashboard\BranchMenuController;
use App\Http\Controllers\Dashboard\BranchesController;
use App\Http\Controllers\Dashboard\CategoriesController;
use App\Http\Controllers\Dashboard\CustomersController;
use App\Http\Controllers\Dashboard\FeedbackController;
use App\Http\Controllers\Dashboard\HomeController;
use App\Http\Controllers\Dashboard\InventoryController;
use App\Http\Controllers\Dashboard\ItemsController;
use App\Http\Controllers\Dashboard\KitchenController;
use App\Http\Controllers\Dashboard\LoyaltyController;
use App\Http\Controllers\Dashboard\MarketingController;
use App\Http\Controllers\Dashboard\MenuImportController;
use App\Http\Controllers\Dashboard\OrdersController;
use App\Http\Controllers\Dashboard\PosController;
use App\Http\Controllers\Dashboard\PrintMenuController;
use App\Http\Controllers\Dashboard\ReportsController;
use App\Http\Controllers\Dashboard\SettingsController;
use App\Http\Controllers\Dashboard\ShiftsController;
use App\Http\Controllers\Dashboard\StationsController;
use App\Http\Controllers\Dashboard\CallCenterController;
use App\Http\Controllers\Dashboard\DeliveryZonesController;
use App\Http\Controllers\Dashboard\TablesController;
use App\Http\Controllers\Dashboard\TvScreensController;
use App\Http\Controllers\Dashboard\UsersController;
use Illuminate\Support\Facades\Route;

// app.<domain> — restaurant dashboard. The tenant is the logged-in user's restaurant (InitializeTenancyFromUser).
Route::middleware('admin.locale')->group(function () {
    Route::get('/login', [AuthController::class, 'show'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::get('/welcome/{user}', [AuthController::class, 'welcome'])->whereNumber('user')->name('welcome');
});

Route::middleware(['auth:web', 'tenant.user'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::post('/locale', [AuthController::class, 'locale'])->name('locale');

    Route::get('/', [HomeController::class, 'index'])->name('home');

    Route::middleware('permission:users.manage')->group(function () {
        Route::get('/users', [UsersController::class, 'index'])->name('users');
        Route::post('/users', [UsersController::class, 'store'])->name('users.store');
        Route::post('/users/{user}', [UsersController::class, 'update'])->name('users.update');
    });

    Route::middleware('permission:billing.manage')->group(function () {
        Route::get('/billing', [BillingController::class, 'index'])->name('billing');
        Route::post('/billing/checkout', [BillingController::class, 'checkout'])->name('billing.checkout');
        Route::post('/billing/addons/{key}', [BillingController::class, 'addon'])->where('key', '[a-z_]+')->name('billing.addon');
        Route::get('/billing/invoices/{number}', [BillingController::class, 'invoice'])->name('billing.invoice');
        Route::post('/billing/invoices/{number}/claim', [BillingController::class, 'claim'])->middleware('throttle:10,1')->name('billing.claim');
    });

    Route::middleware('permission:settings.manage')->group(function () {
        Route::get('/settings', [SettingsController::class, 'edit'])->name('settings');
        Route::post('/settings', [SettingsController::class, 'update'])->name('settings.update');
        Route::post('/settings/ai-test', [SettingsController::class, 'testAi'])->middleware('throttle:6,1')->name('settings.ai-test');
    });

    // Menu editor
    Route::middleware('permission:menu.manage')->group(function () {
        Route::get('/menu', [ItemsController::class, 'index'])->name('menu');
        Route::get('/menu/items/create', [ItemsController::class, 'create'])->name('items.create');
        Route::post('/menu/items', [ItemsController::class, 'store'])->name('items.store');
        Route::get('/menu/items/{item}', [ItemsController::class, 'edit'])->name('items.edit');
        Route::post('/menu/items/{item}', [ItemsController::class, 'update'])->name('items.update');
        Route::post('/menu/items/{item}/delete', [ItemsController::class, 'destroy'])->name('items.delete');
        Route::post('/menu/items-order', [ItemsController::class, 'reorder'])->name('items.reorder');
        Route::post('/menu/categories', [CategoriesController::class, 'store'])->name('categories.store');
        Route::post('/menu/categories/{category}', [CategoriesController::class, 'update'])->name('categories.update');
        Route::post('/menu/categories/{category}/delete', [CategoriesController::class, 'destroy'])->name('categories.delete');
        Route::post('/menu/categories-order', [CategoriesController::class, 'reorder'])->name('categories.reorder');

        Route::get('/menu/print', [PrintMenuController::class, 'show'])->middleware('feature:printed_menu')->name('menu.print');

        // import from Excel or from photos/PDF (AI with the restaurant's own key)
        Route::get('/menu/import', [MenuImportController::class, 'index'])->name('import');
        Route::get('/menu/import/template', [MenuImportController::class, 'template'])->name('import.template');
        Route::post('/menu/import/excel', [MenuImportController::class, 'excel'])->name('import.excel');
        Route::post('/menu/import/ai', [MenuImportController::class, 'ai'])->middleware('throttle:10,1')->name('import.ai');
        Route::get('/menu/import/{import}', [MenuImportController::class, 'review'])->name('import.review');
        Route::get('/menu/import/{import}/status', [MenuImportController::class, 'status'])->name('import.status');
        Route::post('/menu/import/{import}/apply', [MenuImportController::class, 'apply'])->name('import.apply');
    });
    Route::post('/menu/items/{item}/toggle', [ItemsController::class, 'toggle'])
        ->middleware('permission:menu.manage|menu.availability')->name('items.toggle');

    Route::middleware('permission:branches.manage')->group(function () {
        Route::get('/branches', [BranchesController::class, 'index'])->name('branches');
        Route::post('/branches', [BranchesController::class, 'store'])->name('branches.store');
        Route::post('/branches/{branch}', [BranchesController::class, 'update'])->name('branches.update');
        Route::get('/branches/{branch}/menu', [BranchMenuController::class, 'edit'])->middleware('feature:branch_pricing')->name('branches.menu');
        Route::post('/branches/{branch}/menu', [BranchMenuController::class, 'update'])->middleware('feature:branch_pricing')->name('branches.menu.update');
    });

    Route::middleware('permission:tables.manage')->group(function () {
        Route::get('/tables', [TablesController::class, 'index'])->name('tables');
        Route::get('/tables/print', [TablesController::class, 'print'])->name('tables.print');
        Route::post('/tables', [TablesController::class, 'store'])->name('tables.store');
        Route::post('/tables/{table}', [TablesController::class, 'update'])->name('tables.update');
        Route::post('/tables/{table}/rotate', [TablesController::class, 'rotate'])->name('tables.rotate');
        Route::post('/tables/{table}/delete', [TablesController::class, 'destroy'])->name('tables.delete');
    });

    // Live order board (polling)
    Route::middleware(['feature:order_board', 'permission:orders.view'])->group(function () {
        Route::get('/orders', [OrdersController::class, 'board'])->name('orders');
        Route::get('/orders/feed', [OrdersController::class, 'feed'])->name('orders.feed');
        Route::post('/orders/{order}/status', [OrdersController::class, 'transition'])
            ->middleware('permission:orders.manage')->name('orders.status');
    });

    Route::get('/orders/{order}/receipt', [OrdersController::class, 'receipt'])
        ->middleware('permission:orders.view|pos.use')->name('orders.receipt');

    // ---- Reports (daily on Pro, advanced on Business) ----
    Route::middleware(['feature:daily_reports', 'permission:reports.view'])->group(function () {
        Route::get('/reports', [ReportsController::class, 'index'])->name('reports');
        Route::get('/reports/export', [ReportsController::class, 'export'])->name('reports.export');
    });

    // ---- Marketing: timed offers & popups ----
    Route::middleware(['feature:offers', 'permission:marketing.manage'])->group(function () {
        Route::get('/marketing', [MarketingController::class, 'index'])->name('marketing');
        Route::post('/marketing/offers', [MarketingController::class, 'saveOffer'])->name('offers.store');
        Route::post('/marketing/offers/{offer}', [MarketingController::class, 'saveOffer'])->name('offers.update');
        Route::post('/marketing/offers/{offer}/toggle', [MarketingController::class, 'toggleOffer'])->name('offers.toggle');
        Route::post('/marketing/offers/{offer}/delete', [MarketingController::class, 'deleteOffer'])->name('offers.delete');
        Route::post('/marketing/popups', [MarketingController::class, 'savePopup'])->name('popups.store');
        Route::post('/marketing/popups/{popup}/toggle', [MarketingController::class, 'togglePopup'])->name('popups.toggle');
        Route::post('/marketing/popups/{popup}/delete', [MarketingController::class, 'deletePopup'])->name('popups.delete');
    });

    // ---- Guests: customers, loyalty card, feedback ----
    Route::middleware(['feature:customers', 'permission:customers.view'])->group(function () {
        Route::get('/customers', [CustomersController::class, 'index'])->name('customers');
        Route::get('/customers/export', [CustomersController::class, 'export'])->name('customers.export');
        Route::get('/customers/{customer}', [CustomersController::class, 'show'])->name('customers.show');
        Route::post('/customers/{customer}', [CustomersController::class, 'update'])->name('customers.update');
    });
    Route::middleware(['feature:loyalty_stamps', 'permission:loyalty.manage'])->group(function () {
        Route::get('/loyalty/setup', [LoyaltyController::class, 'edit'])->name('loyalty.setup');
        Route::post('/loyalty/setup', [LoyaltyController::class, 'update'])->name('loyalty.update');
        Route::post('/loyalty/restart', [LoyaltyController::class, 'restart'])->name('loyalty.restart');
    });
    Route::middleware(['feature:loyalty_stamps', 'permission:loyalty.stamp'])->group(function () {
        Route::get('/loyalty', [LoyaltyController::class, 'desk'])->name('loyalty.desk');
        Route::post('/loyalty/enroll', [LoyaltyController::class, 'enroll'])->name('loyalty.enroll');
        Route::post('/loyalty/cards/{card}/stamp', [LoyaltyController::class, 'stamp'])->name('loyalty.stamp');
        Route::post('/loyalty/cards/{card}/claim', [LoyaltyController::class, 'claim'])->name('loyalty.claim');
        Route::post('/loyalty/rewards/{reward}/redeem', [LoyaltyController::class, 'redeem'])->name('loyalty.redeem');
    });
    Route::get('/feedback', [FeedbackController::class, 'index'])
        ->middleware(['feature:feedback', 'permission:reports.view|customers.view'])->name('feedback');

    // ---- Operations add-ons (sold separately from the plans) ----

    // POS pack: cashier screen, shifts & drawer, customer display
    Route::middleware(['feature:pos', 'permission:pos.use'])->group(function () {
        Route::get('/pos', [PosController::class, 'screen'])->name('pos');
        Route::post('/pos/orders', [PosController::class, 'store'])->name('pos.order');
        Route::post('/pos/orders/{order}/pay', [PosController::class, 'pay'])->name('pos.pay');
        Route::get('/pos/open', [PosController::class, 'open'])->name('pos.open');
        Route::get('/pos/display', [PosController::class, 'display'])->name('pos.display');
    });
    Route::middleware(['feature:shifts', 'permission:shifts.manage'])->group(function () {
        Route::get('/shifts', [ShiftsController::class, 'index'])->name('shifts');
        Route::post('/shifts/open', [ShiftsController::class, 'open'])->name('shifts.open');
        Route::post('/shifts/move', [ShiftsController::class, 'move'])->name('shifts.move');
        Route::post('/shifts/close', [ShiftsController::class, 'close'])->name('shifts.close');
    });

    // TV menu boards (Business)
    Route::middleware(['feature:tv_screens', 'permission:menu.manage'])->group(function () {
        Route::get('/tv-screens', [TvScreensController::class, 'index'])->name('tv');
        Route::post('/tv-screens', [TvScreensController::class, 'store'])->name('tv.store');
        Route::post('/tv-screens/{screen}', [TvScreensController::class, 'update'])->name('tv.update');
        Route::post('/tv-screens/{screen}/link', [TvScreensController::class, 'regenerate'])->name('tv.regenerate');
        Route::post('/tv-screens/{screen}/delete', [TvScreensController::class, 'destroy'])->name('tv.delete');
    });

    // Delivery zones (call-center pack)
    Route::middleware(['feature:delivery_zones', 'permission:delivery.manage'])->group(function () {
        Route::get('/delivery-zones', [DeliveryZonesController::class, 'index'])->name('zones');
        Route::post('/delivery-zones', [DeliveryZonesController::class, 'store'])->name('zones.store');
        Route::post('/delivery-zones/{zone}', [DeliveryZonesController::class, 'update'])->name('zones.update');
        Route::post('/delivery-zones/{zone}/delete', [DeliveryZonesController::class, 'destroy'])->name('zones.delete');
    });

    // Call-center pack: phone orders with delivery zones
    Route::middleware(['feature:call_center', 'permission:callcenter.use'])->group(function () {
        Route::get('/call-center', [CallCenterController::class, 'screen'])->name('callcenter');
        Route::get('/call-center/customer', [CallCenterController::class, 'lookup'])->name('callcenter.lookup');
        Route::post('/call-center/orders', [CallCenterController::class, 'store'])->name('callcenter.order');
    });

    // Inventory pack: ingredients, stock per branch, recipes and food cost
    Route::middleware(['feature:inventory', 'permission:inventory.manage'])->group(function () {
        Route::get('/inventory', [InventoryController::class, 'index'])->name('inventory');
        Route::post('/inventory/ingredients', [InventoryController::class, 'store'])->name('inventory.store');
        Route::post('/inventory/ingredients/{ingredient}', [InventoryController::class, 'update'])->name('inventory.update');
        Route::post('/inventory/move', [InventoryController::class, 'move'])->name('inventory.move');
        Route::get('/inventory/movements', [InventoryController::class, 'movements'])->name('inventory.movements');
        Route::get('/inventory/recipes', [InventoryController::class, 'recipes'])->name('inventory.recipes');
        Route::get('/inventory/recipes/{item}', [InventoryController::class, 'recipe'])->name('inventory.recipe');
        Route::post('/inventory/recipes/{item}', [InventoryController::class, 'saveRecipe'])->name('inventory.recipe.save');
    });

    // Kitchen pack: kitchen display and stations
    Route::middleware(['feature:kitchen_display', 'permission:kitchen.view'])->group(function () {
        Route::get('/kitchen', [KitchenController::class, 'screen'])->name('kitchen');
        Route::get('/kitchen/feed', [KitchenController::class, 'feed'])->name('kitchen.feed');
        Route::post('/kitchen/orders/{order}', [KitchenController::class, 'bump'])->name('kitchen.bump');
    });
    Route::middleware(['feature:kitchen_stations', 'permission:menu.manage'])->group(function () {
        Route::get('/kitchen/stations', [StationsController::class, 'index'])->name('stations');
        Route::post('/kitchen/stations', [StationsController::class, 'store'])->name('stations.store');
        Route::post('/kitchen/stations/assign', [StationsController::class, 'assign'])->name('stations.assign');
        Route::post('/kitchen/stations/{station}/delete', [StationsController::class, 'destroy'])->name('stations.delete');
    });
});
