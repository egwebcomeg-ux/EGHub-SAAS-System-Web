<?php

use App\Http\Controllers\Dashboard\AuthController;
use App\Http\Controllers\Dashboard\BillingController;
use App\Http\Controllers\Dashboard\BranchesController;
use App\Http\Controllers\Dashboard\CategoriesController;
use App\Http\Controllers\Dashboard\CustomersController;
use App\Http\Controllers\Dashboard\FeedbackController;
use App\Http\Controllers\Dashboard\HomeController;
use App\Http\Controllers\Dashboard\ItemsController;
use App\Http\Controllers\Dashboard\KitchenController;
use App\Http\Controllers\Dashboard\LoyaltyController;
use App\Http\Controllers\Dashboard\OrdersController;
use App\Http\Controllers\Dashboard\PosController;
use App\Http\Controllers\Dashboard\SettingsController;
use App\Http\Controllers\Dashboard\ShiftsController;
use App\Http\Controllers\Dashboard\StationsController;
use App\Http\Controllers\Dashboard\TablesController;
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
    });
    Route::post('/menu/items/{item}/toggle', [ItemsController::class, 'toggle'])
        ->middleware('permission:menu.manage|menu.availability')->name('items.toggle');

    Route::middleware('permission:branches.manage')->group(function () {
        Route::get('/branches', [BranchesController::class, 'index'])->name('branches');
        Route::post('/branches', [BranchesController::class, 'store'])->name('branches.store');
        Route::post('/branches/{branch}', [BranchesController::class, 'update'])->name('branches.update');
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
