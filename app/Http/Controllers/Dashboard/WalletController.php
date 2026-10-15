<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\GiftCard;
use App\Models\WalletTransaction;
use App\Services\Audit;
use App\Services\Orders\OrderException;
use App\Services\Orders\ShiftService;
use App\Services\Qr;
use App\Services\Settings;
use App\Services\Wallet\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * "Wallet & gift cards": balances, the ledger and gift cards (dashboard page), and the POS actions — look a guest
 * up by mobile, top up a balance, sell a gift card and print it on the 80mm printer.
 */
class WalletController extends Controller
{
    public function index(Request $request): View
    {
        $tab = in_array($request->query('tab'), ['balances', 'ledger', 'gifts'], true) ? $request->query('tab') : 'balances';
        $q = mb_substr(trim((string) $request->query('q', '')), 0, 60);

        return view('dashboard.wallet.index', [
            'tab' => $tab,
            'q' => $q,
            'totals' => [
                'liability' => round((float) Customer::query()->sum('credit_balance'), 2),
                'customers' => Customer::query()->where('credit_balance', '>', 0)->count(),
                'gifts' => round((float) GiftCard::query()->where('status', 'active')->where(fn ($w) => $w->whereNull('expires_at')->orWhere('expires_at', '>', now()))->sum('balance'), 2),
                'month_topups' => round((float) WalletTransaction::query()->where('kind', 'topup')->where('created_at', '>=', now()->startOfMonth())->sum('delta'), 2),
            ],
            'balances' => $tab === 'balances' ? Customer::query()->where('credit_balance', '!=', 0)
                ->when($q !== '', fn ($w) => $w->where(fn ($s) => $s->where('phone', 'like', '%'.(normalize_phone($q) ?? $q).'%')->orWhere('name', 'like', "%$q%")))
                ->orderByDesc('credit_balance')->paginate(30)->withQueryString() : null,
            'ledger' => $tab === 'ledger' ? WalletTransaction::query()->with(['customer', 'user', 'order'])
                ->when($q !== '', fn ($w) => $w->whereHas('customer', fn ($c) => $c->where('phone', 'like', '%'.(normalize_phone($q) ?? $q).'%')))
                ->latest('id')->paginate(50)->withQueryString() : null,
            'gifts' => $tab === 'gifts' ? GiftCard::query()->with(['redeemedBy', 'seller'])
                ->when($q !== '', fn ($w) => $w->where(fn ($s) => $s->where('code', 'like', '%'.GiftCard::normalizeCode($q).'%')->orWhere('recipient_phone', 'like', "%$q%")))
                ->latest('id')->paginate(30)->withQueryString() : null,
            'canAdjust' => $request->user()->can('pos.discount'),
        ]);
    }

    /** POS: the guest's balance by mobile number. */
    public function lookup(Request $request): JsonResponse
    {
        $phone = normalize_phone((string) $request->query('phone', ''));
        if (! $phone) {
            return response()->json(['message' => __('Enter a valid Egyptian mobile number.')], 422);
        }
        $customer = Customer::query()->where('phone', $phone)->first();

        return response()->json([
            'found' => (bool) $customer, 'phone' => $phone, 'name' => $customer?->name ?? '',
            'balance' => round((float) ($customer?->credit_balance ?? 0), 2),
        ]);
    }

    /** POS: the guest pays (cash / card / InstaPay / mobile wallet) and the amount goes to their balance. */
    public function topUp(Request $request, WalletService $wallet, ShiftService $shifts): JsonResponse
    {
        $data = $request->validate([
            'phone' => 'required|string|max:20',
            'name' => 'nullable|string|max:100',
            'amount' => 'required|numeric|min:1|max:'.WalletService::MAX_AMOUNT,
            'method' => ['required', Rule::in(WalletService::TOPUP_METHODS)],
        ]);
        $phone = normalize_phone($data['phone']);
        if (! $phone) {
            return response()->json(['message' => __('Enter a valid Egyptian mobile number.')], 422);
        }
        $customer = Customer::query()->firstOrCreate(['phone' => $phone], ['name' => mb_substr(trim($data['name'] ?? ''), 0, 100), 'source' => 'pos']);
        try {
            $tx = $wallet->topUp($customer, (float) $data['amount'], $data['method'], $request->user(), $shifts->current($request->user()));
        } catch (OrderException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        Audit::record('wallet.topup', $phone, ['amount' => $tx->delta, 'method' => $data['method']]);

        return response()->json([
            'ok' => true, 'balance' => $tx->balance_after, 'name' => $customer->name, 'phone' => $phone,
            'receipt' => route('dash.wallet.receipt', $tx),
        ]);
    }

    /** POS: sell a gift card; the answer has the link of its printable card. */
    public function sellGift(Request $request, WalletService $wallet, ShiftService $shifts): JsonResponse
    {
        $data = $request->validate([
            'amount' => 'required|numeric|min:1|max:'.WalletService::MAX_AMOUNT,
            'method' => ['required', Rule::in(WalletService::TOPUP_METHODS)],
            'recipient_phone' => 'nullable|string|max:20',
            'message' => 'nullable|string|max:200',
            'buyer_phone' => 'nullable|string|max:20',
        ]);
        $buyerPhone = ! empty($data['buyer_phone']) ? normalize_phone($data['buyer_phone']) : null;
        $buyer = $buyerPhone ? Customer::query()->firstOrCreate(['phone' => $buyerPhone], ['name' => '', 'source' => 'pos']) : null;
        try {
            $card = $wallet->sellGiftCard((float) $data['amount'], $data['method'], $request->user(), $shifts->current($request->user()),
                $data['recipient_phone'] ?? null, $data['message'] ?? null, $buyer);
        } catch (OrderException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        Audit::record('wallet.gift_sold', $card->formattedCode(), ['amount' => $card->amount, 'method' => $data['method']]);

        return response()->json(['ok' => true, 'id' => $card->id, 'code' => $card->formattedCode(), 'print' => route('dash.wallet.gift.print', $card)]);
    }

    /** 80mm gift card: amount, message, the code and a QR to /gift/{code} on the menu. */
    public function printGift(GiftCard $card): View
    {
        $url = tenant()->url('gift/'.$card->code);

        return view('dashboard.wallet.receipt', [
            'card' => $card, 'tx' => null, 'settings' => Settings::all(), 'url' => $url, 'qr' => Qr::svg($url, 180),
        ]);
    }

    /** 80mm top-up slip: amount, new balance and a QR to the guest's card page (where the balance shows). */
    public function receipt(WalletTransaction $tx): View
    {
        abort_unless($tx->kind === 'topup', 404);
        $tx->load('customer');
        $url = tenant()->url('card/'.$tx->customer->card_token);

        return view('dashboard.wallet.receipt', [
            'card' => null, 'tx' => $tx, 'settings' => Settings::all(), 'url' => $url, 'qr' => Qr::svg($url, 160),
        ]);
    }

    /** Voiding destroys prepaid value, so it is limited to owner / manager like a manual balance correction. */
    public function voidGift(Request $request, GiftCard $card, WalletService $wallet): RedirectResponse
    {
        abort_unless($request->user()->can('pos.discount'), 403);
        try {
            $wallet->voidGiftCard($card);
        } catch (OrderException $e) {
            return back()->withErrors(['gift' => $e->getMessage()]);
        }
        Audit::record('wallet.gift_void', $card->formattedCode(), ['amount' => $card->amount]);

        return back()->with('ok', __('Gift card voided.'));
    }

    /** Manual correction of a balance (owner / manager), with a reason in the ledger. */
    public function adjust(Request $request, Customer $customer, WalletService $wallet): RedirectResponse
    {
        abort_unless($request->user()->can('pos.discount'), 403);
        $data = $request->validate(['delta' => 'required|numeric|min:-'.WalletService::MAX_AMOUNT.'|max:'.WalletService::MAX_AMOUNT, 'note' => 'required|string|max:200']);
        try {
            $wallet->adjust($customer, (float) $data['delta'], $request->user(), $data['note']);
        } catch (OrderException $e) {
            return back()->withErrors(['delta' => $e->getMessage()]);
        }
        Audit::record('wallet.adjust', $customer->phone, ['delta' => round((float) $data['delta'], 2), 'note' => $data['note']]);

        return back()->with('ok', __('Balance updated.'));
    }
}
