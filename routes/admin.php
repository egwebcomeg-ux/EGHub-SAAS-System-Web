<?php

use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\BlogController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\DomainsController;
use App\Http\Controllers\Admin\InvoicesController;
use App\Http\Controllers\Admin\PasswordResetController;
use App\Http\Controllers\Admin\PlansController;
use App\Http\Controllers\Admin\SecurityController;
use App\Http\Controllers\Admin\ServicesController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\TenantsController;
use App\Http\Controllers\Admin\TwoFactorController;
use Illuminate\Support\Facades\Route;

// admin.<domain> — EgWeb super admin. Guard "admin" = platform_admins table, never restaurant staff.
Route::get('/login', [AuthController::class, 'show'])->name('login');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
Route::get('/forgot-password', [PasswordResetController::class, 'request'])->name('password.request');
Route::post('/forgot-password', [PasswordResetController::class, 'send'])->middleware('throttle:5,1')->name('password.email');
Route::get('/reset-password/{token}', [PasswordResetController::class, 'edit'])->name('password.reset');
Route::post('/reset-password', [PasswordResetController::class, 'update'])->middleware('throttle:10,1')->name('password.update');

Route::middleware('auth:admin')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/', [DashboardController::class, 'index'])->name('home');

    Route::get('/tenants', [TenantsController::class, 'index'])->name('tenants');
    Route::get('/tenants/create', [TenantsController::class, 'create'])->name('tenants.create');
    Route::post('/tenants', [TenantsController::class, 'store'])->name('tenants.store');
    Route::get('/tenants/{tenant}', [TenantsController::class, 'show'])->name('tenants.show');
    Route::post('/tenants/{tenant}/subscription', [TenantsController::class, 'subscription'])->name('tenants.subscription');
    Route::post('/tenants/{tenant}/addons', [TenantsController::class, 'addons'])->name('tenants.addons');
    Route::post('/tenants/{tenant}/credits', [TenantsController::class, 'credits'])->name('tenants.credits');
    Route::post('/tenants/{tenant}/suspend', [TenantsController::class, 'suspend'])->name('tenants.suspend');
    Route::post('/tenants/{tenant}/activate', [TenantsController::class, 'activate'])->name('tenants.activate');
    Route::post('/tenants/{tenant}/users/{user}/verify-phone', [TenantsController::class, 'verifyPhone'])->whereNumber('user')->name('tenants.verify-phone');
    Route::post('/domains/{domain}', [DomainsController::class, 'update'])->name('domains.update');

    Route::get('/plans', [PlansController::class, 'index'])->name('plans');
    Route::post('/addons', [PlansController::class, 'addons'])->name('addons.update');
    Route::get('/plans/{plan}', [PlansController::class, 'edit'])->name('plans.edit');
    Route::post('/plans/{plan}', [PlansController::class, 'update'])->name('plans.update');

    Route::get('/invoices', [InvoicesController::class, 'index'])->name('invoices');
    Route::post('/invoices/{invoice}/paid', [InvoicesController::class, 'markPaid'])->name('invoices.paid');
    Route::post('/invoices/{invoice}/void', [InvoicesController::class, 'void'])->name('invoices.void');

    Route::get('/settings', [SettingsController::class, 'edit'])->name('settings');
    Route::post('/settings', [SettingsController::class, 'update'])->name('settings.update');

    Route::get('/services', [ServicesController::class, 'index'])->name('services');
    Route::post('/services/{serviceRequest}', [ServicesController::class, 'update'])->whereNumber('serviceRequest')->name('services.update');

    // Blog of the marketing site (egmenu.app/blog): Markdown articles, covers, publish/unpublish
    Route::get('/blog', [BlogController::class, 'index'])->name('blog');
    Route::get('/blog/create', [BlogController::class, 'create'])->name('blog.create');
    Route::post('/blog', [BlogController::class, 'store'])->name('blog.store');
    Route::get('/blog/{post}/edit', [BlogController::class, 'edit'])->whereNumber('post')->name('blog.edit');
    Route::post('/blog/{post}', [BlogController::class, 'update'])->whereNumber('post')->name('blog.update');
    Route::post('/blog/{post}/publish', [BlogController::class, 'publish'])->whereNumber('post')->name('blog.publish');
    Route::post('/blog/{post}/delete', [BlogController::class, 'destroy'])->whereNumber('post')->name('blog.delete');
});

// ---- Two-factor authentication: second login step, the admin's own security page, 2FA reset of a tenant user (support) ----
Route::get('/two-factor', [TwoFactorController::class, 'show'])->name('two-factor');
Route::post('/two-factor', [TwoFactorController::class, 'verify'])->middleware('throttle:5,1')->name('two-factor.verify');
Route::middleware('auth:admin')->group(function () {
    Route::get('/security', [SecurityController::class, 'show'])->name('security');
    Route::post('/security/two-factor', [SecurityController::class, 'enable'])->name('security.enable');
    Route::post('/security/two-factor/confirm', [SecurityController::class, 'confirm'])->middleware('throttle:10,1')->name('security.confirm');
    Route::post('/security/two-factor/disable', [SecurityController::class, 'disable'])->middleware('throttle:10,1')->name('security.disable');
    Route::post('/tenants/{tenant}/users/{user}/reset-2fa', [TenantsController::class, 'resetTwoFactor'])->whereNumber('user')->name('tenants.reset-2fa');
});
