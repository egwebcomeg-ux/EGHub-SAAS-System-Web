<?php

namespace App\Http\Controllers\Menu;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Services\Customers\GuestAccount;
use App\Services\Customers\SignIn\GuestIdentity;
use App\Services\Customers\SignIn\GuestOAuth;
use App\Services\Customers\SignIn\WhatsAppSignIn;
use App\Services\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The restaurant-host half of the newer ways into «حسابي»: «الدخول بجوجل» (out to eghub.app and back with a one-time
 * ticket, App\Services\Customers\SignIn\GuestOAuth), «ادخل بواتساب» (a reverse code confirmed by the platform's
 * webhook, App\Services\Customers\SignIn\WhatsAppSignIn), and the one question a Google guest gets afterwards: their
 * mobile, for orders (not verified, never used to open another guest's account). Everything here is private: never
 * cached, never indexed, nothing personal in a page another visitor could get.
 */
class GuestSignInController extends Controller
{
    /** Session key (+ restaurant) of a guest a claim link opened, to link to the Google account that comes back. */
    public const LINK_GOOGLE_KEY = 'guest.link_google.';

    /** Google's answers on the way back, as the guest reads them. */
    private const GOOGLE_ERRORS = [
        'cancelled' => 'You cancelled the Google sign-in. Try again whenever you like, or use another way below.',
        'expired' => 'The sign-in took too long or was opened in another tab. Try again.',
        'failed' => 'We could not reach Google right now. Try again in a minute, or use another way below.',
    ];

    /** «الدخول بجوجل»: remembers a nonce on this host, then off to the main domain with the signed state. */
    public function google(Request $request): RedirectResponse
    {
        abort_unless(GuestOAuth::enabled('google'), 404);
        $nonce = random_token(32);
        $url = GuestOAuth::startUrl('google', tenant(), route('menu.account.google.done'), $nonce);

        return redirect()->away($url)->withHeaders(AccountController::HEADERS)->withCookie(cookie(
            GuestOAuth::nonceCookie((string) tenant()->getTenantKey()), $nonce, GuestOAuth::STATE_MINUTES, null, null, $this->secure(), true, false, 'lax'
        ));
    }

    /** Back from Google: the ticket becomes this phone's remembered guest (once), then the menu or the mobile question. */
    public function googleDone(Request $request): RedirectResponse
    {
        $forgetNonce = cookie(GuestOAuth::nonceCookie((string) tenant()->getTenantKey()), '', -2628000, null, null, $this->secure(), true, false, 'lax');
        $error = (string) $request->query('error', '');
        $customer = $error === '' ? GuestOAuth::redeemTicket((string) $request->query('ticket', ''), $request->cookie(GuestOAuth::nonceCookie((string) tenant()->getTenantKey()))) : null;
        if (! $customer) {
            $message = self::GOOGLE_ERRORS[$error] ?? self::GOOGLE_ERRORS['expired'];

            return redirect()->route('menu.account.sign-in')->withHeaders(AccountController::HEADERS)->withCookie($forgetNonce)
                ->withErrors(['google' => __($message)], 'google');
        }
        // a claim link sent this phone to Google: the Google account joins the guest the link opened
        $linkTo = $request->session()->pull(self::LINK_GOOGLE_KEY.tenant()->getTenantKey());
        if ($linkTo && ($claimed = Customer::query()->find((int) $linkTo)) && GuestIdentity::adopt($customer, $claimed)) {
            $customer = $claimed->refresh();
        }
        $target = $customer->phone === '' ? route('menu.account.phone') : route('menu.show');

        return redirect()->to($target)->withHeaders(AccountController::HEADERS)->withCookie($forgetNonce)
            ->withCookie(GuestAccount::rememberCookie(GuestAccount::token($customer)));
    }

    /** «ادخل بواتساب»: a fresh code for this browser and the wa.me link with the message ready. */
    public function whatsappStart(Request $request, WhatsAppSignIn $signIn): JsonResponse
    {
        abort_unless(WhatsAppSignIn::available(), 404);
        $pending = $signIn->start($request);
        if (! $pending) {
            return response()->json(['message' => __('Too many tries. Please try again in a few minutes.')], 429, AccountController::HEADERS);
        }

        return response()->json([
            'url' => $pending['url'], 'code' => $pending['code'], 'message' => $pending['message'],
            'expires_in' => max(0, $pending['expires_at'] - now()->getTimestamp()),
        ], 200, AccountController::HEADERS)->withCookie(WhatsAppSignIn::cookie($pending['secret']));
    }

    /** The page asks every few seconds whether the message arrived; on 'ok' this phone now remembers the guest. */
    public function whatsappStatus(Request $request, WhatsAppSignIn $signIn): JsonResponse
    {
        abort_unless(WhatsAppSignIn::available(), 404);
        [$status, $customer] = $signIn->status($request);
        if ($status !== 'ok' || ! $customer) {
            return response()->json(['status' => $status], 200, AccountController::HEADERS);
        }
        GuestAccount::adoptGoogleGuest(GuestAccount::fromRequest($request), $customer);

        return response()->json(['status' => 'ok', 'redirect' => GuestAccount::url($customer)], 200, AccountController::HEADERS)
            ->withCookie(GuestAccount::rememberCookie(GuestAccount::token($customer)))
            ->withCookie(GuestAccount::phoneProofCookie($customer))
            ->withCookie(WhatsAppSignIn::forgetCookie());
    }

    /** The mobile question after a Google sign-in (asked once; «بعدين» skips it). */
    public function phone(Request $request): Response|RedirectResponse
    {
        $customer = GuestAccount::fromRequest($request);
        if (! $customer || $customer->phone !== '') {
            return redirect()->route('menu.show')->withHeaders(AccountController::HEADERS);
        }
        $request->attributes->set('noindex', true);

        return response()->view('menu.account-phone', [
            'customer' => $customer, 'settings' => Settings::all(), 'whatsapp' => WhatsAppSignIn::available(),
        ], 200, AccountController::HEADERS);
    }

    /**
     * Saves the mobile on this Google guest. A mobile another guest here already has is not taken over: that
     * account opens only with a proof (WhatsApp, or a previous order number), which then links Google to it.
     */
    public function savePhone(Request $request): RedirectResponse
    {
        $customer = GuestAccount::fromRequest($request);
        if (! $customer || $customer->phone !== '') {
            return redirect()->route('menu.show')->withHeaders(AccountController::HEADERS);
        }
        $data = $request->validate(['phone' => 'required|string|max:20']);
        $phone = normalize_phone($data['phone']);
        if ($phone === null) {
            return redirect()->route('menu.account.phone')->withHeaders(AccountController::HEADERS)->withInput()
                ->withErrors(['phone' => __('Enter an Egyptian mobile number, e.g. 01012345678.')]);
        }
        if (Customer::query()->where('phone', $phone)->whereKeyNot($customer->id)->exists()) {
            return redirect()->route('menu.account.phone')->withHeaders(AccountController::HEADERS)->withInput()
                ->withErrors(['phone' => __('This mobile already has an account here. Confirm it is yours with WhatsApp or a previous order number below, and we will link it to your Google account.')]);
        }
        $customer->forceFill(['phone' => $phone])->save();

        return redirect()->route('menu.show')->withHeaders(AccountController::HEADERS);
    }

    private function secure(): bool
    {
        return (bool) (config('session.secure') ?? (request()->isSecure() || app()->isProduction()));
    }
}
