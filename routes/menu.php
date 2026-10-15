<?php

use App\Http\Controllers\Menu\BoardController;
use App\Http\Controllers\Menu\FeedbackController;
use App\Http\Controllers\Menu\ItemViewController;
use App\Http\Controllers\Menu\GiftController;
use App\Http\Controllers\Menu\LoyaltyCardController;
use App\Http\Controllers\Menu\MenuController;
use App\Http\Controllers\Menu\OrderController;
use App\Http\Controllers\Menu\ProfileController;
use App\Http\Controllers\Menu\TableCallController;
use App\Http\Controllers\Menu\TvController;
use Illuminate\Support\Facades\Route;

// egmenu.app/{tenant}/... (IdentifyTenantByPath, canonical), {tenant}.egmenu.app/... (IdentifyTenantBySubdomain,
// alias) and a custom domain (IdentifyTenantByDomain).
Route::get('/', [MenuController::class, 'show'])->name('show');
Route::get('/about', [ProfileController::class, 'show'])->name('about');
Route::get('/t/{token}', [MenuController::class, 'table'])->where('token', '[a-f0-9]{20}')->name('table');

Route::post('/order', [OrderController::class, 'store'])->middleware('throttle:menu-orders')->name('order');
Route::get('/o/{token}', [OrderController::class, 'show'])->where('token', '[a-f0-9]{24}')->name('order.show');
Route::get('/o/{token}/status', [OrderController::class, 'status'])->where('token', '[a-f0-9]{24}')->name('order.status');

// Order-number board for a screen in the hall (POS pack)
Route::get('/board', [BoardController::class, 'show'])->name('board');
Route::get('/board/feed', [BoardController::class, 'feed'])->name('board.feed');

// Menu boards on TVs (Business): secret link per screen
Route::get('/tv/{token}', [TvController::class, 'show'])->where('token', '[a-f0-9]{20}')->name('tv');
Route::get('/tv/{token}/version', [TvController::class, 'version'])->where('token', '[a-f0-9]{20}')->name('tv.version');

// Digital loyalty card
Route::get('/card', [LoyaltyCardController::class, 'join'])->name('card.join');
Route::post('/card', [LoyaltyCardController::class, 'store'])->middleware('throttle:10,1')->name('card.store');
Route::get('/card/{token}', [LoyaltyCardController::class, 'show'])->where('token', '[a-f0-9]{24}')->name('card');

// Gift cards (wallet): the printed card's QR; adds the amount to the wallet of the mobile entered, once
Route::get('/gift/{code}', [GiftController::class, 'show'])->where('code', '[A-Za-z0-9]{12}')->name('gift');
Route::post('/gift/{code}', [GiftController::class, 'redeem'])->where('code', '[A-Za-z0-9]{12}')->middleware('throttle:10,1')->name('gift.redeem');

Route::post('/call', [TableCallController::class, 'store'])->middleware('throttle:6,1')->name('call');
Route::post('/feedback', [FeedbackController::class, 'store'])->middleware('throttle:10,1')->name('feedback');

// popup impressions / clicks (counters only, no personal data)
Route::post('/popups/{popup}/{event}', [MenuController::class, 'popupEvent'])->whereIn('event', ['view', 'click'])
    ->whereNumber('popup')->middleware('throttle:30,1')->name('popup.event');

// item sheet opened (counter for the advanced reports: viewed vs ordered)
Route::post('/i/{item}/view', [ItemViewController::class, 'store'])->whereNumber('item')->middleware('throttle:60,1')->name('item.view');
