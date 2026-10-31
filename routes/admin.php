<?php

use App\Http\Controllers\Admin\ActivityController;
use App\Http\Controllers\Admin\AdminsController;
use App\Http\Controllers\Admin\AnnouncementsController;
use App\Http\Controllers\Admin\AssistantController;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\BlogController;
use App\Http\Controllers\Admin\BrandingController;
use App\Http\Controllers\Admin\CallScriptController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\DomainsController;
use App\Http\Controllers\Admin\EgwebClientsController;
use App\Http\Controllers\Admin\InvoicesController;
use App\Http\Controllers\Admin\LeadsController;
use App\Http\Controllers\Admin\PasswordResetController;
use App\Http\Controllers\Admin\PlansController;
use App\Http\Controllers\Admin\ProspectImportController;
use App\Http\Controllers\Admin\ProspectsController;
use App\Http\Controllers\Admin\SalesTeamController;
use App\Http\Controllers\Admin\SearchController;
use App\Http\Controllers\Admin\SecurityController;
use App\Http\Controllers\Admin\ServicesController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\SubscriptionsController;
use App\Http\Controllers\Admin\SupportAccessController;
use App\Http\Controllers\Admin\SystemController;
use App\Http\Controllers\Admin\TenantDiscountsController;
use App\Http\Controllers\Admin\TenantsController;
use App\Http\Controllers\Admin\TenantUsersController;
use App\Http\Controllers\Admin\TestimonialsController;
use App\Http\Controllers\Admin\TwoFactorController;
use App\Http\Controllers\Admin\VisitsController;
use Illuminate\Support\Facades\Route;

// admin.<domain> — EgWeb super admin. Guard "admin" = platform_admins table, never restaurant staff.
// Every page past the login checks admin.access (switched-off admins are signed out) and, where named, one
// permission of App\Support\AdminPermissions (403 otherwise; the sidebar hides what the admin cannot open).
Route::get('/login', [AuthController::class, 'show'])->name('login');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
Route::get('/forgot-password', [PasswordResetController::class, 'request'])->name('password.request');
Route::post('/forgot-password', [PasswordResetController::class, 'send'])->middleware('throttle:5,1')->name('password.email');
Route::get('/reset-password/{token}', [PasswordResetController::class, 'edit'])->name('password.reset');
Route::post('/reset-password', [PasswordResetController::class, 'update'])->middleware('throttle:10,1')->name('password.update');

Route::middleware(['auth:admin', 'admin.access'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/', [DashboardController::class, 'index'])->name('home');
    // the header search (each group by the admin's permissions, checked in the controller)
    Route::get('/search', SearchController::class)->middleware('throttle:60,1')->name('search');

    // Restaurants: tenants.view to look, tenants.manage to change them and their users, billing.manage for money
    Route::middleware('admin.access:tenants.view')->group(function () {
        Route::get('/tenants', [TenantsController::class, 'index'])->name('tenants');
        Route::get('/tenants/create', [TenantsController::class, 'create'])->middleware('admin.access:tenants.manage')->name('tenants.create');
        // the list with its filters as a CSV (a slug never has a dot, so no restaurant is shadowed)
        Route::get('/tenants/export.csv', [TenantsController::class, 'export'])->middleware('throttle:10,1')->name('tenants.export');
        Route::get('/tenants/{tenant}', [TenantsController::class, 'show'])->name('tenants.show');
    });
    Route::middleware('admin.access:tenants.manage')->group(function () {
        Route::post('/tenants', [TenantsController::class, 'store'])->name('tenants.store');
        Route::post('/tenants/{tenant}/info', [TenantsController::class, 'info'])->name('tenants.info');
        // the owner's welcome kit: a new temporary password and a new welcome page link (App\Services\WelcomeKit)
        Route::post('/tenants/{tenant}/welcome-kit', [TenantsController::class, 'welcomeKit'])->middleware('throttle:10,1')->name('tenants.welcome-kit');
        Route::post('/tenants/{tenant}/showcase', [TenantsController::class, 'showcase'])->name('tenants.showcase');
        Route::post('/tenants/{tenant}/directory', [TenantsController::class, 'directory'])->name('tenants.directory');
        Route::post('/tenants/{tenant}/suspend', [TenantsController::class, 'suspend'])->name('tenants.suspend');
        Route::post('/tenants/{tenant}/activate', [TenantsController::class, 'activate'])->name('tenants.activate');
        // delete for good: the platform owner only (checked in the controller), with the slug typed to confirm
        Route::post('/tenants/{tenant}/delete', [TenantsController::class, 'destroy'])->middleware('throttle:10,1')->name('tenants.destroy');
        Route::post('/tenants/{tenant}/users/{user}/verify-phone', [TenantsController::class, 'verifyPhone'])->whereNumber('user')->name('tenants.verify-phone');
        Route::post('/tenants/{tenant}/users/{user}/reset-link', [TenantUsersController::class, 'sendResetLink'])->whereNumber('user')->middleware('throttle:10,1')->name('tenants.users.reset-link');
        Route::post('/tenants/{tenant}/users/{user}/password', [TenantUsersController::class, 'setPassword'])->whereNumber('user')->middleware('throttle:10,1')->name('tenants.users.password');
        Route::post('/tenants/{tenant}/users/{user}/sign-out', [TenantUsersController::class, 'signOut'])->whereNumber('user')->name('tenants.users.sign-out');
        Route::post('/domains/{domain}', [DomainsController::class, 'update'])->name('domains.update');
        // «إعلانات للمطاعم»: a banner on the restaurant dashboards (all of them or one plan) between two dates
        Route::get('/announcements', [AnnouncementsController::class, 'index'])->name('announcements');
        Route::post('/announcements', [AnnouncementsController::class, 'store'])->name('announcements.store');
        Route::get('/announcements/{announcement}/edit', [AnnouncementsController::class, 'edit'])->whereNumber('announcement')->name('announcements.edit');
        Route::post('/announcements/{announcement}', [AnnouncementsController::class, 'update'])->whereNumber('announcement')->name('announcements.update');
        Route::post('/announcements/{announcement}/delete', [AnnouncementsController::class, 'destroy'])->whereNumber('announcement')->name('announcements.delete');
    });
    // "open the restaurant's dashboard" as its owner (or a picked staff account): a one-time ticket, then app.<domain>
    Route::post('/tenants/{tenant}/support-access', [SupportAccessController::class, 'store'])
        ->middleware(['admin.access:tenants.impersonate', 'throttle:10,1'])->name('tenants.support-access');
    Route::middleware('admin.access:billing.manage')->group(function () {
        Route::post('/tenants/{tenant}/subscription', [TenantsController::class, 'subscription'])->name('tenants.subscription');
        Route::post('/tenants/{tenant}/addons', [TenantsController::class, 'addons'])->name('tenants.addons');
        Route::post('/tenants/{tenant}/credits', [TenantsController::class, 'credits'])->name('tenants.credits');
        Route::get('/invoices', [InvoicesController::class, 'index'])->name('invoices');
        Route::post('/invoices/{invoice}/paid', [InvoicesController::class, 'markPaid'])->name('invoices.paid');
        Route::post('/invoices/{invoice}/void', [InvoicesController::class, 'void'])->name('invoices.void');
        // «الاشتراكات والإيرادات»: by plan and status, the MRR, new and churned, revenue per month, what ends soon
        Route::get('/subscriptions', [SubscriptionsController::class, 'index'])->name('subscriptions');
        Route::get('/subscriptions/export.csv', [SubscriptionsController::class, 'export'])->middleware('throttle:10,1')->name('subscriptions.export');
    });

    // «متابعة العملاء»: every restaurant is a lead. crm.view to look, crm.manage to log calls and change them, crm.discount
    // to give a discount on the next invoice within the admin's limit; the call script is edited by the platform owner
    Route::middleware('admin.access:crm.view')->group(function () {
        Route::get('/crm', [LeadsController::class, 'index'])->name('crm');
        Route::get('/crm/leads/{lead}', [LeadsController::class, 'show'])->whereNumber('lead')->name('crm.show');
        Route::get('/crm/leads/{lead}/offer', [LeadsController::class, 'offer'])->whereNumber('lead')->name('crm.offer');
    });
    Route::middleware('admin.access:crm.manage')->group(function () {
        Route::post('/crm/leads/{lead}/calls', [LeadsController::class, 'call'])->whereNumber('lead')->name('crm.call');
        Route::post('/crm/leads/{lead}', [LeadsController::class, 'update'])->whereNumber('lead')->name('crm.update');
        Route::get('/crm/script', [CallScriptController::class, 'index'])->name('crm.script');
        Route::post('/crm/script', [CallScriptController::class, 'store'])->name('crm.script.store');
        Route::post('/crm/script/defaults', [CallScriptController::class, 'defaults'])->name('crm.script.defaults');
        Route::post('/crm/script/{item}', [CallScriptController::class, 'update'])->whereNumber('item')->name('crm.script.update');
        Route::post('/crm/script/{item}/move', [CallScriptController::class, 'move'])->whereNumber('item')->name('crm.script.move');
        Route::post('/crm/script/{item}/delete', [CallScriptController::class, 'destroy'])->whereNumber('item')->name('crm.script.delete');
    });
    // the field sales team: places without an account yet (a field rep sees only his), visits, the map import, the
    // team's targets and pay, and the printed playbook
    Route::middleware('admin.access:crm.view')->group(function () {
        Route::get('/crm/prospects', [ProspectsController::class, 'index'])->name('crm.prospects');
        Route::post('/crm/prospects', [ProspectsController::class, 'store'])->name('crm.prospects.store');
        Route::get('/crm/prospects/{prospect}', [ProspectsController::class, 'show'])->whereNumber('prospect')->name('crm.prospects.show');
        Route::post('/crm/prospects/{prospect}', [ProspectsController::class, 'update'])->whereNumber('prospect')->name('crm.prospects.update');
        Route::post('/crm/appointments/{appointment}', [ProspectsController::class, 'appointment'])->whereNumber('appointment')->name('crm.appointments.update');
        Route::get('/crm/appointments/{appointment}/photos/{index}', [VisitsController::class, 'photo'])->whereNumber(['appointment', 'index'])->name('crm.appointments.photo');
        Route::get('/crm/playbook', [SalesTeamController::class, 'playbook'])->name('crm.playbook');
    });
    Route::middleware('admin.access:crm.manage')->group(function () {
        Route::post('/crm/prospects/assign', [ProspectsController::class, 'bulkAssign'])->name('crm.prospects.bulk');
        Route::post('/crm/prospects/{prospect}/calls', [ProspectsController::class, 'call'])->whereNumber('prospect')->name('crm.prospects.call');
        Route::post('/crm/prospects/{prospect}/assign', [ProspectsController::class, 'assign'])->whereNumber('prospect')->name('crm.prospects.assign');
        Route::post('/crm/prospects/{prospect}/book', [ProspectsController::class, 'book'])->whereNumber('prospect')->name('crm.prospects.book');
    });
    Route::middleware('admin.access:crm.visits')->group(function () {
        Route::get('/crm/visits', [VisitsController::class, 'index'])->name('crm.visits');
        Route::post('/crm/visits/{appointment}/check-in', [VisitsController::class, 'checkIn'])->whereNumber('appointment')->name('crm.visits.checkin');
        Route::post('/crm/visits/{appointment}/report', [VisitsController::class, 'report'])->whereNumber('appointment')->name('crm.visits.report');
        Route::post('/crm/prospects/{prospect}/walk-in', [VisitsController::class, 'walkIn'])->whereNumber('prospect')->name('crm.visits.walkin');
    });
    Route::middleware('admin.access:crm.import')->group(function () {
        Route::get('/crm/import', [ProspectImportController::class, 'show'])->name('crm.import');
        Route::post('/crm/import/map', [ProspectImportController::class, 'map'])->middleware('throttle:10,1')->name('crm.import.map');
        Route::post('/crm/import/csv', [ProspectImportController::class, 'csv'])->name('crm.import.csv');
    });
    Route::middleware('admin.access:crm.team')->group(function () {
        Route::get('/crm/team', [SalesTeamController::class, 'index'])->name('crm.team');
        Route::post('/crm/team/plan', [SalesTeamController::class, 'plan'])->name('crm.team.plan');
        Route::post('/crm/team/{member}/targets', [SalesTeamController::class, 'targets'])->whereNumber('member')->name('crm.team.targets');
    });
    Route::middleware('admin.access:crm.discount')->group(function () {
        Route::post('/crm/leads/{lead}/discount', [TenantDiscountsController::class, 'store'])->whereNumber('lead')->name('crm.discount');
        Route::post('/crm/discounts/{discount}/revoke', [TenantDiscountsController::class, 'revoke'])->whereNumber('discount')->name('crm.discount.revoke');
    });

    // Plans and add-ons: two tabs of one section; each add-on is edited on its own page and saved through the same route
    Route::middleware('admin.access:plans.manage')->group(function () {
        Route::get('/plans', [PlansController::class, 'index'])->name('plans');
        Route::get('/addons', [PlansController::class, 'addonsIndex'])->name('addons');
        Route::get('/prices', [PlansController::class, 'prices'])->name('prices');
        Route::post('/prices', [PlansController::class, 'updatePrices'])->name('prices.update');
        Route::get('/addons/{addon}/edit', [PlansController::class, 'editAddon'])->whereNumber('addon')->name('addons.edit');
        Route::post('/addons', [PlansController::class, 'addons'])->name('addons.update');
        Route::post('/addons/{addon}/toggle', [PlansController::class, 'toggleAddon'])->whereNumber('addon')->name('addons.toggle');
        Route::get('/plans/{plan}', [PlansController::class, 'edit'])->name('plans.edit');
        Route::post('/plans/{plan}', [PlansController::class, 'update'])->name('plans.update');
        Route::post('/plans/{plan}/archive', [PlansController::class, 'archive'])->name('plans.archive');
        Route::post('/plans/{plan}/unarchive', [PlansController::class, 'unarchive'])->name('plans.unarchive');
    });

    Route::middleware('admin.access:settings.manage')->group(function () {
        Route::get('/settings', [SettingsController::class, 'edit'])->name('settings');
        Route::post('/settings', [SettingsController::class, 'update'])->name('settings.update');
        // the platform look: logo, favicon, site name and brand colors (App\Support\PlatformBrand)
        // «صحة النظام»: the deploy checklist, background jobs, backups, outside services, the automatic deploy log
        Route::get('/system', [SystemController::class, 'index'])->name('system');
        // a failed background job sent back to its queue, or forgotten (both in the activity log)
        Route::post('/system/failed-jobs/{uuid}/retry', [SystemController::class, 'retry'])->whereUuid('uuid')->name('system.retry');
        Route::post('/system/failed-jobs/{uuid}/delete', [SystemController::class, 'forget'])->whereUuid('uuid')->name('system.forget');
        Route::get('/branding', [BrandingController::class, 'edit'])->name('branding');
        Route::post('/branding', [BrandingController::class, 'update'])->name('branding.update');
        Route::post('/branding/reset', [BrandingController::class, 'reset'])->name('branding.reset');
        // logos of EgWeb's clients on the home page (App\Services\Seo\EgwebClients)
        Route::post('/branding/clients', [EgwebClientsController::class, 'store'])->name('clients.store');
        Route::post('/branding/clients/{key}', [EgwebClientsController::class, 'update'])->where('key', '[A-Za-z0-9-]+')->name('clients.update');
        Route::post('/branding/clients/{key}/move', [EgwebClientsController::class, 'move'])->where('key', '[A-Za-z0-9-]+')->name('clients.move');
        Route::post('/branding/clients/{key}/delete', [EgwebClientsController::class, 'destroy'])->where('key', '[A-Za-z0-9-]+')->name('clients.delete');
    });

    Route::middleware('admin.access:services.manage')->group(function () {
        Route::get('/services', [ServicesController::class, 'index'])->name('services');
        Route::post('/services/{serviceRequest}', [ServicesController::class, 'update'])->whereNumber('serviceRequest')->name('services.update');
    });

    Route::middleware('admin.access:content.manage')->group(function () {
        // Blog of the marketing site (eghub.app/blog): Markdown articles, covers, publish/unpublish
        Route::get('/blog', [BlogController::class, 'index'])->name('blog');
        Route::get('/blog/create', [BlogController::class, 'create'])->name('blog.create');
        Route::post('/blog', [BlogController::class, 'store'])->name('blog.store');
        Route::get('/blog/{post}/edit', [BlogController::class, 'edit'])->whereNumber('post')->name('blog.edit');
        Route::post('/blog/{post}', [BlogController::class, 'update'])->whereNumber('post')->name('blog.update');
        Route::post('/blog/{post}/publish', [BlogController::class, 'publish'])->whereNumber('post')->name('blog.publish');
        Route::post('/blog/{post}/delete', [BlogController::class, 'destroy'])->whereNumber('post')->name('blog.delete');

        // Real owner quotes on the home page (published only with the owner's consent)
        Route::get('/testimonials', [TestimonialsController::class, 'index'])->name('testimonials');
        Route::get('/testimonials/create', [TestimonialsController::class, 'create'])->name('testimonials.create');
        Route::post('/testimonials', [TestimonialsController::class, 'store'])->name('testimonials.store');
        Route::get('/testimonials/{testimonial}/edit', [TestimonialsController::class, 'edit'])->whereNumber('testimonial')->name('testimonials.edit');
        Route::post('/testimonials/{testimonial}', [TestimonialsController::class, 'update'])->whereNumber('testimonial')->name('testimonials.update');
        Route::post('/testimonials/{testimonial}/publish', [TestimonialsController::class, 'publish'])->whereNumber('testimonial')->name('testimonials.publish');
        Route::post('/testimonials/{testimonial}/delete', [TestimonialsController::class, 'destroy'])->whereNumber('testimonial')->name('testimonials.delete');
    });

    // What visitors asked the website assistant ("اسألنا") and where it handed over to WhatsApp
    Route::get('/assistant', [AssistantController::class, 'index'])->middleware('admin.access:assistant.view')->name('assistant');

    // The admin team: who can sign in here and what each one may do
    Route::middleware('admin.access:admins.manage')->group(function () {
        // «سجل العمليات»: what the team changed on the platform side (App\Models\PlatformAuditLog)
        Route::get('/activity', [ActivityController::class, 'index'])->name('activity');
        Route::get('/team', [AdminsController::class, 'index'])->name('admins');
        Route::get('/team/create', [AdminsController::class, 'create'])->name('admins.create');
        Route::post('/team', [AdminsController::class, 'store'])->name('admins.store');
        Route::get('/team/{member}/edit', [AdminsController::class, 'edit'])->whereNumber('member')->name('admins.edit');
        Route::post('/team/{member}', [AdminsController::class, 'update'])->whereNumber('member')->name('admins.update');
        Route::post('/team/{member}/reset-link', [AdminsController::class, 'sendResetLink'])->whereNumber('member')->middleware('throttle:10,1')->name('admins.reset-link');
        Route::post('/team/{member}/delete', [AdminsController::class, 'destroy'])->whereNumber('member')->name('admins.delete');
    });
});

// ---- Two-factor authentication: second login step, the admin's own security page, 2FA reset of a tenant user (support) ----
Route::get('/two-factor', [TwoFactorController::class, 'show'])->name('two-factor');
Route::post('/two-factor', [TwoFactorController::class, 'verify'])->middleware('throttle:5,1')->name('two-factor.verify');
Route::middleware(['auth:admin', 'admin.access'])->group(function () {
    Route::get('/security', [SecurityController::class, 'show'])->name('security');
    Route::post('/security/two-factor', [SecurityController::class, 'enable'])->name('security.enable');
    Route::post('/security/two-factor/confirm', [SecurityController::class, 'confirm'])->middleware('throttle:10,1')->name('security.confirm');
    Route::post('/security/two-factor/disable', [SecurityController::class, 'disable'])->middleware('throttle:10,1')->name('security.disable');
    Route::post('/security/password', [SecurityController::class, 'password'])->middleware('throttle:10,1')->name('security.password');
    Route::post('/tenants/{tenant}/users/{user}/reset-2fa', [TenantsController::class, 'resetTwoFactor'])->whereNumber('user')->middleware('admin.access:tenants.manage')->name('tenants.reset-2fa');
});
