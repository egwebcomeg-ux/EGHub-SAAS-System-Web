<?php

use App\Http\Controllers\Menu\BoardController;
use App\Http\Controllers\Menu\MenuController;
use App\Http\Controllers\Menu\OrderController;
use Illuminate\Support\Facades\Route;

// egmenu.app/{tenant}/... (tenancy initialised by IdentifyTenantByPath)
Route::get('/', [MenuController::class, 'show'])->name('show');
Route::get('/t/{token}', [MenuController::class, 'table'])->where('token', '[a-f0-9]{20}')->name('table');

Route::post('/order', [OrderController::class, 'store'])->middleware('throttle:menu-orders')->name('order');
Route::get('/o/{token}', [OrderController::class, 'show'])->where('token', '[a-f0-9]{24}')->name('order.show');
Route::get('/o/{token}/status', [OrderController::class, 'status'])->where('token', '[a-f0-9]{24}')->name('order.status');

// Order-number board for a screen in the hall (POS pack)
Route::get('/board', [BoardController::class, 'show'])->name('board');
Route::get('/board/feed', [BoardController::class, 'feed'])->name('board.feed');
