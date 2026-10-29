<?php

use App\Http\Controllers\Menu\AccountController;
use App\Http\Controllers\Menu\BoardController;
use App\Http\Controllers\Menu\BrandAssetController;
use App\Http\Controllers\Menu\CardAppController;
use App\Http\Controllers\Menu\CorporateVoucherController;
use App\Http\Controllers\Menu\FeedbackController;
use App\Http\Controllers\Menu\GeoController;
use App\Http\Controllers\Menu\GiftController;
use App\Http\Controllers\Menu\GuestEmailController;
use App\Http\Controllers\Menu\GuestSignInController;
use App\Http\Controllers\Menu\ItemViewController;
use App\Http\Controllers\Menu\KioskController;
use App\Http\Controllers\Menu\LinksController;
use App\Http\Controllers\Menu\LoyaltyCardController;
use App\Http\Controllers\Menu\MenuController;
use App\Http\Controllers\Menu\OrderConfirmController;
use App\Http\Controllers\Menu\OrderController;
use App\Http\Controllers\Menu\PagerController;
use App\Http\Controllers\Menu\PhoneCodeController;
use App\Http\Controllers\Menu\ProfileController;
use App\Http\Controllers\Menu\PwaController;
use App\Http\Controllers\Menu\ReceiptController;
use App\Http\Controllers\Menu\ReservationController;
use App\Http\Controllers\Menu\TableCallController;
use App\Http\Controllers\Menu\TvController;
use Illuminate\Support\Facades\Route;

// eghub.app/{tenant}/... (IdentifyTenantByPath, canonical), {tenant}.egmenu.app/... (IdentifyTenantBySubdomain,
// alias) and a custom domain (IdentifyTenantByDomain).
Route::get('/', [MenuController::class, 'show'])->middleware('page.cache:menu')->name('show');
Route::get('/about', [ProfileController::class, 'show'])->middleware('page.cache:menu')->name('about');
// the restaurant's SVG brand pieces (mascot intro, header pattern), served with a no-script CSP
Route::get('/brand/{file}', [BrandAssetController::class, 'show'])->where('file', '[0-9a-f-]{36}\.svg')->name('brand.asset');
// shareable link of one item: the menu with the item opened (full page in the professional display)
Route::get('/item/{item}', [MenuController::class, 'item'])->whereNumber('item')->middleware('page.cache:menu')->name('item');
// the menu counts a visit from a page the page cache served (MenuController::visit)
Route::get('/visit', [MenuController::class, 'visit'])->middleware('throttle:30,1')->name('visit');
Route::get('/t/{token}', [MenuController::class, 'table'])->where('token', '[a-f0-9]{20}')->name('table');

Route::post('/order', [OrderController::class, 'store'])->middleware('throttle:menu-orders')->name('order');
// the cart's coupon preview before sending, priced like the order
Route::post('/coupon', [OrderController::class, 'coupon'])->middleware('throttle:20,1')->name('coupon');
// the checkout map: the street of the guest's pin and "search a street or a place" (Menu\GeoController, never cached)
Route::get('/geo/reverse', [GeoController::class, 'reverse'])->middleware('throttle:geo-reverse')->name('geo.reverse');
Route::get('/geo/search', [GeoController::class, 'search'])->middleware('throttle:geo-search')->name('geo.search');
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
Route::get('/tv/{token}/live', [TvController::class, 'live'])->where('token', '[a-f0-9]{20}')->middleware('throttle:240,1')->name('tv.live');
Route::get('/tv/{token}/ready', [TvController::class, 'ready'])->where('token', '[a-f0-9]{20}')->middleware('throttle:60,1')->name('tv.ready');

// Digital loyalty card
Route::get('/card', [LoyaltyCardController::class, 'join'])->name('card.join');
Route::post('/card', [LoyaltyCardController::class, 'store'])->middleware('throttle:10,1')->name('card.store');
Route::get('/card/{token}', [LoyaltyCardController::class, 'show'])->where('token', '[a-f0-9]{24}')->name('card');
// what the card's QR opens: the code for the cashier, nothing of the guest's (the card link above is their key)
Route::get('/c/{code}', [LoyaltyCardController::class, 'scan'])->where('code', '[a-f0-9]{20}')->middleware('throttle:30,1')->name('card.scan');
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
// in with Google, with a WhatsApp message to the EgHub number, with the mobile + a previous order number
// (App\Services\Customers\SignIn: throttled per IP and per mobile, one answer for every failure) or with the link.
// None of these pages is ever page-cached (no page.cache middleware) and all answer with AccountController::HEADERS.
Route::get('/me', [AccountController::class, 'signIn'])->middleware('throttle:30,1')->name('account.sign-in');
Route::get('/me/open', [AccountController::class, 'open'])->middleware('throttle:10,1')->name('account.open');
Route::post('/me/verify/order', [AccountController::class, 'verifyByOrder'])->middleware('throttle:10,1')->name('account.verify.order');
// «الدخول بجوجل»: out to eghub.app/auth/google/start and back here with a one-time ticket (App\Services\Customers\SignIn\GuestOAuth)
Route::get('/me/google', [GuestSignInController::class, 'google'])->middleware('throttle:20,1')->name('account.google');
Route::get('/me/google/done', [GuestSignInController::class, 'googleDone'])->middleware('throttle:20,1')->name('account.google.done');
// «ادخل بواتساب»: a code the guest sends to the EgHub number, confirmed by its webhook, polled here (WhatsAppSignIn)
Route::post('/me/whatsapp', [GuestSignInController::class, 'whatsappStart'])->middleware('throttle:10,1')->name('account.whatsapp');
Route::get('/me/whatsapp/status', [GuestSignInController::class, 'whatsappStatus'])->middleware('throttle:60,1')->name('account.whatsapp.status');
// the mobile, asked once after a Google sign-in (for orders; not verified)
Route::get('/me/phone', [GuestSignInController::class, 'phone'])->middleware('throttle:30,1')->name('account.phone');
Route::post('/me/phone', [GuestSignInController::class, 'savePhone'])->middleware('throttle:10,1')->name('account.phone.save');
// the returning guest's card on the menu, loaded by the page (never in the cached HTML): AccountController::summary
Route::get('/me/summary', [AccountController::class, 'summary'])->middleware('throttle:60,1')->name('account.summary');
// «ادخل برقم موبايلك»: a 6-digit code by the restaurant's own WhatsApp number or SMS, or another way (PhoneCodeSignIn)
Route::post('/me/phone-code', [PhoneCodeController::class, 'start'])->middleware('throttle:10,1')->name('account.phone-code');
Route::post('/me/phone-code/verify', [PhoneCodeController::class, 'verify'])->middleware('throttle:15,1')->name('account.phone-code.verify');
// «ادخل بالإيميل»: a one-time link + a 6-digit code (App\Services\Customers\SignIn\EmailSignIn); the link opens a page
// with one button so a mail scanner does not use it up
Route::post('/me/email', [GuestEmailController::class, 'start'])->middleware('throttle:10,1')->name('account.email');
Route::post('/me/email/code', [GuestEmailController::class, 'code'])->middleware('throttle:10,1')->name('account.email.code');
Route::get('/me/email/{token}', [GuestEmailController::class, 'link'])->where('token', '[a-f0-9]{64}')->middleware('throttle:20,1')->name('account.email.link');
Route::post('/me/email/{token}', [GuestEmailController::class, 'redeem'])->where('token', '[a-f0-9]{64}')->middleware('throttle:10,1')->name('account.email.redeem');
// «أكّد إيميلك» after an order with an email (EmailConfirmation): confirms only where the guest is proven
Route::get('/me/email/confirm/{token}', [GuestEmailController::class, 'confirmPage'])->where('token', '[a-f0-9]{64}')->middleware('throttle:20,1')->name('account.email.confirm');
Route::post('/me/email/confirm/{token}', [GuestEmailController::class, 'confirm'])->where('token', '[a-f0-9]{64}')->middleware('throttle:10,1')->name('account.email.confirm.save');
Route::post('/me/email/confirm-again', [GuestEmailController::class, 'confirmAgain'])->middleware('throttle:6,1')->name('account.email.confirm.again');
// email + password (PasswordSignIn): «اعمل حساب», «ادخل», «نسيت الباسورد؟» and the reset link
Route::post('/me/register', [GuestEmailController::class, 'register'])->middleware('throttle:10,1')->name('account.register');
Route::post('/me/login', [GuestEmailController::class, 'login'])->middleware('throttle:20,1')->name('account.login');
Route::post('/me/forgot', [GuestEmailController::class, 'forgot'])->middleware('throttle:6,1')->name('account.forgot');
Route::get('/me/reset/{token}', [GuestEmailController::class, 'resetForm'])->where('token', '[a-f0-9]{64}')->middleware('throttle:20,1')->name('account.reset');
Route::post('/me/reset/{token}', [GuestEmailController::class, 'reset'])->where('token', '[a-f0-9]{64}')->middleware('throttle:10,1')->name('account.reset.save');
// «ادعي عملاءك»: the personal claim link of an existing guest (App\Services\Customers\ClaimLinks)
Route::get('/me/claim/{token}', [GuestEmailController::class, 'claim'])->where('token', '[a-f0-9]{64}')->middleware('throttle:20,1')->name('account.claim');
Route::post('/me/claim/{token}', [GuestEmailController::class, 'claimPassword'])->where('token', '[a-f0-9]{64}')->middleware('throttle:10,1')->name('account.claim.save');
Route::post('/me/claim/{token}/google', [GuestEmailController::class, 'claimGoogle'])->where('token', '[a-f0-9]{64}')->middleware('throttle:10,1')->name('account.claim.google');
// a new account's mobile that another guest here has: a sign-in link to that guest's email joins the two
Route::post('/me/prove-phone/email', [GuestEmailController::class, 'proveByOldEmail'])->middleware('throttle:6,1')->name('account.prove.email');
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

Route::post('/call', [TableCallController::class, 'store'])->middleware('throttle:table-calls')->name('call');
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
Route::get('/links', [LinksController::class, 'show'])->middleware('page.cache:menu')->name('links');
// The menu as an installable app (App\Services\Pwa): manifest, icons from the logo, service worker scoped to the menu, offline page
Route::get('/manifest.webmanifest', [PwaController::class, 'manifest'])->name('manifest');
Route::get('/sw.js', [PwaController::class, 'serviceWorker'])->name('sw');
Route::get('/offline', [PwaController::class, 'offline'])->name('offline');
Route::get('/icon-{size}.png', [PwaController::class, 'icon'])->whereIn('size', ['192', '512'])->name('icon');
