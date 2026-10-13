<?php

use App\Http\Controllers\Dashboard\AuthController;
use App\Http\Controllers\Dashboard\BillingController;
use App\Http\Controllers\Dashboard\BranchesController;
use App\Http\Controllers\Dashboard\CategoriesController;
use App\Http\Controllers\Dashboard\HomeController;
use App\Http\Controllers\Dashboard\ItemsController;
use App\Http\Controllers\Dashboard\OrdersController;
use App\Http\Controllers\Dashboard\SettingsController;
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
});
