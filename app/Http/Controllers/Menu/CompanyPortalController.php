<?php

namespace App\Http\Controllers\Menu;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\CorporateContract;
use App\Models\CorporateVoucher;
use App\Services\Corporate\CorporateAccount;
use App\Services\Corporate\CorporateBookings;
use App\Services\Corporate\CorporateVouchers;
use App\Services\Orders\OrderException;
use App\Services\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * /company/{token} on the restaurant's menu: a contracted company's private page. It asks for visits (at least the
 * contract's notice ahead; a manager confirms each) and sees its account, its visits and the vouchers they left it.
 * The link is the company's key: a manager makes a new one from the contract and the old one stops working.
 */
class CompanyPortalController extends Controller
{
    public function show(string $token, CorporateAccount $account, CorporateBookings $bookings): Response
    {
        $contract = $this->contract($token);
        $vouchers = CorporateVoucher::query()->where('corporate_contract_id', $contract->id)->whereNotNull('corporate_booking_id')
            ->where('status', 'active')->where('balance', '>', 0)->where('expires_on', '>=', now()->toDateString())
            ->orderBy('expires_on')->limit(50)->get();

        return response(view('menu.company', [
            'contract' => $contract, 'token' => $token, 'settings' => Settings::all(),
            'account' => $account->summary($contract), 'available' => $bookings->available($contract),
            'bookings' => $contract->bookings()->with('branch')->latest('visit_at')->limit(30)->get(),
            'vouchers' => $vouchers, 'branches' => $this->branches($contract),
            'earliest' => now()->addHours($contract->booking_notice_hours),
        ]), 200, CardAppController::PRIVATE_HEADERS);
    }

    public function book(Request $request, string $token, CorporateBookings $bookings): RedirectResponse
    {
        $contract = $this->contract($token);
        $data = $request->validate([
            'branch_id' => 'nullable|integer',
            'date' => 'required|date_format:Y-m-d',
            'time' => 'required|date_format:H:i',
            'party_size' => 'required|integer|min:1|max:'.CorporateBookings::MAX_PARTY,
            'budget' => 'required|numeric|min:1|max:'.CorporateBookings::MAX_BUDGET,
            'rep_name' => 'required|string|max:100',
            'rep_phone' => 'required|string|max:20',
            'notes' => 'nullable|string|max:500',
        ]);
        try {
            $bookings->request($contract, $data, 'portal');
        } catch (OrderException $e) {
            return back()->withInput()->withErrors(['date' => $e->getMessage()]);
        }

        return redirect()->route('menu.company', $token)->with('ok', __('Visit sent. The restaurant confirms it and you will see it here.'));
    }

    private function contract(string $token): CorporateContract
    {
        abort_unless(CorporateVouchers::enabled(), 404);

        return CorporateContract::query()->where('portal_token', $token)->where('status', '!=', 'void')->firstOrFail();
    }

    private function branches(CorporateContract $contract)
    {
        return Branch::query()->where('is_active', true)
            ->when(! empty($contract->branch_ids), fn ($q) => $q->whereIn('id', $contract->branch_ids))
            ->orderBy('sort_order')->get();
    }
}
