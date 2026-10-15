<?php

namespace App\Http\Controllers\Menu;

use App\Http\Controllers\Controller;
use App\Models\GiftCard;
use App\Services\Orders\OrderException;
use App\Services\Settings;
use App\Services\Wallet\WalletService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * /gift/{code} on the restaurant's menu: the printed gift card's QR. Shows the card and adds it to the wallet of
 * the mobile number the guest enters, once. Codes are only looked up in this restaurant.
 */
class GiftController extends Controller
{
    public function show(string $code): View
    {
        abort_unless(WalletService::enabled(), 404);
        $card = GiftCard::query()->where('code', GiftCard::normalizeCode($code))->firstOrFail();

        return view('menu.gift', ['card' => $card, 'settings' => Settings::all()]);
    }

    public function redeem(Request $request, string $code, WalletService $wallet): RedirectResponse
    {
        abort_unless(WalletService::enabled(), 404);
        $data = $request->validate(['phone' => 'required|string|max:20', 'name' => 'nullable|string|max:100']);
        try {
            ['card' => $card, 'customer' => $customer, 'created' => $created] = $wallet->redeemGiftCard($code, $data['phone'], $data['name'] ?? '');
        } catch (OrderException $e) {
            return back()->withInput()->withErrors(['phone' => $e->getMessage()]);
        }

        // A new guest gets their card on this phone. An existing one keeps their card private: whoever typed the
        // number must not be able to spend a balance that was there before.
        if ($created) {
            return redirect()->route('menu.card', $customer->card_token)
                ->withCookie(cookie()->forever('card_'.tenant()->getTenantKey(), $customer->card_token));
        }

        return back()->with('ok', __(':amount added to the wallet of :phone. Pay with it on your next order.', [
            'amount' => money($card->amount), 'phone' => substr($customer->phone, 0, 3).'*****'.substr($customer->phone, -3),
        ]));
    }
}
