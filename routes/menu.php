<?php

use App\Http\Controllers\Menu\MenuController;
use App\Http\Controllers\Menu\OrderController;
use Illuminate\Support\Facades\Route;

// egmenu.app/{tenant}/... (tenancy initialised by IdentifyTenantByPath)
Route::get('/', [MenuController::class, 'show'])->name('show');
Route::get('/t/{token}', [MenuController::class, 'table'])->where('token', '[a-f0-9]{20}')->name('table');

Route::post('/order', [OrderController::class, 'store'])->middleware('throttle:menu-orders')->name('order');
Route::get('/o/{token}', [OrderController::class, 'show'])->where('token', '[a-f0-9]{24}')->name('order.show');
Route::get('/o/{token}/status', [OrderController::class, 'status'])->where('token', '[a-f0-9]{24}')->name('order.status');
