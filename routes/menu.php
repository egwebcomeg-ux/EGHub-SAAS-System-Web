<?php

use App\Http\Controllers\Menu\AccountController;
use App\Http\Controllers\Menu\BoardController;
use App\Http\Controllers\Menu\BrandAssetController;
use App\Http\Controllers\Menu\CardAppController;
use App\Http\Controllers\Menu\CorporateVoucherController;
use App\Http\Controllers\Menu\FeedbackController;
use App\Http\Controllers\Menu\GiftController;
use App\Http\Controllers\Menu\ItemViewController;
use App\Http\Controllers\Menu\KioskController;
use App\Http\Controllers\Menu\LinksController;
use App\Http\Controllers\Menu\LoyaltyCardController;
use App\Http\Controllers\Menu\MenuController;
use App\Http\Controllers\Menu\OrderConfirmController;
use App\Http\Controllers\Menu\OrderController;
use App\Http\Controllers\Menu\PagerController;
use App\Http\Controllers\Menu\ProfileController;
use App\Http\Controllers\Menu\PwaController;
use App\Http\Controllers\Menu\ReceiptController;
use App\Http\Controllers\Menu\ReservationController;
use App\Http\Controllers\Menu\TableCallController;
use App\Http\Controllers\Menu\TvController;
use Illuminate\Support\Facades\Route;

// eghub.app/{tenant}/... (IdentifyTenantByPath, canonical), {tenant}.egmenu.app/... (IdentifyTenantBySubdomain,
// alias) and a custom domain (IdentifyTenantByDomain).
Route::get('/', [MenuController::class, 'show'])->name('show');
Route::get('/about', [ProfileController::class, 'show'])->name('about');
// the restaurant's SVG brand pieces (mascot intro, header pattern), served with a no-script CSP
Route::get('/brand/{file}', [BrandAssetController::class, 'show'])->where('file', '[0-9a-f-]{36}\.svg')->name('brand.asset');
// shareable link of one item: the menu with the item opened (full page in the professional display)
Route::get('/item/{item}', [MenuController::class, 'item'])->whereNumber('item')->name('item');
Route::get('/t/{token}', [MenuController::class, 'table'])->where('token', '[a-f0-9]{20}')->name('table');

Route::post('/order', [OrderController::class, 'store'])->middleware('throttle:menu-orders')->name('order');
// the cart's coupon preview before sending, priced like the order
Route::post('/coupon', [OrderController::class, 'coupon'])->middleware('throttle:20,1')->name('coupon');
Route::get('/o/{token}', [OrderController::class, 'show'])->where('token', '[a-f0-9]{24}')->name('order.show');
Route::get('/o/{token}/status', [OrderController::class, 'status'])->where('token', '[a-f0-9]{24}')->middleware('throttle:60,1')->name('order.status');
// the read-only receipt (QR on the printed receipt, WhatsApp link from the POS): signed, nothing personal
Route::get('/r/{order}/{signature}', [ReceiptController::class, 'show'])->whereNumber('order')->where('signature', '[a-f0-9]{32}')
    ->middleware('throttle:60,1')->name('receipt');
// the guest confirms, changes or cancels a cash-on-delivery order (the WhatsApp message's link when the provider has no
// buttons): signed, this restaurant's orders only
Route::get('/oc/{token}/{signature}', [OrderConfirmController::class, 'show'])->where(['token' => '[a-f0-9]{32}', 'signature' => '[a-f0-9]{32}'])
    ->middleware('throttle:60,1')->name('order.confirm');
Route::post('/oc/{token}/{signature}', [OrderConfirmController::class, 'answer'])->where(['token' => '[a-f0-9]{32}', 'signature' => '[a-f0-9]{32}'])
    ->middleware('throttle:20,1')->name('order.confirm.answer');

// The guest pager (plan feature pager): the QR on the receipt or the pager-station ticket. The phone rings while the
// page is open; the state is polled every few seconds (limiter "pager": per page and device, not per shop Wi-Fi)
Route::prefix('/p/{key}')->where(['key' => PagerController::KEY])->name('pager')->group(function () {
    Route::get('/', [PagerController::class, 'show'])->name('.show');
    Route::get('/state', [PagerController::class, 'state'])->middleware('throttle:pager')->name('.state');
    Route::post('/picked-up', [PagerController::class, 'pickedUp'])->middleware('throttle:pager-actions')->name('.picked');
    Route::post('/rate', [PagerController::class, 'rate'])->middleware('throttle:pager-actions')->name('.rate');
});

// Order-number board for a screen in the hall (POS pack)
Route::get('/board', [BoardController::class, 'show'])->name('board');
Route::get('/board/feed', [BoardController::class, 'feed'])->name('board.feed');

// TV screens: the older secret link per screen, now the same player as eghub.app/screen (no pairing: the link is the key)
Route::get('/tv/{token}', [TvController::class, 'show'])->where('token', '[a-f0-9]{20}')->name('tv');
Route::get('/tv/{token}/version', [TvController::class, 'version'])->where('token', '[a-f0-9]{20}')->name('tv.version');
Route::get('/tv/{token}/playlist', [TvController::class, 'playlist'])->where('token', '[a-f0-9]{20}')->middleware('throttle:30,1')->name('tv.playlist');
Route::get('/tv/{token}/ready', [TvController::class, 'ready'])->where('token', '[a-f0-9]{20}')->middleware('throttle:60,1')->name('tv.ready');

// Digital loyalty card
Route::get('/card', [LoyaltyCardController::class, 'join'])->name('card.join');
Route::post('/card', [LoyaltyCardController::class, 'store'])->middleware('throttle:10,1')->name('card.store');
Route::get('/card/{token}', [LoyaltyCardController::class, 'show'])->where('token', '[a-f0-9]{24}')->name('card');
// the card as its own small app on the guest's phone (App\Services\Pwa::cardManifest), its live state, a picture, wallet passes
Route::prefix('/card/{token}')->where(['token' => '[a-f0-9]{24}'])->name('card.')->group(function () {
    Route::get('/manifest.webmanifest', [CardAppController::class, 'manifest'])->middleware('throttle:30,1')->name('manifest');
    Route::get('/sw.js', [CardAppController::class, 'serviceWorker'])->middleware('throttle:30,1')->name('sw');
    Route::get('/state', [CardAppController::class, 'state'])->middleware('throttle:30,1')->name('state');
    Route::get('/image.svg', [CardAppController::class, 'image'])->middleware('throttle:10,1')->name('image');
    Route::get('/wallet/google', [CardAppController::class, 'googleWallet'])->middleware('throttle:10,1')->name('wallet.google');
    Route::get('/wallet/apple', [CardAppController::class, 'appleWallet'])->middleware('throttle:10,1')->name('wallet.apple');
});

// "حسابي": the guest's account (orders, order again, addresses, points, balance) at /me/{card token}. A new phone gets
// in with the link or with the mobile + a previous order number (App\Services\Customers\SignIn: throttled per IP and
// per mobile, one answer for every failure); a WhatsApp code would be one more action here.
Route::get('/me', [AccountController::class, 'signIn'])->middleware('throttle:30,1')->name('account.sign-in');
Route::get('/me/open', [AccountController::class, 'open'])->middleware('throttle:10,1')->name('account.open');
Route::post('/me/verify/order', [AccountController::class, 'verifyByOrder'])->middleware('throttle:10,1')->name('account.verify.order');
Route::prefix('/me/{token}')->where(['token' => '[a-f0-9]{24}'])->group(function () {
    Route::get('/', [AccountController::class, 'show'])->middleware('throttle:30,1')->name('account');
    Route::post('/sign-out', [AccountController::class, 'signOut'])->middleware('throttle:10,1')->name('account.sign-out');
    Route::post('/addresses/{key}/delete', [AccountController::class, 'deleteAddress'])->where('key', '[a-f0-9]{16}')
        ->middleware('throttle:10,1')->name('account.address.delete');
});

// Gift cards (wallet): the printed card's QR; adds the amount to the wallet of the mobile entered, once
Route::get('/gift/{code}', [GiftController::class, 'show'])->where('code', '[A-Za-z0-9]{12}')->name('gift');
Route::post('/gift/{code}', [GiftController::class, 'redeem'])->where('code', '[A-Za-z0-9]{12}')->middleware('throttle:10,1')->name('gift.redeem');

// Corporate vouchers: the printed voucher's QR shows that voucher only and lets the holder link it to their mobile
Route::get('/voucher/{code}', [CorporateVoucherController::class, 'show'])->where('code', '[A-Za-z0-9-]{10,14}')
    ->middleware('throttle:30,1')->name('voucher');
Route::post('/voucher/{code}', [CorporateVoucherController::class, 'link'])->where('code', '[A-Za-z0-9-]{10,14}')
    ->middleware('throttle:10,1')->name('voucher.link');

Route::post('/call', [TableCallController::class, 'store'])->middleware('throttle:6,1')->name('call');
Route::post('/feedback', [FeedbackController::class, 'store'])->middleware('throttle:10,1')->name('feedback');

// popup impressions / clicks (counters only, no personal data)
Route::post('/popups/{popup}/{event}', [MenuController::class, 'popupEvent'])->whereIn('event', ['view', 'click'])
    ->whereNumber('popup')->middleware('throttle:30,1')->name('popup.event');

// item sheet opened (counter for the advanced reports: viewed vs ordered)
Route::post('/i/{item}/view', [ItemViewController::class, 'store'])->whereNumber('item')->middleware('throttle:60,1')->name('item.view');
// Self-order kiosk (kiosk pack): a tablet at the counter, secret link per kiosk
Route::get('/kiosk/{token}', [KioskController::class, 'show'])->where('token', '[a-f0-9]{20}')->name('kiosk');
Route::post('/kiosk/{token}/order', [KioskController::class, 'order'])->where('token', '[a-f0-9]{20}')
    ->middleware('throttle:30,1')->name('kiosk.order');

// Table reservations (plan feature reservations): book, then follow / cancel on the secret page
Route::get('/reserve', [ReservationController::class, 'create'])->name('reserve');
Route::post('/reserve', [ReservationController::class, 'store'])->middleware('throttle:10,1')->name('reserve.store');
Route::get('/reserve/{token}', [ReservationController::class, 'show'])->where('token', '[a-f0-9]{24}')->name('reserve.show');
Route::post('/reserve/{token}/cancel', [ReservationController::class, 'cancel'])->where('token', '[a-f0-9]{24}')
    ->middleware('throttle:10,1')->name('reserve.cancel');

// The restaurant's links page (menu, order, book, WhatsApp, socials, map, hours): what a QR can open instead of the menu
Route::get('/links', [LinksController::class, 'show'])->name('links');
// The menu as an installable app (App\Services\Pwa): manifest, icons from the logo, service worker scoped to the menu, offline page
Route::get('/manifest.webmanifest', [PwaController::class, 'manifest'])->name('manifest');
Route::get('/sw.js', [PwaController::class, 'serviceWorker'])->name('sw');
Route::get('/offline', [PwaController::class, 'offline'])->name('offline');
Route::get('/icon-{size}.png', [PwaController::class, 'icon'])->whereIn('size', ['192', '512'])->name('icon');
