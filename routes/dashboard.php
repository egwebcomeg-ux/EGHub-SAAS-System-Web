<?php

use App\Http\Controllers\Dashboard\AuditController;
use App\Http\Controllers\Dashboard\AuthController;
use App\Http\Controllers\Dashboard\AutomationsController;
use App\Http\Controllers\Dashboard\BillingController;
use App\Http\Controllers\Dashboard\BranchesController;
use App\Http\Controllers\Dashboard\BranchMenuController;
use App\Http\Controllers\Dashboard\CallCenterController;
use App\Http\Controllers\Dashboard\CampaignsController;
use App\Http\Controllers\Dashboard\CardsController;
use App\Http\Controllers\Dashboard\CategoriesController;
use App\Http\Controllers\Dashboard\CustomersController;
use App\Http\Controllers\Dashboard\DeliveryZonesController;
use App\Http\Controllers\Dashboard\DomainController;
use App\Http\Controllers\Dashboard\FeedbackController;
use App\Http\Controllers\Dashboard\HomeController;
use App\Http\Controllers\Dashboard\InventoryController;
use App\Http\Controllers\Dashboard\ItemsController;
use App\Http\Controllers\Dashboard\KiosksController;
use App\Http\Controllers\Dashboard\KitchenController;
use App\Http\Controllers\Dashboard\LoyaltyController;
use App\Http\Controllers\Dashboard\MarketingController;
use App\Http\Controllers\Dashboard\MenuExportController;
use App\Http\Controllers\Dashboard\MenuImportController;
use App\Http\Controllers\Dashboard\MenuTranslationController;
use App\Http\Controllers\Dashboard\ModulesController;
use App\Http\Controllers\Dashboard\NotificationsController;
use App\Http\Controllers\Dashboard\OnboardingController;
use App\Http\Controllers\Dashboard\OrdersController;
use App\Http\Controllers\Dashboard\PasswordResetController;
use App\Http\Controllers\Dashboard\PhoneVerificationController;
use App\Http\Controllers\Dashboard\PosController;
use App\Http\Controllers\Dashboard\PrintMenuController;
use App\Http\Controllers\Dashboard\PwaController;
use App\Http\Controllers\Dashboard\ReportsController;
use App\Http\Controllers\Dashboard\ReservationsController;
use App\Http\Controllers\Dashboard\SecurityController;
use App\Http\Controllers\Dashboard\SettingsController;
use App\Http\Controllers\Dashboard\ShiftsController;
use App\Http\Controllers\Dashboard\StationsController;
use App\Http\Controllers\Dashboard\TablesController;
use App\Http\Controllers\Dashboard\TableSessionsController;
use App\Http\Controllers\Dashboard\TvScreensController;
use App\Http\Controllers\Dashboard\TwoFactorController;
use App\Http\Controllers\Dashboard\UsersController;
use App\Http\Controllers\Dashboard\VouchersController;
use App\Http\Controllers\Dashboard\WalletController;
use App\Models\CampaignAutomation;
use Illuminate\Support\Facades\Route;

// app.<domain> — restaurant dashboard. The tenant is the logged-in user's restaurant (InitializeTenancyFromUser).
Route::middleware('admin.locale')->group(function () {
    Route::get('/login', [AuthController::class, 'show'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::get('/welcome/{user}', [AuthController::class, 'welcome'])->whereNumber('user')->name('welcome');
    Route::get('/forgot-password', [PasswordResetController::class, 'request'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetController::class, 'send'])->middleware('throttle:5,1')->name('password.email');
    Route::get('/reset-password/{token}', [PasswordResetController::class, 'edit'])->name('password.reset');
    Route::post('/reset-password', [PasswordResetController::class, 'update'])->middleware('throttle:10,1')->name('password.update');
});

Route::middleware(['auth:web', 'tenant.user'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::post('/locale', [AuthController::class, 'locale'])->name('locale');

    Route::get('/', [HomeController::class, 'index'])->name('home');

    // Confirm the account's mobile on WhatsApp (one prepared message, no codes to type). Never blocks anything.
    Route::get('/phone', [PhoneVerificationController::class, 'show'])->name('phone');
    Route::post('/phone', [PhoneVerificationController::class, 'update'])->middleware('throttle:10,1')->name('phone.update');
    Route::get('/phone/status', [PhoneVerificationController::class, 'status'])->middleware('throttle:60,1')->name('phone.status');

    Route::middleware('permission:users.manage')->group(function () {
        Route::get('/users', [UsersController::class, 'index'])->name('users');
        Route::post('/users', [UsersController::class, 'store'])->name('users.store');
        Route::post('/users/{user}', [UsersController::class, 'update'])->name('users.update');
    });

    Route::middleware('permission:billing.manage')->group(function () {
        Route::get('/billing', [BillingController::class, 'index'])->name('billing');
        Route::post('/billing/checkout', [BillingController::class, 'checkout'])->name('billing.checkout');
        Route::post('/billing/addons/{key}', [BillingController::class, 'addon'])->where('key', '[a-z0-9_]+')->name('billing.addon');
        Route::get('/billing/invoices/{number}', [BillingController::class, 'invoice'])->name('billing.invoice');
        Route::post('/billing/invoices/{number}/claim', [BillingController::class, 'claim'])->middleware('throttle:10,1')->name('billing.claim');
    });

    Route::middleware('permission:settings.manage')->group(function () {
        Route::get('/settings', [SettingsController::class, 'edit'])->name('settings');
        Route::post('/settings', [SettingsController::class, 'update'])->name('settings.update');
        Route::post('/settings/ai-test', [SettingsController::class, 'testAi'])->middleware('throttle:6,1')->name('settings.ai-test');
        // "what will you use?": first stop after signup (?welcome=1), then from Settings; focus only, never access
        Route::get('/modules', [ModulesController::class, 'edit'])->name('modules');
        Route::get('/modules/summary', [ModulesController::class, 'summary'])->middleware('throttle:60,1')->name('modules.summary');
        Route::post('/modules', [ModulesController::class, 'update'])->name('modules.update');
        Route::post('/modules/skip', [ModulesController::class, 'skip'])->name('modules.skip');
        Route::get('/domain', [DomainController::class, 'show'])->name('domain');
        Route::post('/domain/book', [DomainController::class, 'book'])->middleware('throttle:10,1')->name('domain.book');
        Route::post('/domain/connect', [DomainController::class, 'connect'])->middleware('throttle:10,1')->name('domain.connect');
        Route::post('/domain/check', [DomainController::class, 'check'])->middleware('throttle:10,1')->name('domain.check');
        Route::post('/domain/delete', [DomainController::class, 'destroy'])->name('domain.delete');
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

        // extra menu languages: fill the missing translations with the restaurant's own AI key
        Route::post('/menu/translate', [MenuTranslationController::class, 'start'])->middleware(['feature:extra_languages', 'throttle:6,1'])->name('menu.translate');
        Route::get('/menu/translate/status', [MenuTranslationController::class, 'status'])->middleware('feature:extra_languages')->name('menu.translate.status');
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
        Route::post('/tables/design', [TablesController::class, 'design'])->name('tables.design');
        Route::get('/tables/{table}/svg', [TablesController::class, 'svg'])->name('tables.svg');
        Route::post('/tables', [TablesController::class, 'store'])->name('tables.store');
        Route::post('/tables/{table}', [TablesController::class, 'update'])->name('tables.update');
        Route::post('/tables/{table}/rotate', [TablesController::class, 'rotate'])->name('tables.rotate');
        Route::post('/tables/{table}/delete', [TablesController::class, 'destroy'])->name('tables.delete');
    });

    // Live order board (polling)
    Route::middleware(['feature:order_board', 'permission:orders.view'])->group(function () {
        Route::get('/orders', [OrdersController::class, 'board'])->name('orders');
        Route::get('/orders/feed', [OrdersController::class, 'feed'])->name('orders.feed');
        Route::post('/orders/calls/{call}', [OrdersController::class, 'handleCall'])->name('orders.call');
        Route::post('/orders/{order}/status', [OrdersController::class, 'transition'])
            ->middleware('permission:orders.manage')->name('orders.status');
        Route::post('/orders/{order}/part-ready', [OrdersController::class, 'partReady'])
            ->middleware('permission:orders.manage')->name('orders.part-ready');

        // the "Tables" tab: tables free / busy, one bill per table, pay and close (taking money needs the POS right)
        Route::middleware('permission:orders.manage')->group(function () {
            Route::get('/orders/tables', [TableSessionsController::class, 'index'])->name('orders.tables');
            Route::get('/orders/tables/feed', [TableSessionsController::class, 'feed'])->name('orders.tables.feed');
            Route::get('/orders/tables/sessions/{tableSession}', [TableSessionsController::class, 'bill'])->name('orders.tables.bill');
            Route::get('/orders/tables/sessions/{tableSession}/receipt', [TableSessionsController::class, 'receipt'])->name('orders.tables.receipt');
            Route::post('/orders/tables/sessions/{tableSession}/pay', [TableSessionsController::class, 'pay'])
                ->middleware('permission:pos.use')->name('orders.tables.pay');
            Route::post('/orders/tables/sessions/{tableSession}/close', [TableSessionsController::class, 'close'])
                ->middleware('permission:pos.use')->name('orders.tables.close');
        });
    });

    // the bell in the dashboard header: new / ready orders, waiter calls, bookings, low stock (each by its right)
    Route::get('/notifications', [NotificationsController::class, 'index'])->name('notifications');
    Route::post('/notifications/seen', [NotificationsController::class, 'seen'])->name('notifications.seen');

    Route::get('/orders/{order}/receipt', [OrdersController::class, 'receipt'])
        ->middleware('permission:orders.view|pos.use')->name('orders.receipt');

    // ---- Reports (daily on Pro, advanced on Business) ----
    Route::middleware(['feature:daily_reports', 'permission:reports.view'])->group(function () {
        Route::get('/reports', [ReportsController::class, 'index'])->name('reports');
        Route::get('/reports/export', [ReportsController::class, 'export'])->name('reports.export');
        Route::get('/reports/export/{table}', [ReportsController::class, 'exportTable'])
            ->whereIn('table', ['comparison', 'items', 'heatmap', 'branches', 'sources', 'types', 'guests'])->name('reports.export.table');
    });

    Route::get('/audit', [AuditController::class, 'index'])
        ->middleware(['feature:audit_log', 'permission:audit.view'])->name('audit');

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

    // ---- Marketing: WhatsApp campaigns with coupons ----
    Route::middleware(['feature:campaigns', 'permission:marketing.manage'])->group(function () {
        Route::get('/campaigns', [CampaignsController::class, 'index'])->name('campaigns');
        Route::get('/campaigns/preview', [CampaignsController::class, 'preview'])->name('campaigns.preview');
        Route::post('/campaigns', [CampaignsController::class, 'store'])->middleware('throttle:20,1')->name('campaigns.store');
        Route::post('/campaigns/{campaign}/cancel', [CampaignsController::class, 'cancel'])->name('campaigns.cancel');
        Route::post('/campaigns/{campaign}/resume', [CampaignsController::class, 'resume'])->name('campaigns.resume');
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
        // discount vouchers of the loyalty card: single, held by a guest, printed batches
        Route::get('/loyalty/vouchers', [VouchersController::class, 'index'])->name('vouchers');
        Route::post('/loyalty/vouchers', [VouchersController::class, 'store'])->name('vouchers.store');
        Route::post('/loyalty/vouchers/batch', [VouchersController::class, 'batch'])->middleware('throttle:20,1')->name('vouchers.batch');
        Route::post('/loyalty/vouchers/batch/toggle', [VouchersController::class, 'toggleBatch'])->name('vouchers.batch.toggle');
        Route::get('/loyalty/vouchers/export', [VouchersController::class, 'export'])->name('vouchers.export');
        Route::get('/loyalty/vouchers/print', [VouchersController::class, 'print'])->name('vouchers.print');
        Route::post('/loyalty/vouchers/{voucher}/toggle', [VouchersController::class, 'toggle'])->whereNumber('voucher')->name('vouchers.toggle');
    });
    Route::post('/loyalty/customers/{customer}/vouchers', [VouchersController::class, 'give'])
        ->middleware(['feature:loyalty_stamps', 'permission:vouchers.give'])->whereNumber('customer')->name('vouchers.give');
    // The counter desk also serves the advanced cards (packages, tiers, cashback) when there is no stamp program.
    Route::middleware(['feature:loyalty_stamps|prepaid_packages|membership|cashback', 'permission:loyalty.stamp'])->group(function () {
        Route::get('/loyalty', [LoyaltyController::class, 'desk'])->name('loyalty.desk');
        Route::post('/loyalty/enroll', [LoyaltyController::class, 'enroll'])->name('loyalty.enroll');
    });
    Route::middleware(['feature:loyalty_stamps', 'permission:loyalty.stamp'])->group(function () {
        Route::post('/loyalty/cards/{card}/stamp', [LoyaltyController::class, 'stamp'])->name('loyalty.stamp');
        Route::post('/loyalty/cards/{card}/claim', [LoyaltyController::class, 'claim'])->name('loyalty.claim');
        Route::post('/loyalty/rewards/{reward}/redeem', [LoyaltyController::class, 'redeem'])->name('loyalty.redeem');
    });
    // Advanced cards (cards pack / Business): cashback share, membership tiers, prepaid packages on sale
    Route::middleware(['feature:cashback|prepaid_packages|membership', 'permission:loyalty.manage'])->group(function () {
        Route::get('/loyalty/cards', [CardsController::class, 'index'])->name('cards');
        Route::post('/loyalty/cards/cashback', [CardsController::class, 'cashback'])->name('cards.cashback');
        Route::post('/loyalty/cards/tiers', [CardsController::class, 'tiers'])->name('cards.tiers');
        Route::post('/loyalty/cards/packages', [CardsController::class, 'storePackage'])->name('cards.packages.store');
        Route::post('/loyalty/cards/packages/{package}', [CardsController::class, 'updatePackage'])->whereNumber('package')->name('cards.packages.update');
    });
    Route::middleware(['feature:prepaid_packages', 'permission:loyalty.stamp'])->group(function () {
        Route::post('/loyalty/customers/{customer}/packages', [CardsController::class, 'sellPackage'])->whereNumber('customer')->name('cards.packages.sell');
        Route::post('/loyalty/packages/{copy}/use', [CardsController::class, 'usePackage'])->whereNumber('copy')->name('cards.packages.use');
        Route::post('/loyalty/packages/{copy}/void', [CardsController::class, 'voidPackage'])->whereNumber('copy')->name('cards.packages.void');
    });
    Route::get('/feedback', [FeedbackController::class, 'index'])
        ->middleware(['feature:feedback', 'permission:reports.view|customers.view'])->name('feedback');

    // ---- Operations add-ons (sold separately from the plans) ----

    // POS pack: cashier screen, shifts & drawer, customer display
    Route::middleware(['feature:pos', 'permission:pos.use'])->group(function () {
        Route::get('/pos', [PosController::class, 'screen'])->name('pos');
        Route::post('/pos/orders', [PosController::class, 'store'])->name('pos.order');
        Route::post('/pos/coupon', [PosController::class, 'coupon'])->middleware(['feature:loyalty_stamps|campaigns', 'throttle:60,1'])->name('pos.coupon');
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
    Route::middleware(['feature:delivery_zones|online_ordering', 'permission:delivery.manage'])->group(function () {
        Route::get('/delivery-zones', [DeliveryZonesController::class, 'index'])->name('zones');
        Route::post('/delivery-zones', [DeliveryZonesController::class, 'store'])->name('zones.store');
        Route::post('/delivery-zones/mode', [DeliveryZonesController::class, 'mode'])->name('zones.mode');
        Route::post('/delivery-zones/{zone}', [DeliveryZonesController::class, 'update'])->name('zones.update');
        Route::post('/delivery-zones/{zone}/area', [DeliveryZonesController::class, 'polygon'])->name('zones.polygon');
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

    // Prepaid wallet & gift cards: the page, and the POS actions (top-up, gift card sale) through the cashier's shift
    Route::middleware('feature:wallet')->group(function () {
        Route::get('/wallet/customer', [WalletController::class, 'lookup'])->middleware('permission:wallet.manage|pos.use')->name('wallet.lookup');
        Route::middleware('permission:wallet.manage')->group(function () {
            Route::get('/wallet', [WalletController::class, 'index'])->name('wallet');
            Route::post('/wallet/topup', [WalletController::class, 'topUp'])->name('wallet.topup');
            Route::get('/wallet/topups/{tx}', [WalletController::class, 'receipt'])->whereNumber('tx')->name('wallet.receipt');
            Route::post('/wallet/gift-cards', [WalletController::class, 'sellGift'])->name('wallet.gift.sell');
            Route::get('/wallet/gift-cards/{card}/print', [WalletController::class, 'printGift'])->whereNumber('card')->name('wallet.gift.print');
            Route::post('/wallet/gift-cards/{card}/void', [WalletController::class, 'voidGift'])->whereNumber('card')->name('wallet.gift.void');
            Route::post('/wallet/customers/{customer}/adjust', [WalletController::class, 'adjust'])->whereNumber('customer')->name('wallet.adjust');
        });
    });

    // Kiosk pack: self-order tablets at the counter
    Route::middleware(['feature:kiosk', 'permission:kiosk.manage'])->group(function () {
        Route::get('/kiosks', [KiosksController::class, 'index'])->name('kiosks');
        Route::post('/kiosks', [KiosksController::class, 'store'])->name('kiosks.store');
        Route::post('/kiosks/{kiosk}', [KiosksController::class, 'update'])->name('kiosks.update');
        Route::post('/kiosks/{kiosk}/link', [KiosksController::class, 'regenerate'])->name('kiosks.regenerate');
        Route::post('/kiosks/{kiosk}/delete', [KiosksController::class, 'destroy'])->name('kiosks.delete');
    });

    // Table reservations (plan feature / reservations pack)
    Route::middleware(['feature:reservations', 'permission:reservations.manage'])->group(function () {
        Route::get('/reservations', [ReservationsController::class, 'index'])->name('reservations');
        Route::post('/reservations', [ReservationsController::class, 'store'])->name('reservations.store');
        Route::post('/reservations/settings', [ReservationsController::class, 'settings'])->name('reservations.settings');
        Route::post('/reservations/{reservation}/status', [ReservationsController::class, 'transition'])->name('reservations.status');
    });

    // ---- Order & menu operations ----

    // the whole menu as a sheet in the import layout (Excel or CSV), so it can be edited and imported back
    Route::get('/menu/export.{format}', [MenuExportController::class, 'download'])->whereIn('format', ['xlsx', 'csv'])
        ->middleware('permission:menu.manage')->name('menu.export');

    // busy mode from the orders board: pauses online / takeaway / delivery ordering until a time
    Route::post('/orders/busy', [OrdersController::class, 'busy'])
        ->middleware(['feature:order_board', 'permission:orders.manage'])->name('orders.busy');

    // menu banners (picture strip under the menu header), managed from the offers page
    Route::middleware(['feature:offers', 'permission:marketing.manage'])->group(function () {
        Route::post('/marketing/banners', [MarketingController::class, 'saveBanner'])->name('banners.store');
        Route::post('/marketing/banners/{banner}/toggle', [MarketingController::class, 'toggleBanner'])->name('banners.toggle');
        Route::post('/marketing/banners/{banner}/delete', [MarketingController::class, 'deleteBanner'])->name('banners.delete');
        // cart goal ("add X and get Y free") and seasonal looks of the menu (Ramadan, Eid…)
        Route::post('/marketing/cart-goal', [MarketingController::class, 'saveCartGoal'])->name('cart-goal.update');
        Route::post('/marketing/seasons', [MarketingController::class, 'saveSeason'])->name('seasons.store');
        Route::post('/marketing/seasons/{season}/toggle', [MarketingController::class, 'toggleSeason'])->name('seasons.toggle');
        Route::post('/marketing/seasons/{season}/delete', [MarketingController::class, 'deleteSeason'])->name('seasons.delete');
    });
});

// ---- Marketing: automations (the "Automations" tab of the campaigns page) ----
Route::middleware(['auth:web', 'tenant.user', 'feature:campaigns', 'permission:marketing.manage'])->group(function () {
    Route::post('/campaigns/automations/{kind}', [AutomationsController::class, 'update'])
        ->whereIn('kind', array_keys(CampaignAutomation::KINDS))->name('automations.update');
    Route::post('/campaigns/automations/{kind}/toggle', [AutomationsController::class, 'toggle'])
        ->whereIn('kind', array_keys(CampaignAutomation::KINDS))->name('automations.toggle');
});

// ---- Two-factor authentication: second login step (guest, session-scoped) and the account security page ----
Route::middleware('admin.locale')->group(function () {
    Route::get('/two-factor', [TwoFactorController::class, 'show'])->name('two-factor');
    Route::post('/two-factor', [TwoFactorController::class, 'verify'])->middleware('throttle:5,1')->name('two-factor.verify');
});
Route::middleware(['auth:web', 'tenant.user'])->group(function () {
    Route::get('/security', [SecurityController::class, 'show'])->name('security');
    Route::post('/security/two-factor', [SecurityController::class, 'enable'])->name('security.enable');
    Route::post('/security/two-factor/confirm', [SecurityController::class, 'confirm'])->middleware('throttle:10,1')->name('security.confirm');
    Route::post('/security/two-factor/disable', [SecurityController::class, 'disable'])->middleware('throttle:10,1')->name('security.disable');
});

// ---- Onboarding checklist on the home page (App\Services\Onboarding) and the illustrated tutorial ----
Route::middleware(['auth:web', 'tenant.user'])->group(function () {
    Route::post('/onboarding/skip', [OnboardingController::class, 'skip'])->name('onboarding.skip');
    Route::post('/onboarding/dismiss', [OnboardingController::class, 'dismiss'])->name('onboarding.dismiss');
    Route::get('/help', [OnboardingController::class, 'help'])->name('help');
});

// ---- The dashboard as an installable app (App\Services\Pwa): manifest and a static-files-only service worker ----
Route::get('/manifest.webmanifest', [PwaController::class, 'manifest'])->name('manifest');
Route::get('/sw.js', [PwaController::class, 'serviceWorker'])->name('sw');
