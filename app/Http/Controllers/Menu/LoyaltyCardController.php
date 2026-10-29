<?php

namespace App\Http\Controllers\Menu;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Coupon;
use App\Models\Customer;
use App\Services\Corporate\CorporateVouchers;
use App\Services\Customers\GuestAccount;
use App\Services\Entitlements;
use App\Services\Loyalty\CardState;
use App\Services\Loyalty\LoyaltyService;
use App\Services\Loyalty\Membership;
use App\Services\Loyalty\Packages;
use App\Services\Loyalty\WalletPasses;
use App\Services\Qr;
use App\Services\Settings;
use App\Services\Wallet\WalletService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * The guest's digital loyalty card: {tenant}.egmenu.app/card/{token}. Joining only needs a mobile number; the
 * card shows stamps and rewards but can never add stamps itself (staff or a counted order do that).
 * The browser remembers the card per restaurant so the menu can link to "My card".
 */
class LoyaltyCardController extends Controller
{
    public function join(Request $request, LoyaltyService $loyalty): View|RedirectResponse
    {
        $program = $loyalty->program() ?? abort(404);
        if ($token = $request->cookie($this->cookie())) {
            if (Customer::query()->where('card_token', $token)->exists()) {
                return redirect()->route('menu.card', $token);
            }
        }

        return view('menu.card-join', ['program' => $program, 'settings' => Settings::all(), 'milestones' => $loyalty->milestones($program)]);
    }

    public function store(Request $request, LoyaltyService $loyalty): RedirectResponse
    {
        abort_unless($loyalty->program(), 404);
        $data = $request->validate(['phone' => 'required|string|max:20', 'name' => 'nullable|string|max:100', 'consent' => 'nullable|boolean']);
        $phone = normalize_phone($data['phone']);
        if (! $phone) {
            return back()->withInput()->withErrors(['phone' => __('Enter a valid Egyptian mobile number.')]);
        }
        // A number that ever used the prepaid wallet only opens from its own link (receipt QR, or this phone's
        // cookie): typing someone's mobile must never give access to their money nor show their wallet history,
        // and the answer does not say whether there is money on it.
        $existing = Customer::query()->where('phone', $phone)->first();
        if ($existing && ($existing->credit_balance != 0 || $existing->walletTransactions()->exists())
            && $request->cookie($this->cookie()) !== $existing->card_token) {
            return back()->withInput()->withErrors(['phone' => __('This number already has a card. Open it from the QR on your receipt, or ask the cashier.')]);
        }
        // The card link is also the guest's account key (/me/{token}: orders, addresses): a mobile that already
        // ordered here opens it from its own link or with the mobile + a delivered order's number, never by typing it.
        if ($existing && $existing->orders()->exists() && $request->cookie($this->cookie()) !== $existing->card_token) {
            return back()->withInput()->withErrors(['phone' => __('This number already has an account here. Open «My account» on the menu with your mobile and a previous order number.')]);
        }
        $card = $loyalty->enroll($phone, $data['name'] ?? '');
        if ($request->boolean('consent')) {
            $card->customer->update(['marketing_opt_in' => true]);
        }

        return redirect()->route('menu.card', $card->customer->card_token)
            ->withCookie(cookie()->forever($this->cookie(), $card->customer->card_token));
    }

    /** The card link is the guest's key: opening it remembers the card on this phone (menu "My card", wallet). */
    public function show(string $token, LoyaltyService $loyalty, CardState $cards, WalletPasses $walletPasses): Response
    {
        $customer = Customer::query()->where('card_token', $token)->firstOrFail();
        $program = $loyalty->program();
        $wallet = WalletService::enabled() ? [
            'balance' => round((float) $customer->credit_balance, 2),
            'history' => $customer->walletTransactions()->latest('id')->limit(10)->get(),
        ] : null;
        // advanced cards: the membership tier and the prepaid packages still good to use
        $membership = app(Membership::class);
        $packages = Packages::enabled() ? app(Packages::class)->activeFor($customer) : collect();
        // the discount vouchers this guest holds (only theirs: customer_id), to use with their mobile when ordering
        $vouchers = Entitlements::current()->allows('loyalty_stamps') ? Coupon::heldBy($customer) : collect();
        // corporate vouchers linked to this guest's mobile ("فاوتشراتي")
        $corporate = CorporateVouchers::enabled() ? app(CorporateVouchers::class)->heldBy($customer) : collect();
        abort_unless($program || $wallet || $packages->isNotEmpty() || $vouchers->isNotEmpty() || $corporate->isNotEmpty() || Membership::enabled(), 404);
        $shared = [
            'customer' => $customer, 'settings' => Settings::all(), 'wallet' => $wallet, 'vouchers' => $vouchers, 'corporate' => $corporate,
            'tier' => $membership->tierFor($customer), 'nextTier' => $membership->nextFor($customer), 'packages' => $packages,
            'qr' => Qr::svg(GuestAccount::scanUrl($customer), 220, '#111111'),
            'branches' => Branch::query()->where('is_active', true)->orderBy('sort_order')->get(),
            // the card as an app on the phone: live state version, wallet buttons only when the platform has the keys
            'cardState' => $cards->for($customer) ?? abort(404), 'walletPasses' => $walletPasses,
        ];
        $view = $program
            ? view('menu.card', $shared + [
                'program' => $program, 'milestones' => $loyalty->milestones($program),
                'card' => $loyalty->card($program, $customer)->load(['availableRewards', 'transactions' => fn ($q) => $q->whereIn('type', ['stamp', 'points', 'redeem'])->limit(15)]),
            ])
            : view('menu.wallet', $shared);

        return response($view, 200, CardAppController::PRIVATE_HEADERS)->withCookie(cookie()->forever($this->cookie(), $token));
    }

    /**
     * The card's QR opened in a browser (the cashier's phone, or anyone who photographed it): the restaurant's name and
     * the code to type at the counter. Nothing of the guest, no cookie: the guest's own page is their card link.
     */
    public function scan(string $code): Response
    {
        abort_unless(Customer::query()->where('scan_code', $code)->exists(), 404);

        return response(view('menu.card-scan', ['code' => $code, 'settings' => Settings::all()]), 200, CardAppController::PRIVATE_HEADERS);
    }

    private function cookie(): string
    {
        return 'card_'.tenant()->getTenantKey();
    }
}
