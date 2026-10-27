<?php

use App\Http\Controllers\Dashboard\AuditController;
use App\Http\Controllers\Dashboard\AuthController;
use App\Http\Controllers\Dashboard\AutomationsController;
use App\Http\Controllers\Dashboard\BackupsController;
use App\Http\Controllers\Dashboard\BillingController;
use App\Http\Controllers\Dashboard\BranchesController;
use App\Http\Controllers\Dashboard\BranchMenuController;
use App\Http\Controllers\Dashboard\CallCenterController;
use App\Http\Controllers\Dashboard\CampaignsController;
use App\Http\Controllers\Dashboard\CardsController;
use App\Http\Controllers\Dashboard\CategoriesController;
use App\Http\Controllers\Dashboard\CorporateContractsController;
use App\Http\Controllers\Dashboard\CorporateRedeemController;
use App\Http\Controllers\Dashboard\CorporateReportController;
use App\Http\Controllers\Dashboard\CouponsController;
use App\Http\Controllers\Dashboard\CustomerImportController;
use App\Http\Controllers\Dashboard\CustomersController;
use App\Http\Controllers\Dashboard\DeliveryZonesController;
use App\Http\Controllers\Dashboard\DemoContentController;
use App\Http\Controllers\Dashboard\DevicesController;
use App\Http\Controllers\Dashboard\DomainController;
use App\Http\Controllers\Dashboard\DriverController;
use App\Http\Controllers\Dashboard\DriversController;
use App\Http\Controllers\Dashboard\FeedbackController;
use App\Http\Controllers\Dashboard\HoldsController;
use App\Http\Controllers\Dashboard\HomeController;
use App\Http\Controllers\Dashboard\InventoryController;
use App\Http\Controllers\Dashboard\ItemsController;
use App\Http\Controllers\Dashboard\KiosksController;
use App\Http\Controllers\Dashboard\KitchenController;
use App\Http\Controllers\Dashboard\KnowledgeBaseController;
use App\Http\Controllers\Dashboard\LoyaltyController;
use App\Http\Controllers\Dashboard\MarketingController;
use App\Http\Controllers\Dashboard\MenuExportController;
use App\Http\Controllers\Dashboard\MenuImportController;
use App\Http\Controllers\Dashboard\MenuTranslationController;
use App\Http\Controllers\Dashboard\ModulesController;
use App\Http\Controllers\Dashboard\NativeTrackingController;
use App\Http\Controllers\Dashboard\NotificationsController;
use App\Http\Controllers\Dashboard\OnboardingController;
use App\Http\Controllers\Dashboard\OrdersController;
use App\Http\Controllers\Dashboard\OutreachController;
use App\Http\Controllers\Dashboard\PagerController;
use App\Http\Controllers\Dashboard\PasswordResetController;
use App\Http\Controllers\Dashboard\PhoneVerificationController;
use App\Http\Controllers\Dashboard\PosController;
use App\Http\Controllers\Dashboard\PrintController;
use App\Http\Controllers\Dashboard\PrintMenuController;
use App\Http\Controllers\Dashboard\PushController;
use App\Http\Controllers\Dashboard\PwaController;
use App\Http\Controllers\Dashboard\RefundsController;
use App\Http\Controllers\Dashboard\ReportsController;
use App\Http\Controllers\Dashboard\ReservationsController;
use App\Http\Controllers\Dashboard\SecurityController;
use App\Http\Controllers\Dashboard\SettingsController;
use App\Http\Controllers\Dashboard\ShiftsController;
use App\Http\Controllers\Dashboard\SocialLoginController;
use App\Http\Controllers\Dashboard\StationsController;
use App\Http\Controllers\Dashboard\SupportAccessController;
use App\Http\Controllers\Dashboard\TablesController;
use App\Http\Controllers\Dashboard\TableSessionsController;
use App\Http\Controllers\Dashboard\TerminalsController;
use App\Http\Controllers\Dashboard\TvAlertsController;
use App\Http\Controllers\Dashboard\TvGroupsController;
use App\Http\Controllers\Dashboard\TvPairingController;
use App\Http\Controllers\Dashboard\TvPlaylistController;
use App\Http\Controllers\Dashboard\TvScreenDeviceController;
use App\Http\Controllers\Dashboard\TvScreensController;
use App\Http\Controllers\Dashboard\TwoFactorController;
use App\Http\Controllers\Dashboard\UsersController;
use App\Http\Controllers\Dashboard\VoucherRedeemController;
use App\Http\Controllers\Dashboard\VouchersController;
use App\Http\Controllers\Dashboard\WalletController;
use App\Http\Controllers\Dashboard\WelcomeKitController;
use App\Http\Controllers\Dashboard\WhatsAppNumberController;
use App\Http\Controllers\Dashboard\ZReportController;
use App\Http\Controllers\Platform\BillingWebhookController;
use App\Models\CampaignAutomation;
use App\Models\Payment;
use App\Services\Auth\SocialProviders;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

// app.<domain> — restaurant dashboard. The tenant is the logged-in user's restaurant (InitializeTenancyFromUser).
Route::middleware('admin.locale')->group(function () {
    Route::get('/login', [AuthController::class, 'show'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::get('/welcome/{user}', [AuthController::class, 'welcome'])->whereNumber('user')->name('welcome');
    Route::get('/forgot-password', [PasswordResetController::class, 'request'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetController::class, 'send'])->middleware('throttle:5,1')->name('password.email');
    Route::get('/reset-password/{token}', [PasswordResetController::class, 'edit'])->name('password.reset');
    Route::post('/reset-password', [PasswordResetController::class, 'update'])->middleware('throttle:10,1')->name('password.update');
    // "Continue with Google / Facebook" (shown only when the provider's keys are set); also started from eghub.app/register
    Route::get('/auth/{provider}/redirect', [SocialLoginController::class, 'redirect'])
        ->whereIn('provider', array_keys(SocialProviders::PROVIDERS))->middleware('throttle:20,1')->name('social.redirect');
    Route::get('/auth/{provider}/callback', [SocialLoginController::class, 'callback'])
        ->whereIn('provider', array_keys(SocialProviders::PROVIDERS))->middleware('throttle:20,1')->name('social.callback');
});

// The welcome page of a restaurant EgWeb made for a prospect (App\Services\WelcomeKit): no sign-in, the signature
// (14 days) and the nonce of the current kit are the keys; a new kit makes older links fail.
Route::get('/welcome-kit/{place}/{nonce}', [WelcomeKitController::class, 'show'])
    ->where(['place' => '[a-z0-9-]{3,30}', 'nonce' => '[A-Za-z0-9]{40}'])->middleware(['signed', 'throttle:30,1'])->name('welcome-kit');

// EgWeb support signing in from the admin (App\Services\SupportAccess): a one-time ticket made on admin.<domain>
// a moment ago (60 seconds, used once, bound to the admin, the restaurant and the account) opens this dashboard.
Route::get('/support-access/{ticket}', [SupportAccessController::class, 'enter'])
    ->where('ticket', '[a-f0-9]{48}')->middleware(['admin.locale', 'throttle:20,1'])->name('support-access');

// The online payment gateway's server-to-server webhook (Kashier: https://app.<domain>/billing/kashier/webhook).
// No login and no CSRF (bootstrap/app.php): the gateway's signature is the only proof, checked in the controller.
Route::post('/billing/{gateway}/webhook', [BillingWebhookController::class, 'receive'])
    ->whereIn('gateway', Payment::ONLINE_GATEWAYS)->middleware('throttle:120,1')->name('billing.webhook');

// The EgHub driver app's background location (App\Services\Orders\NativeTracking): the signed URL and the bearer token
// it was given are the only keys, no session and no CSRF.
Route::post('/native/track/{order}', [NativeTrackingController::class, 'store'])->whereNumber('order')
    ->middleware('throttle:native-track')->name('native.track')
    ->withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, PreventRequestForgery::class]);

Route::middleware(['auth:web', 'tenant.user'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    // EgHub apps: this phone's push token (registered by the app's page on every load) and the user's own choice of
    // notifications on the phone
    Route::post('/devices/push-token', [PushController::class, 'register'])->middleware('throttle:30,1')->name('push.register');
    Route::post('/devices/push-token/delete', [PushController::class, 'unregister'])->middleware('throttle:30,1')->name('push.unregister');
    Route::get('/notifications/push', [PushController::class, 'settings'])->name('push');
    Route::post('/notifications/push', [PushController::class, 'update'])->name('push.update');
    Route::post('/notifications/push/devices/{device}/delete', [PushController::class, 'removeDevice'])->whereNumber('device')->name('push.devices.delete');
    Route::post('/locale', [AuthController::class, 'locale'])->name('locale');
    Route::post('/support-access/exit', [SupportAccessController::class, 'exit'])->name('support-access.exit');

    Route::get('/', [HomeController::class, 'index'])->name('home');

    // Confirm the account's mobile on WhatsApp (one prepared message, no codes to type). Never blocks anything.
    Route::get('/phone', [PhoneVerificationController::class, 'show'])->name('phone');
    Route::post('/phone', [PhoneVerificationController::class, 'update'])->middleware(['support.blocked', 'throttle:10,1'])->name('phone.update');
    Route::get('/phone/status', [PhoneVerificationController::class, 'status'])->middleware('throttle:60,1')->name('phone.status');

    Route::middleware('permission:users.manage')->group(function () {
        Route::get('/users', [UsersController::class, 'index'])->name('users');
        // staff accounts (a password EgWeb support picked would outlive the support session: support.blocked)
        Route::post('/users', [UsersController::class, 'store'])->middleware('support.blocked')->name('users.store');
        Route::post('/users/{user}', [UsersController::class, 'update'])->middleware('support.blocked')->name('users.update');
    });

    // paying, subscribing and claiming a transfer are not for EgWeb support signed in from the admin (support.blocked)
    Route::middleware('permission:billing.manage')->group(function () {
        Route::get('/billing', [BillingController::class, 'index'])->name('billing');
        Route::post('/billing/checkout', [BillingController::class, 'checkout'])->middleware('support.blocked')->name('billing.checkout');
        Route::post('/billing/addons/{key}', [BillingController::class, 'addon'])->where('key', '[a-z0-9_]+')->middleware('support.blocked')->name('billing.addon');
        Route::get('/billing/invoices/{number}', [BillingController::class, 'invoice'])->name('billing.invoice');
        Route::post('/billing/invoices/{number}/claim', [BillingController::class, 'claim'])->middleware(['support.blocked', 'throttle:10,1'])->name('billing.claim');
        Route::post('/billing/invoices/{number}/pay', [BillingController::class, 'pay'])->middleware(['support.blocked', 'throttle:10,1'])->name('billing.pay');
        // the gateway sends the restaurant back here (checks the database, never the query string)
        Route::get('/billing/invoices/{number}/return', [BillingController::class, 'returned'])->middleware('throttle:30,1')->name('billing.return');
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
        Route::post('/domain/book', [DomainController::class, 'book'])->middleware(['support.blocked', 'throttle:10,1'])->name('domain.book');
        Route::post('/domain/connect', [DomainController::class, 'connect'])->middleware('throttle:10,1')->name('domain.connect');
        Route::post('/domain/check', [DomainController::class, 'check'])->middleware('throttle:10,1')->name('domain.check');
        Route::post('/domain/delete', [DomainController::class, 'destroy'])->middleware('support.blocked')->name('domain.delete');

        // the restaurant's own WhatsApp Business number (App\Services\WhatsApp\OwnNumber): the page explains on a plan
        // without WhatsApp messages; the token is the owner's, so EgWeb support can only test it (support.blocked)
        Route::get('/settings/whatsapp-number', [WhatsAppNumberController::class, 'show'])->name('whatsapp-number');
        // deleting the stored token never depends on the plan: a restaurant that lost WhatsApp messages can still revoke it
        Route::post('/settings/whatsapp-number/disconnect', [WhatsAppNumberController::class, 'disconnect'])->middleware('support.blocked')->name('whatsapp-number.disconnect');
        Route::middleware('feature:whatsapp_notifications')->group(function () {
            Route::post('/settings/whatsapp-number', [WhatsAppNumberController::class, 'save'])->middleware(['support.blocked', 'throttle:10,1'])->name('whatsapp-number.save');
            Route::post('/settings/whatsapp-number/test', [WhatsAppNumberController::class, 'test'])->middleware('throttle:10,1')->name('whatsapp-number.test');
            Route::post('/settings/whatsapp-number/templates', [WhatsAppNumberController::class, 'templates'])->middleware(['support.blocked', 'throttle:6,1'])->name('whatsapp-number.templates');
        });

        // backups of the whole restaurant: owner only (backups.manage is never granted to staff); the page explains
        // on a plan without the feature, creating / downloading / deleting need it
        Route::middleware('permission:backups.manage')->group(function () {
            Route::get('/backups', [BackupsController::class, 'index'])->name('backups');
            Route::middleware('feature:backups')->group(function () {
                // the restaurant's data leaves only with its owner, never with EgWeb support (support.blocked)
                Route::post('/backups', [BackupsController::class, 'store'])->middleware(['support.blocked', 'throttle:10,1'])->name('backups.store');
                Route::get('/backups/{backup}/download', [BackupsController::class, 'download'])->whereNumber('backup')->middleware(['signed', 'support.blocked'])->name('backups.download');
                Route::post('/backups/{backup}/delete', [BackupsController::class, 'destroy'])->whereNumber('backup')->name('backups.delete');
            });
        });
    });

    // Menu editor
    Route::middleware('permission:menu.manage')->group(function () {
        Route::get('/menu', [ItemsController::class, 'index'])->name('menu');
        Route::get('/menu/items/create', [ItemsController::class, 'create'])->name('items.create');
        Route::post('/menu/items', [ItemsController::class, 'store'])->name('items.store');
        Route::get('/menu/items/{item}', [ItemsController::class, 'edit'])->name('items.edit');
        Route::post('/menu/items/{item}', [ItemsController::class, 'update'])->name('items.update');
        Route::post('/menu/items/{item}/delete', [ItemsController::class, 'destroy'])->name('items.delete');
        Route::post('/menu/items/{item}/price', [ItemsController::class, 'price'])->name('items.price');
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
        // «اتأكد بالتليفون»: staff called the guest of a cash-on-delivery order and it is fine
        Route::post('/orders/{order}/confirm-phone', [OrdersController::class, 'confirmByPhone'])->whereNumber('order')
            ->middleware(['permission:orders.manage', 'throttle:30,1'])->name('orders.confirm-phone');

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

    // The guest pager (plan feature pager): the station for places on another POS, and "Call" on an order's card
    Route::middleware(['feature:pager', 'permission:pager.use'])->prefix('/pager')->name('pager')->group(function () {
        Route::get('/', [PagerController::class, 'station']);
        Route::get('/feed', [PagerController::class, 'feed'])->name('.feed');
        Route::post('/tickets', [PagerController::class, 'store'])->middleware('throttle:60,1')->name('.tickets.store');
        Route::post('/tickets/{ticket}/call', [PagerController::class, 'callTicket'])->whereNumber('ticket')->middleware('throttle:60,1')->name('.tickets.call');
        Route::post('/tickets/{ticket}/picked-up', [PagerController::class, 'pickedUp'])->whereNumber('ticket')->name('.tickets.picked');
        Route::get('/tickets/{ticket}/print', [PagerController::class, 'print'])->whereNumber('ticket')->name('.tickets.print');
        Route::get('/tickets/{ticket}/qr', [PagerController::class, 'showQr'])->whereNumber('ticket')->name('.tickets.qr');
        Route::post('/settings', [PagerController::class, 'settings'])->name('.settings');
        Route::post('/orders/{order}/call', [PagerController::class, 'callOrder'])->whereNumber('order')->middleware('throttle:60,1')->name('.orders.call');
        Route::post('/orders/{order}/whatsapp', [PagerController::class, 'whatsappOrder'])->whereNumber('order')->middleware('throttle:10,1')->name('.orders.whatsapp');
    });

    // the bell in the dashboard header: new / ready orders, waiter calls, bookings, low stock (each by its right)
    Route::get('/notifications', [NotificationsController::class, 'index'])->name('notifications');
    Route::post('/notifications/seen', [NotificationsController::class, 'seen'])->name('notifications.seen');

    Route::get('/orders/{order}/receipt', [OrdersController::class, 'receipt'])
        ->middleware('permission:orders.view|pos.use|pager.use')->name('orders.receipt');

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
        // the old popup editor: popups are now banners shown as soon as the menu opens (Banners & announcements)
        Route::match(['get', 'post'], '/marketing/popups/{any?}', [MarketingController::class, 'popupsMoved'])
            ->where('any', '.*')->name('popups.moved');
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
        Route::get('/customers/export', [CustomersController::class, 'export'])->middleware('support.blocked')->name('customers.export');
        // import a guest list (CSV / Excel): upload, review, save (owner and manager only: customers.import)
        Route::middleware('permission:customers.import')->group(function () {
            Route::get('/customers/import', [CustomerImportController::class, 'create'])->name('customers.import');
            Route::get('/customers/import/template', [CustomerImportController::class, 'template'])->name('customers.import.template');
            Route::post('/customers/import', [CustomerImportController::class, 'store'])->middleware('throttle:20,1')->name('customers.import.store');
            Route::post('/customers/import/cancel', [CustomerImportController::class, 'cancel'])->name('customers.import.cancel');
            Route::get('/customers/import/{token}', [CustomerImportController::class, 'review'])->where('token', '[A-Za-z0-9]+')->name('customers.import.review');
            Route::post('/customers/import/{token}', [CustomerImportController::class, 'apply'])->where('token', '[A-Za-z0-9]+')->name('customers.import.apply');
        });
        // a group of guests for Meta Ads (customer list format), owner and manager only, in the audit log
        Route::get('/customers/audience-export', [CustomersController::class, 'audienceExport'])
            ->middleware(['permission:customers.import', 'support.blocked', 'throttle:10,1'])->name('customers.audience-export');
        // «حملة واتساب مجانية»: the manual campaign sent row by row from the staff's own WhatsApp (no Meta, no credits)
        Route::middleware('permission:marketing.manage')->prefix('/customers/outreach')->name('outreach')->group(function () {
            Route::get('/', [OutreachController::class, 'index'])->name('');
            Route::post('/', [OutreachController::class, 'store'])->middleware('throttle:10,1')->name('.store');
            Route::get('/{campaign}', [OutreachController::class, 'show'])->whereNumber('campaign')->name('.show');
            Route::post('/{campaign}/rows/{recipient}/sent', [OutreachController::class, 'sent'])->whereNumber(['campaign', 'recipient'])
                ->middleware('throttle:120,1')->name('.sent');
            Route::post('/{campaign}/rows/{recipient}/opt-out', [OutreachController::class, 'optOut'])->whereNumber(['campaign', 'recipient'])->name('.opt-out');
            Route::get('/{campaign}/links.csv', [OutreachController::class, 'export'])->whereNumber('campaign')
                ->middleware(['permission:customers.import', 'support.blocked'])->name('.export');
            Route::post('/{campaign}/sms', [OutreachController::class, 'sms'])->whereNumber('campaign')->middleware('throttle:6,1')->name('.sms');
        });
        Route::get('/customers/{customer}', [CustomersController::class, 'show'])->name('customers.show');
        Route::post('/customers/{customer}', [CustomersController::class, 'update'])->name('customers.update');
        // "رابط جديد" of the guest's account (and card) link: every old link and remembered phone stops working
        Route::post('/customers/{customer}/account-link', [CustomersController::class, 'rotateAccountLink'])
            ->middleware('throttle:10,1')->name('customers.account-link');
    });
    Route::middleware(['feature:loyalty_stamps', 'permission:loyalty.manage'])->group(function () {
        Route::get('/loyalty/setup', [LoyaltyController::class, 'edit'])->name('loyalty.setup');
        Route::post('/loyalty/setup', [LoyaltyController::class, 'update'])->name('loyalty.update');
        Route::post('/loyalty/restart', [LoyaltyController::class, 'restart'])->name('loyalty.restart');
        // «سجّل وخد …»: the gift on a guest's first online order (App\Services\Marketing\WelcomeReward)
        Route::post('/loyalty/welcome', [LoyaltyController::class, 'welcome'])->name('loyalty.welcome');
        // discount vouchers of the loyalty card: single, held by a guest, printed batches
        Route::get('/loyalty/vouchers', [VouchersController::class, 'index'])->name('vouchers');
        Route::post('/loyalty/vouchers', [VouchersController::class, 'store'])->name('vouchers.store');
        Route::post('/loyalty/vouchers/batch', [VouchersController::class, 'batch'])->middleware('throttle:20,1')->name('vouchers.batch');
        Route::post('/loyalty/vouchers/batch/toggle', [VouchersController::class, 'toggleBatch'])->name('vouchers.batch.toggle');
        Route::get('/loyalty/vouchers/export', [VouchersController::class, 'export'])->name('vouchers.export');
        Route::get('/loyalty/vouchers/print', [VouchersController::class, 'print'])->name('vouchers.print');
        Route::post('/loyalty/vouchers/{voucher}/toggle', [VouchersController::class, 'toggle'])->whereNumber('voucher')->name('vouchers.toggle');
    });
    // one page for every discount code: campaign coupons and loyalty vouchers (each side for whoever runs it today)
    Route::middleware(['feature:loyalty_stamps|campaigns', 'permission:loyalty.manage|marketing.manage'])->group(function () {
        Route::get('/coupons', [CouponsController::class, 'index'])->name('coupons');
        Route::post('/coupons/{coupon}/toggle', [CouponsController::class, 'toggle'])->whereNumber('coupon')->name('coupons.toggle');
    });
    Route::post('/coupons/give', [CouponsController::class, 'give'])
        ->middleware(['feature:loyalty_stamps', 'permission:vouchers.give', 'throttle:30,1'])->name('coupons.give');
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
        // offline mode: the catalog the device keeps, an "online?" check, and the sales made offline, one by one
        Route::get('/pos/catalog', [PosController::class, 'catalog'])->name('pos.catalog');
        Route::get('/pos/ping', [PosController::class, 'ping'])->name('pos.ping');
        Route::post('/pos/sync', [PosController::class, 'sync'])->middleware('throttle:240,1')->name('pos.sync');
        Route::post('/pos/terminals/{terminal}/claim', [PosController::class, 'claim'])->whereNumber('terminal')->middleware('throttle:20,1')->name('pos.claim');
        // held tickets ("تعليق"): park, list, resume on any terminal of the branch, discard (pos.hold.discard)
        Route::get('/pos/holds', [HoldsController::class, 'index'])->name('pos.holds');
        Route::post('/pos/holds', [HoldsController::class, 'store'])->middleware('throttle:60,1')->name('pos.holds.store');
        Route::post('/pos/holds/{held}/resume', [HoldsController::class, 'resume'])->whereNumber('held')->name('pos.holds.resume');
        Route::post('/pos/holds/{held}/discard', [HoldsController::class, 'discard'])->whereNumber('held')->name('pos.holds.discard');
        // the recent paid orders (search by number or OFF-K1-3), refunds with a manager's approval and the refund slip
        Route::get('/pos/orders/recent', [RefundsController::class, 'recent'])->name('pos.orders.recent');
        Route::post('/pos/orders/{order}/refund', [RefundsController::class, 'store'])->whereNumber('order')->middleware('throttle:30,1')->name('pos.refund');
        Route::get('/pos/refunds/{refund}/receipt', [RefundsController::class, 'receipt'])->whereNumber('refund')->name('pos.refunds.receipt');
        // printing from the EgHub cashier app: an order's jobs (each copy claimed once), a failed copy released, and
        // the orders still waiting for their kitchen tickets
        Route::post('/pos/orders/{order}/print-jobs', [PrintController::class, 'jobs'])->whereNumber('order')->middleware('throttle:120,1')->name('pos.print.jobs');
        Route::post('/pos/orders/{order}/print-jobs/release', [PrintController::class, 'release'])->whereNumber('order')->middleware('throttle:60,1')->name('pos.print.release');
        Route::get('/pos/print-queue', [PrintController::class, 'queue'])->middleware('throttle:30,1')->name('pos.print.queue');
    });
    // end of day (Z) report: per branch / terminal / day, on screen, printed (80mm / A4) or as CSV / Excel
    Route::get('/pos/z-report', [ZReportController::class, 'show'])->middleware(['feature:pos', 'permission:reports.view|shifts.manage'])->name('zreport');
    // POS terminals of each branch (owner / manager) and how long a device may sell offline
    Route::middleware(['feature:pos', 'permission:branches.manage'])->group(function () {
        Route::get('/pos/terminals', [TerminalsController::class, 'index'])->name('terminals');
        Route::post('/pos/terminals', [TerminalsController::class, 'store'])->name('terminals.store');
        Route::post('/pos/terminals/settings', [TerminalsController::class, 'settings'])->name('terminals.settings');
        Route::post('/pos/printing', [PrintController::class, 'settings'])->name('pos.printing');
        Route::post('/pos/terminals/{terminal}', [TerminalsController::class, 'update'])->whereNumber('terminal')->name('terminals.update');
        Route::post('/pos/terminals/{terminal}/release', [TerminalsController::class, 'release'])->whereNumber('terminal')->name('terminals.release');
    });
    Route::middleware(['feature:shifts', 'permission:shifts.manage'])->group(function () {
        Route::get('/shifts', [ShiftsController::class, 'index'])->name('shifts');
        Route::post('/shifts/open', [ShiftsController::class, 'open'])->name('shifts.open');
        Route::post('/shifts/move', [ShiftsController::class, 'move'])->name('shifts.move');
        Route::post('/shifts/close', [ShiftsController::class, 'close'])->name('shifts.close');
    });

    // Screens & devices: TV boards, kiosks, customer display / order board, kitchen display links (each type by its right)
    Route::get('/devices', [DevicesController::class, 'index'])->name('devices');

    // EgHub screens (TV screens): playlists, pairing a TV with the code it shows on eghub.app/screen, screen media
    Route::middleware(['feature:tv_screens', 'permission:menu.manage'])->group(function () {
        Route::get('/tv-screens', [TvScreensController::class, 'index'])->name('tv');
        Route::post('/tv-screens', [TvScreensController::class, 'store'])->name('tv.store');
        // a device stays attached after a support session: not for EgWeb support signed in from the admin
        Route::get('/tv-screens/pair', [TvPairingController::class, 'show'])->name('tv.pair');
        Route::post('/tv-screens/pair', [TvPairingController::class, 'store'])->middleware(['support.blocked', 'throttle:10,1'])->name('tv.pair.store');
        Route::post('/tv-screens/media', [TvPlaylistController::class, 'upload'])->middleware('throttle:30,1')->name('tv.media.store');
        Route::post('/tv-screens/media/{media}/delete', [TvPlaylistController::class, 'destroyMedia'])->whereNumber('media')->name('tv.media.delete');
        Route::get('/tv-screens/{screen}', [TvPlaylistController::class, 'edit'])->whereNumber('screen')->name('tv.edit');
        Route::post('/tv-screens/{screen}/settings', [TvPlaylistController::class, 'settings'])->whereNumber('screen')->name('tv.settings');
        Route::post('/tv-screens/{screen}/slides', [TvPlaylistController::class, 'storeSlide'])->whereNumber('screen')->name('tv.slides.store');
        Route::post('/tv-screens/{screen}/slides/{slide}', [TvPlaylistController::class, 'updateSlide'])->whereNumber(['screen', 'slide'])->name('tv.slides.update');
        Route::post('/tv-screens/{screen}/slides/{slide}/move', [TvPlaylistController::class, 'moveSlide'])->whereNumber(['screen', 'slide'])->name('tv.slides.move');
        Route::post('/tv-screens/{screen}/slides/{slide}/delete', [TvPlaylistController::class, 'destroySlide'])->whereNumber(['screen', 'slide'])->name('tv.slides.delete');
        Route::get('/tv-screens/{screen}/device', [TvScreenDeviceController::class, 'show'])->whereNumber('screen')->name('tv.device');
        Route::post('/tv-screens/{screen}/device', [TvScreenDeviceController::class, 'update'])->whereNumber('screen')->name('tv.device.update');
        Route::post('/tv-screens/{screen}/reload', [TvPlaylistController::class, 'reload'])->whereNumber('screen')->name('tv.reload');
        Route::post('/tv-screens/{screen}/unpair', [TvPlaylistController::class, 'unpair'])->whereNumber('screen')->middleware('support.blocked')->name('tv.unpair');
        Route::post('/tv-screens/{screen}', [TvScreensController::class, 'update'])->whereNumber('screen')->name('tv.update');
        Route::post('/tv-screens/{screen}/link', [TvScreensController::class, 'regenerate'])->name('tv.regenerate');
        Route::post('/tv-screens/{screen}/delete', [TvScreensController::class, 'destroy'])->name('tv.delete');
        Route::post('/tv-screens/{screen}/zones', [TvPlaylistController::class, 'zones'])->whereNumber('screen')->name('tv.zones');
        Route::post('/tv-screens/{screen}/copy', [TvPlaylistController::class, 'copy'])->whereNumber('screen')->name('tv.copy');
        // what every screen shows now (the screens pages refresh it), and the ready designs drawn for the picker
        Route::get('/tv-screens/live', [TvScreensController::class, 'live'])->middleware('throttle:60,1')->name('tv.live');
        Route::get('/tv-screens/design', [TvScreensController::class, 'design'])->name('tv.design');
        // «رسالة عاجلة» on the screens, from the phone
        Route::get('/tv-screens/urgent', [TvAlertsController::class, 'index'])->name('tv.alerts');
        Route::post('/tv-screens/urgent', [TvAlertsController::class, 'store'])->middleware('throttle:20,1')->name('tv.alerts.store');
        Route::post('/tv-screens/urgent/{alert}/clear', [TvAlertsController::class, 'clear'])->whereNumber('alert')->name('tv.alerts.clear');
        // screen groups with one shared playlist
        Route::post('/tv-groups', [TvGroupsController::class, 'store'])->name('tv.groups.store');
        Route::get('/tv-groups/{group}', [TvGroupsController::class, 'show'])->whereNumber('group')->name('tv.groups.show');
        Route::post('/tv-groups/{group}', [TvGroupsController::class, 'update'])->whereNumber('group')->name('tv.groups.update');
        Route::post('/tv-groups/{group}/delete', [TvGroupsController::class, 'destroy'])->whereNumber('group')->name('tv.groups.delete');
        Route::post('/tv-groups/{group}/zones', [TvGroupsController::class, 'zones'])->whereNumber('group')->name('tv.groups.zones');
        Route::post('/tv-groups/{group}/copy', [TvPlaylistController::class, 'copyGroup'])->whereNumber('group')->name('tv.groups.copy');
        Route::post('/tv-groups/{group}/slides', [TvPlaylistController::class, 'storeGroupSlide'])->whereNumber('group')->name('tv.groups.slides.store');
        Route::post('/tv-groups/{group}/slides/{slide}', [TvPlaylistController::class, 'updateGroupSlide'])->whereNumber(['group', 'slide'])->name('tv.groups.slides.update');
        Route::post('/tv-groups/{group}/slides/{slide}/move', [TvPlaylistController::class, 'moveGroupSlide'])->whereNumber(['group', 'slide'])->name('tv.groups.slides.move');
        Route::post('/tv-groups/{group}/slides/{slide}/delete', [TvPlaylistController::class, 'destroyGroupSlide'])->whereNumber(['group', 'slide'])->name('tv.groups.slides.delete');
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

    // Delivery drivers (same plan feature as delivery): the driver's own screen and its JSON actions (the driver app
    // wraps these), giving orders to drivers, and the live drivers page with their cash
    Route::middleware(['feature:delivery_zones|online_ordering', 'permission:delivery.drive'])->group(function () {
        Route::get('/driver', [DriverController::class, 'screen'])->name('driver');
        Route::get('/driver/orders', [DriverController::class, 'orders'])->name('driver.orders');
        Route::post('/driver/orders/{order}/picked-up', [DriverController::class, 'pickUp'])->whereNumber('order')->middleware('throttle:30,1')->name('driver.picked-up');
        Route::post('/driver/orders/{order}/delivered', [DriverController::class, 'deliver'])->whereNumber('order')->middleware('throttle:30,1')->name('driver.delivered');
        Route::post('/driver/orders/{order}/failed', [DriverController::class, 'fail'])->whereNumber('order')->middleware('throttle:30,1')->name('driver.failed');
        Route::post('/driver/location', [DriverController::class, 'location'])->middleware('throttle:12,1')->name('driver.location');
        Route::post('/driver/orders/{order}/tracking', [DriverController::class, 'tracking'])->whereNumber('order')->middleware('throttle:20,1')->name('driver.tracking');
    });
    Route::middleware(['feature:delivery_zones|online_ordering', 'permission:delivery.assign'])->group(function () {
        Route::post('/orders/{order}/driver', [DriversController::class, 'assign'])->whereNumber('order')->name('orders.driver');
    });
    Route::middleware(['feature:delivery_zones|online_ordering', 'permission:delivery.assign|delivery.drivers'])->group(function () {
        Route::get('/drivers', [DriversController::class, 'index'])->name('drivers');
        Route::get('/drivers/feed', [DriversController::class, 'feed'])->name('drivers.feed');
        Route::get('/drivers/orders/{order}/trail', [DriversController::class, 'trail'])->whereNumber('order')->name('drivers.trail');
    });
    Route::post('/drivers/{driver}/settle', [DriversController::class, 'settle'])->whereNumber('driver')
        ->middleware(['feature:delivery_zones|online_ordering', 'permission:delivery.drivers'])->name('drivers.settle');

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

    // Knowledge base ("الأسئلة والأجوبة"): written by the owner or a manager, read by the team in the call center, the POS
    // and the order board (feed), the public answers also on the guest menu's about page
    Route::middleware(['feature:knowledge_base', 'permission:kb.manage'])->group(function () {
        Route::get('/kb', [KnowledgeBaseController::class, 'index'])->name('kb');
        Route::get('/kb/create', [KnowledgeBaseController::class, 'create'])->name('kb.create');
        Route::post('/kb', [KnowledgeBaseController::class, 'store'])->middleware('throttle:60,1')->name('kb.store');
        Route::post('/kb/suggested', [KnowledgeBaseController::class, 'suggested'])->middleware('throttle:10,1')->name('kb.suggested');
        Route::get('/kb/{article}/edit', [KnowledgeBaseController::class, 'edit'])->whereNumber('article')->name('kb.edit');
        Route::post('/kb/{article}', [KnowledgeBaseController::class, 'update'])->whereNumber('article')->name('kb.update');
        Route::post('/kb/{article}/toggle', [KnowledgeBaseController::class, 'toggle'])->whereNumber('article')->name('kb.toggle');
        Route::post('/kb/{article}/move', [KnowledgeBaseController::class, 'move'])->whereNumber('article')->name('kb.move');
        Route::post('/kb/{article}/delete', [KnowledgeBaseController::class, 'destroy'])->whereNumber('article')->name('kb.delete');
        Route::post('/kb/categories', [KnowledgeBaseController::class, 'storeCategory'])->name('kb.categories.store');
        Route::post('/kb/categories/{category}', [KnowledgeBaseController::class, 'updateCategory'])->whereNumber('category')->name('kb.categories.update');
        Route::post('/kb/categories/{category}/move', [KnowledgeBaseController::class, 'moveCategory'])->whereNumber('category')->name('kb.categories.move');
        Route::post('/kb/categories/{category}/delete', [KnowledgeBaseController::class, 'destroyCategory'])->whereNumber('category')->name('kb.categories.delete');
    });
    Route::get('/kb/feed', [KnowledgeBaseController::class, 'feed'])->middleware(['feature:knowledge_base', 'permission:kb.view'])->name('kb.feed');

    // Corporate contracts: a company's prepaid vouchers for its staff (managed here, redeemed at the POS)
    Route::middleware(['feature:corporate_vouchers', 'permission:contracts.manage'])->group(function () {
        Route::get('/corporate', [CorporateContractsController::class, 'index'])->name('corporate');
        Route::get('/corporate/create', [CorporateContractsController::class, 'create'])->name('corporate.create');
        Route::post('/corporate', [CorporateContractsController::class, 'store'])->middleware('throttle:20,1')->name('corporate.store');
        Route::get('/corporate/{contract}', [CorporateContractsController::class, 'show'])->whereNumber('contract')->name('corporate.show');
        Route::get('/corporate/{contract}/edit', [CorporateContractsController::class, 'edit'])->whereNumber('contract')->name('corporate.edit');
        Route::post('/corporate/{contract}', [CorporateContractsController::class, 'update'])->whereNumber('contract')->name('corporate.update');
        Route::post('/corporate/{contract}/status', [CorporateContractsController::class, 'status'])->whereNumber('contract')->name('corporate.status');
        Route::post('/corporate/{contract}/extend', [CorporateContractsController::class, 'extend'])->whereNumber('contract')->name('corporate.extend');
        Route::get('/corporate/{contract}/print', [CorporateContractsController::class, 'print'])->whereNumber('contract')->name('corporate.print');
        Route::get('/corporate/{contract}/export', [CorporateContractsController::class, 'export'])->whereNumber('contract')->name('corporate.export');
        Route::get('/corporate/{contract}/statement', [CorporateContractsController::class, 'statement'])->whereNumber('contract')->name('corporate.statement');
        Route::get('/corporate/vouchers/{voucher}/receipt', [CorporateContractsController::class, 'receipt'])->whereNumber('voucher')->name('corporate.voucher.receipt');
        Route::post('/corporate/vouchers/{voucher}/extend', [CorporateContractsController::class, 'extendVoucher'])->whereNumber('voucher')->name('corporate.voucher.extend');
        Route::post('/corporate/vouchers/{voucher}/void', [CorporateContractsController::class, 'voidVoucher'])->whereNumber('voucher')->name('corporate.voucher.void');
    });
    // ... and at the POS: look up by code / QR / mobile, exceptions after expiry (manager), link to the guest's mobile
    Route::middleware(['feature:corporate_vouchers', 'feature:pos', 'permission:pos.use', 'permission:vouchers.redeem'])->group(function () {
        Route::post('/pos/vouchers/check', [CorporateRedeemController::class, 'check'])->middleware('throttle:60,1')->name('pos.corporate.check');
        Route::post('/pos/vouchers/link', [CorporateRedeemController::class, 'link'])->middleware('throttle:30,1')->name('pos.corporate.link');
        Route::post('/pos/vouchers/{voucher}/exception', [CorporateRedeemController::class, 'exception'])->whereNumber('voucher')
            ->middleware('throttle:20,1')->name('pos.corporate.exception');
    });
    // ... and on their own screen, without the POS ("صرف الفاوتشرات"): on an open order of the branch or a paper bill.
    // Named dash.redeem.*: dash.vouchers.* belongs to the loyalty vouchers (Coupons & vouchers).
    Route::middleware(['feature:corporate_vouchers', 'permission:vouchers.redeem'])->group(function () {
        Route::get('/vouchers/redeem', [VoucherRedeemController::class, 'index'])->name('redeem');
        Route::post('/vouchers/redeem/check', [CorporateRedeemController::class, 'check'])->middleware('throttle:60,1')->name('redeem.check');
        Route::post('/vouchers/redeem/link', [CorporateRedeemController::class, 'link'])->middleware('throttle:30,1')->name('redeem.link');
        Route::post('/vouchers/redeem/{voucher}/exception', [CorporateRedeemController::class, 'exception'])->whereNumber('voucher')
            ->middleware('throttle:20,1')->name('redeem.exception');
        Route::get('/vouchers/redeem/orders', [VoucherRedeemController::class, 'orders'])->name('redeem.orders');
        Route::post('/vouchers/redeem/orders/{order}/pay', [VoucherRedeemController::class, 'pay'])->whereNumber('order')
            ->middleware('throttle:30,1')->name('redeem.pay');
        Route::post('/vouchers/redeem/manual', [VoucherRedeemController::class, 'manual'])->middleware('throttle:30,1')->name('redeem.manual');
        Route::post('/vouchers/redeem/batches/{batch}/cancel', [VoucherRedeemController::class, 'cancel'])->where('batch', '[a-f0-9]{8,24}')
            ->middleware('throttle:20,1')->name('redeem.cancel');
        Route::get('/vouchers/redeem/batches/{batch}/receipt', [VoucherRedeemController::class, 'receipt'])->where('batch', '[a-f0-9]{8,24}')->name('redeem.receipt');
    });
    // the vouchers report: issued, redeemed, outstanding, lost, expired; by contract, branch, cashier and day
    Route::middleware(['feature:corporate_vouchers', 'permission:contracts.manage|reports.view'])->group(function () {
        Route::get('/corporate/report', [CorporateReportController::class, 'index'])->name('corporate.report');
        Route::get('/corporate/report/export', [CorporateReportController::class, 'export'])->name('corporate.report.export');
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
        Route::post('/marketing/banners/autoplay', [MarketingController::class, 'saveBannerAutoplay'])->name('banners.autoplay');
        // the ticker under the menu header: the owner's own short lines
        Route::post('/marketing/ticker', [MarketingController::class, 'saveTicker'])->name('ticker.update');
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
// (the account's password, two-factor and PIN are the owner's own: not for EgWeb support signed in from the admin)
Route::middleware(['auth:web', 'tenant.user'])->group(function () {
    Route::get('/security', [SecurityController::class, 'show'])->name('security');
    Route::post('/security/two-factor', [SecurityController::class, 'enable'])->middleware('support.blocked')->name('security.enable');
    Route::post('/security/two-factor/confirm', [SecurityController::class, 'confirm'])->middleware(['support.blocked', 'throttle:10,1'])->name('security.confirm');
    Route::post('/security/two-factor/disable', [SecurityController::class, 'disable'])->middleware(['support.blocked', 'throttle:10,1'])->name('security.disable');
    Route::post('/security/pin', [SecurityController::class, 'pin'])->middleware(['support.blocked', 'throttle:10,1'])->name('security.pin');
    // the account's own password (a welcome-kit owner must pick one before anything else: InitializeTenancyFromUser)
    Route::post('/security/password', [SecurityController::class, 'password'])->middleware(['support.blocked', 'throttle:10,1'])->name('security.password');
});

// ---- The sample data EgWeb filled in for a prospect (App\Services\DemoContent): the owner clears it in one go ----
Route::middleware(['auth:web', 'tenant.user'])->group(function () {
    Route::post('/demo/clear', [DemoContentController::class, 'destroy'])->middleware('throttle:5,1')->name('demo.clear');
});

// ---- Onboarding checklist on the home page (App\Services\Onboarding) and the illustrated tutorial ----
Route::middleware(['auth:web', 'tenant.user'])->group(function () {
    Route::post('/onboarding/skip', [OnboardingController::class, 'skip'])->name('onboarding.skip');
    Route::post('/onboarding/dismiss', [OnboardingController::class, 'dismiss'])->name('onboarding.dismiss');
    Route::get('/help', [OnboardingController::class, 'help'])->name('help');
});

// ---- The dashboard as an installable app (App\Services\Pwa): manifest and a static-files-only service worker ----
Route::get('/manifest.webmanifest', [PwaController::class, 'manifest'])->name('manifest');
// without the session: the browser re-checks sw.js while it navigates, and a session there used up the flash
// message ("Item saved.") meant for the page being opened
Route::get('/sw.js', [PwaController::class, 'serviceWorker'])->name('sw')->withoutMiddleware([
    StartSession::class, ShareErrorsFromSession::class, PreventRequestForgery::class,
]);
