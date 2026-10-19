<?php

namespace App\Http\Controllers\Menu;

use App\Http\Controllers\Controller;
use App\Models\CorporateVoucher;
use App\Models\Customer;
use App\Services\Corporate\CorporateVouchers;
use App\Services\Orders\OrderException;
use App\Services\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * /voucher/{code} on the restaurant's menu: the printed corporate voucher's QR. Shows this voucher only (value,
 * balance, expiry) and lets the holder attach it to their mobile. With their card on this phone (the /card cookie,
 * the guest's identity everywhere on the menu) it is linked at once; a typed mobile is a request the cashier confirms
 * with the guest in front of them. Codes of another restaurant are simply not found.
 */
class CorporateVoucherController extends Controller
{
    public function show(Request $request, string $code): View
    {
        $voucher = $this->voucher($code);

        return view('menu.corporate-voucher', [
            'voucher' => $voucher, 'settings' => Settings::all(), 'me' => $this->cardHolder($request),
        ]);
    }

    public function link(Request $request, string $code, CorporateVouchers $service): RedirectResponse
    {
        $voucher = $this->voucher($code);
        $me = $this->cardHolder($request);
        try {
            if ($me && ! $request->filled('phone')) {
                $service->link($voucher, $me);

                return back()->with('ok', __('Linked to your mobile. From now on it works only with your mobile number.'));
            }
            $data = $request->validate(['phone' => 'required|string|max:20']);
            if ($me && normalize_phone($data['phone']) === $me->phone) {
                $service->link($voucher, $me);

                return back()->with('ok', __('Linked to your mobile. From now on it works only with your mobile number.'));
            }
            $service->requestLink($voucher, $data['phone']);
        } catch (OrderException $e) {
            return back()->withInput()->withErrors(['phone' => $e->getMessage()]);
        }

        return back()->with('ok', __('Request sent. Show this page and your mobile to the cashier on your next visit to confirm the link.'));
    }

    private function voucher(string $code): CorporateVoucher
    {
        abort_unless(CorporateVouchers::enabled(), 404);
        $code = CorporateVoucher::normalizeCode($code);
        abort_unless(CorporateVoucher::isValidCode($code), 404);

        return CorporateVoucher::query()->with(['contract', 'customer'])->where('code', $code)->firstOrFail();
    }

    /** The guest whose card is remembered on this phone (same cookie as /card). */
    private function cardHolder(Request $request): ?Customer
    {
        $token = $request->cookie('card_'.tenant()->getTenantKey());

        return is_string($token) && $token !== '' ? Customer::query()->where('card_token', $token)->first() : null;
    }
}
