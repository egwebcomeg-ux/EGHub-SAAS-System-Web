<?php

use App\Http\Controllers\Menu\BoardController;
use App\Http\Controllers\Menu\FeedbackController;
use App\Http\Controllers\Menu\LoyaltyCardController;
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

// Digital loyalty card
Route::get('/card', [LoyaltyCardController::class, 'join'])->name('card.join');
Route::post('/card', [LoyaltyCardController::class, 'store'])->middleware('throttle:10,1')->name('card.store');
Route::get('/card/{token}', [LoyaltyCardController::class, 'show'])->where('token', '[a-f0-9]{24}')->name('card');

Route::post('/feedback', [FeedbackController::class, 'store'])->middleware('throttle:10,1')->name('feedback');

// popup impressions / clicks (counters only, no personal data)
Route::post('/popups/{popup}/{event}', [MenuController::class, 'popupEvent'])->whereIn('event', ['view', 'click'])
    ->whereNumber('popup')->middleware('throttle:30,1')->name('popup.event');
