<?php

use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\InvoicesController;
use App\Http\Controllers\Admin\PlansController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\TenantsController;
use Illuminate\Support\Facades\Route;

// admin.<domain> — EgWeb super admin. Guard "admin" = platform_admins table, never restaurant staff.
Route::get('/login', [AuthController::class, 'show'])->name('login');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');

Route::middleware('auth:admin')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/', [DashboardController::class, 'index'])->name('home');

    Route::get('/tenants', [TenantsController::class, 'index'])->name('tenants');
    Route::get('/tenants/create', [TenantsController::class, 'create'])->name('tenants.create');
    Route::post('/tenants', [TenantsController::class, 'store'])->name('tenants.store');
    Route::get('/tenants/{tenant}', [TenantsController::class, 'show'])->name('tenants.show');
    Route::post('/tenants/{tenant}/subscription', [TenantsController::class, 'subscription'])->name('tenants.subscription');
    Route::post('/tenants/{tenant}/addons', [TenantsController::class, 'addons'])->name('tenants.addons');
    Route::post('/tenants/{tenant}/suspend', [TenantsController::class, 'suspend'])->name('tenants.suspend');
    Route::post('/tenants/{tenant}/activate', [TenantsController::class, 'activate'])->name('tenants.activate');

    Route::get('/plans', [PlansController::class, 'index'])->name('plans');
    Route::post('/addons', [PlansController::class, 'addons'])->name('addons.update');
    Route::get('/plans/{plan}', [PlansController::class, 'edit'])->name('plans.edit');
    Route::post('/plans/{plan}', [PlansController::class, 'update'])->name('plans.update');

    Route::get('/invoices', [InvoicesController::class, 'index'])->name('invoices');
    Route::post('/invoices/{invoice}/paid', [InvoicesController::class, 'markPaid'])->name('invoices.paid');
    Route::post('/invoices/{invoice}/void', [InvoicesController::class, 'void'])->name('invoices.void');

    Route::get('/settings', [SettingsController::class, 'edit'])->name('settings');
    Route::post('/settings', [SettingsController::class, 'update'])->name('settings.update');
});
