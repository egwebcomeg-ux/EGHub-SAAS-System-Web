<?php

namespace App\Http\Controllers\Menu;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Customer;
use App\Services\Loyalty\LoyaltyService;
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
        if (!$phone) {
            return back()->withInput()->withErrors(['phone' => __('Enter a valid Egyptian mobile number.')]);
        }
        // A number that already holds a prepaid balance only opens from its own link (receipt QR, or this phone's
        // cookie): typing someone's mobile must never give access to their money.
        $existing = Customer::query()->where('phone', $phone)->first();
        if ($existing && $existing->credit_balance > 0 && $request->cookie($this->cookie()) !== $existing->card_token) {
            return back()->withInput()->withErrors(['phone' => __('This number already has a card with a balance. Open it from the QR on your receipt, or ask the cashier.')]);
        }
        $card = $loyalty->enroll($phone, $data['name'] ?? '');
        if ($request->boolean('consent')) {
            $card->customer->update(['marketing_opt_in' => true]);
        }
        return redirect()->route('menu.card', $card->customer->card_token)
            ->withCookie(cookie()->forever($this->cookie(), $card->customer->card_token));
    }

    /** The card link is the guest's key: opening it remembers the card on this phone (menu "My card", wallet). */
    public function show(string $token, LoyaltyService $loyalty): Response
    {
        $customer = Customer::query()->where('card_token', $token)->firstOrFail();
        $program = $loyalty->program();
        $wallet = WalletService::enabled() ? [
            'balance' => round((float) $customer->credit_balance, 2),
            'history' => $customer->walletTransactions()->latest('id')->limit(10)->get(),
        ] : null;
        abort_unless($program || $wallet, 404);
        $shared = [
            'customer' => $customer, 'settings' => Settings::all(), 'wallet' => $wallet,
            'qr' => Qr::svg(route('menu.card', $token), 220, '#111111'),
            'branches' => Branch::query()->where('is_active', true)->orderBy('sort_order')->get(),
        ];
        $view = $program
            ? view('menu.card', $shared + [
                'program' => $program, 'milestones' => $loyalty->milestones($program),
                'card' => $loyalty->card($program, $customer)->load(['availableRewards', 'transactions' => fn ($q) => $q->whereIn('type', ['stamp', 'points', 'redeem'])->limit(15)]),
            ])
            : view('menu.wallet', $shared);

        return response($view)->withCookie(cookie()->forever($this->cookie(), $token));
    }

    private function cookie(): string
    {
        return 'card_' . tenant()->getTenantKey();
    }
}
