<?php

use App\Http\Controllers\Admin\AdminsController;
use App\Http\Controllers\Admin\AssistantController;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\BlogController;
use App\Http\Controllers\Admin\BrandingController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\DomainsController;
use App\Http\Controllers\Admin\InvoicesController;
use App\Http\Controllers\Admin\PasswordResetController;
use App\Http\Controllers\Admin\PlansController;
use App\Http\Controllers\Admin\SecurityController;
use App\Http\Controllers\Admin\ServicesController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\SupportAccessController;
use App\Http\Controllers\Admin\TenantsController;
use App\Http\Controllers\Admin\TenantUsersController;
use App\Http\Controllers\Admin\TestimonialsController;
use App\Http\Controllers\Admin\TwoFactorController;
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

    // Restaurants: tenants.view to look, tenants.manage to change them and their users, billing.manage for money
    Route::middleware('admin.access:tenants.view')->group(function () {
        Route::get('/tenants', [TenantsController::class, 'index'])->name('tenants');
        Route::get('/tenants/create', [TenantsController::class, 'create'])->middleware('admin.access:tenants.manage')->name('tenants.create');
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
    });

    // Plans and add-ons: two tabs of one section; each add-on is edited on its own page and saved through the same route
    Route::middleware('admin.access:plans.manage')->group(function () {
        Route::get('/plans', [PlansController::class, 'index'])->name('plans');
        Route::get('/addons', [PlansController::class, 'addonsIndex'])->name('addons');
        Route::get('/addons/{addon}/edit', [PlansController::class, 'editAddon'])->whereNumber('addon')->name('addons.edit');
        Route::post('/addons', [PlansController::class, 'addons'])->name('addons.update');
        Route::post('/addons/{addon}/toggle', [PlansController::class, 'toggleAddon'])->whereNumber('addon')->name('addons.toggle');
        Route::get('/plans/{plan}', [PlansController::class, 'edit'])->name('plans.edit');
        Route::post('/plans/{plan}', [PlansController::class, 'update'])->name('plans.update');
    });

    Route::middleware('admin.access:settings.manage')->group(function () {
        Route::get('/settings', [SettingsController::class, 'edit'])->name('settings');
        Route::post('/settings', [SettingsController::class, 'update'])->name('settings.update');
        // the platform look: logo, favicon, site name and brand colors (App\Support\PlatformBrand)
        Route::get('/branding', [BrandingController::class, 'edit'])->name('branding');
        Route::post('/branding', [BrandingController::class, 'update'])->name('branding.update');
        Route::post('/branding/reset', [BrandingController::class, 'reset'])->name('branding.reset');
    });

    Route::middleware('admin.access:services.manage')->group(function () {
        Route::get('/services', [ServicesController::class, 'index'])->name('services');
        Route::post('/services/{serviceRequest}', [ServicesController::class, 'update'])->whereNumber('serviceRequest')->name('services.update');
    });

    Route::middleware('admin.access:content.manage')->group(function () {
        // Blog of the marketing site (egmenu.app/blog): Markdown articles, covers, publish/unpublish
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
    Route::post('/tenants/{tenant}/users/{user}/reset-2fa', [TenantsController::class, 'resetTwoFactor'])->whereNumber('user')->middleware('admin.access:tenants.manage')->name('tenants.reset-2fa');
});
