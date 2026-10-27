<?php

namespace App\Http\Controllers\Menu;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Customers\GuestAccount;
use App\Services\Customers\SignIn\OrderNumberSignIn;
use App\Services\Customers\SignIn\SignInGuard;
use App\Services\Loyalty\CardState;
use App\Services\Menu\MenuCatalog;
use App\Services\Settings;
use App\Services\Wallet\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * "حسابي", the guest's account at one restaurant: /me/{token} (the card_token, the guest's key) with their last
 * orders and "order again", saved delivery addresses, points and balance. A new phone gets in with the link itself
 * (pasted or opened) or with the mobile + a previous order number; each way into the account is its own action
 * (a WhatsApp code would be another one). The page and its actions only ever find the guest by token inside this
 * restaurant; nothing is cached by the browser or a proxy, nothing is indexed and no referrer leaves the page.
 */
class AccountController extends Controller
{
    /** Everything under /me: never kept by a shared cache, never indexed, the token never sent on as a referrer. */
    public const HEADERS = CardAppController::PRIVATE_HEADERS + ['Referrer-Policy' => 'no-referrer'];

    /** The two ways in for a phone that does not know the guest yet. */
    public function signIn(Request $request): Response|RedirectResponse
    {
        if ($customer = GuestAccount::fromRequest($request)) {
            return redirect()->to(GuestAccount::url($customer))->withHeaders(self::HEADERS);
        }
        $request->attributes->set('noindex', true);

        return response()->view('menu.account-sign-in', ['settings' => Settings::all(),
            'tab' => in_array($request->query('tab'), ['login', 'register'], true) ? $request->query('tab') : null], 200, self::HEADERS);
    }

    /** "عندي لينك حسابي": the pasted link (or just its key) opens the account of this restaurant only. */
    public function open(Request $request): RedirectResponse
    {
        $data = $request->validate(['link' => 'required|string|max:500']);
        $customer = preg_match('/(?:^|\/me\/|\/card\/)('.GuestAccount::TOKEN_PATTERN.')(?:[\/?#]|$)/', trim($data['link']), $m) === 1
            ? GuestAccount::byToken($m[1]) : null;
        if (! $customer) {
            return redirect()->route('menu.account.sign-in')->withHeaders(self::HEADERS)
                ->withErrors(['link' => __('This link does not open an account here. Copy the whole link and try again.')], 'link');
        }

        return redirect()->to(GuestAccount::url($customer))->withHeaders(self::HEADERS);
    }

    /** Mobile + the number of a previous delivered order. Every failure gets the same answer. */
    public function verifyByOrder(Request $request, SignInGuard $guard, OrderNumberSignIn $method): RedirectResponse
    {
        $data = $request->validate(['phone' => 'required|string|max:20', 'number' => 'required|string|max:12']);
        [$outcome, $customer] = $guard->attempt($method, $data['phone'], ['number' => $data['number']], (string) $request->ip());
        if ($outcome !== 'ok' || ! $customer) {
            return redirect()->route('menu.account.sign-in')->withHeaders(self::HEADERS)->withInput($request->only('phone'))
                ->withErrors(['phone' => $outcome === 'blocked'
                    ? __('Too many tries. Please try again in an hour.')
                    : __('We could not open an account with this mobile and order number. Check both, or ask the restaurant for your account link.')], 'verify');
        }
        GuestAccount::adoptGoogleGuest(GuestAccount::fromRequest($request), $customer);

        return redirect()->to(GuestAccount::url($customer))->withHeaders(self::HEADERS)
            ->withCookie(GuestAccount::rememberCookie(GuestAccount::token($customer)));
    }

    /** The account page; opening it remembers the guest on this phone (menu "حسابي", name and mobile filled in). */
    public function show(Request $request, string $token, CardState $cards): Response
    {
        $customer = $this->customer($token);
        $request->attributes->set('noindex', true);
        $card = $cards->for($customer);

        return response()->view('menu.account', [
            'customer' => $customer,
            'settings' => Settings::all(),
            'orders' => GuestAccount::orders($customer),
            'addresses' => GuestAccount::addresses($customer),
            'card' => $card,
            'wallet' => WalletService::enabled() ? round((float) $customer->credit_balance, 2) : null,
            'shareUrl' => GuestAccount::publicUrl($customer),
        ], 200, self::HEADERS)->withCookie(GuestAccount::rememberCookie($customer->card_token));
    }

    /**
     * The returning guest on the menu (resources/js/menu.js asks once per page): their first name, the last order with
     * what can be ordered again on this branch now, the 4 dishes they order most, points and balance. Loaded by the
     * page itself so nothing personal is ever in the cached menu; answered from the guest cookie only, never stored.
     */
    public function summary(Request $request, CardState $cards): JsonResponse
    {
        $customer = GuestAccount::fromRequest($request);
        if (! $customer) {
            return response()->json(['guest' => null], 200, self::HEADERS);
        }
        $branch = Branch::query()->where('is_active', true)->find((int) $request->query('branch', 0)) ?? MenuCatalog::defaultBranch();
        $available = MenuCatalog::forBranch($branch)->categories()->flatMap(fn ($c) => $c->items)->keyBy('id');
        $last = Order::query()->where('customer_id', $customer->id)->where('status', '!=', 'cancelled')->with('items')->latest('id')->first();
        $again = $last ? MenuController::reorderLines($last, $available) : null;
        $favourites = OrderItem::query()->whereIn('order_id', Order::query()->where('customer_id', $customer->id)->where('status', '!=', 'cancelled')->select('id'))
            ->whereNotNull('menu_item_id')->selectRaw('menu_item_id, sum(quantity) as qty')->groupBy('menu_item_id')->orderByDesc('qty')
            ->limit(20)->pluck('menu_item_id')->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $available->has($id) && ! $available->get($id)->is_sold_out)->take(4)->values();
        $card = $cards->for($customer);
        $first = trim(explode(' ', trim((string) $customer->name))[0] ?? '');

        return response()->json(['guest' => [
            'name' => $first,
            'account' => GuestAccount::url($customer),
            'last' => $last ? [
                'number' => (int) $last->number,
                'date' => local_time($last->created_at, 'Y/m/d'),
                'summary' => GuestAccount::itemsSummary($last, 3),
                'lines' => $again['lines'],
                'skipped' => $again['skipped'],
                'skippedNames' => $again['skippedNames'],
            ] : null,
            'favourites' => $favourites,
            'points' => $card && $card['type'] === 'points' ? (int) $card['points'] : null,
            'stamps' => $card && $card['type'] === 'stamps' ? ['have' => (int) $card['stamps'], 'of' => (int) $card['required']] : null,
            'wallet' => WalletService::enabled() && (float) $customer->credit_balance > 0 ? round((float) $customer->credit_balance, 2) : null,
            'addresses' => array_map(fn (array $a) => ['zone_id' => $a['zone_id'], 'text' => $a['text'], 'label' => $a['label'], 'zone' => $a['zone'],
                'lat' => $a['lat'], 'lng' => $a['lng'], 'parts' => (object) $a['parts']], GuestAccount::addresses($customer)),
            'email' => $customer->email === null && $customer->pending_email === null,
        ]], 200, self::HEADERS);
    }

    /** "ده مش أنا / سجّل خروج": this phone forgets the guest (the menu also clears the saved name and mobile). */
    public function signOut(string $token): RedirectResponse
    {
        return redirect()->route('menu.show', ['signed_out' => 1])->withHeaders(self::HEADERS)
            ->withCookie(GuestAccount::forgetCookie());
    }

    public function deleteAddress(string $token, string $key): RedirectResponse
    {
        $customer = $this->customer($token);
        $deleted = GuestAccount::forgetAddress($customer, $key);

        return redirect()->to(GuestAccount::url($customer).'#addresses')->withHeaders(self::HEADERS)
            ->with('ok', $deleted ? __('Address removed.') : __('This address was already removed.'));
    }

    /** Only this restaurant's guests (tenant scope): another restaurant's or an old token is a 404. */
    private function customer(string $token): Customer
    {
        return GuestAccount::byToken($token) ?? abort(404);
    }
}
