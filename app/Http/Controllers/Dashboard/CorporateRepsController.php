<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\CorporateContract;
use App\Models\CorporateRep;
use App\Services\Corporate\CorporateAccount;
use App\Services\Corporate\CorporateReps;
use App\Services\Corporate\CorporateVouchers;
use App\Services\Orders\OrderException;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The reps of a company, on the company's page (feature corporate_vouchers, permission contracts.manage): add a rep,
 * put a balance for him or take it back, issue his vouchers out of it, and say how much he may order on the company's
 * account outside the vouchers. The rules are CorporateReps'.
 */
class CorporateRepsController extends Controller
{
    public function store(Request $request, CorporateContract $contract, CorporateReps $reps): RedirectResponse
    {
        $data = $request->validate(['name' => 'required|string|max:100', 'phone' => 'nullable|string|max:20']);

        return $this->attempt($contract->id, function () use ($reps, $contract, $data) {
            $rep = $reps->add($contract, $data);

            return __(':name added. Put a balance for him to issue his vouchers.', ['name' => $rep->name]);
        });
    }

    public function update(Request $request, CorporateRep $rep, CorporateReps $reps): RedirectResponse
    {
        $data = $request->validate(['name' => 'required|string|max:100', 'phone' => 'nullable|string|max:20', 'is_active' => 'nullable|boolean']);

        return $this->attempt($rep->corporate_contract_id, function () use ($reps, $rep, $data, $request) {
            $reps->update($rep, $data + ['is_active' => $request->boolean('is_active')]);

            return __('Rep saved.');
        });
    }

    public function topUp(Request $request, CorporateRep $rep, CorporateReps $reps): RedirectResponse
    {
        $data = $request->validate(['amount' => 'required|numeric|min:0.01|max:'.CorporateAccount::MAX_PAYMENT, 'note' => 'nullable|string|max:200']);

        return $this->attempt($rep->corporate_contract_id, function () use ($reps, $rep, $data, $request) {
            $reps->topUp($rep, (float) $data['amount'], $request->user(), $data['note'] ?? null);

            return __(':name has :balance now. Issue his vouchers out of it.', ['name' => $rep->name, 'balance' => money($rep->balance)]);
        });
    }

    public function withdraw(Request $request, CorporateRep $rep, CorporateReps $reps): RedirectResponse
    {
        $data = $request->validate(['amount' => 'required|numeric|min:0.01|max:'.CorporateAccount::MAX_PAYMENT]);

        return $this->attempt($rep->corporate_contract_id, function () use ($reps, $rep, $data, $request) {
            $reps->withdraw($rep, (float) $data['amount'], $request->user());

            return __(':amount went back to the company from :name.', ['amount' => money((float) $data['amount']), 'name' => $rep->name]);
        });
    }

    public function issue(Request $request, CorporateRep $rep, CorporateReps $reps): RedirectResponse
    {
        $data = $request->validate([
            'value' => 'required|numeric|min:1|max:'.CorporateVouchers::MAX_VALUE,
            'count' => 'required|integer|min:1|max:'.CorporateVouchers::MAX_QUANTITY,
            'expires_on' => 'required|date|after_or_equal:'.local_today(),
            'mode' => ['required', Rule::in(array_keys(CorporateContract::MODES))],
        ]);

        return $this->attempt($rep->corporate_contract_id, function () use ($reps, $rep, $data, $request) {
            $n = $reps->issueVouchers($rep, (float) $data['value'], (int) $data['count'], $data['expires_on'], $data['mode'], $request->user());

            return __(':n vouchers for :name. Print them from “Print his new vouchers”.', ['n' => $n, 'name' => $rep->name]);
        });
    }

    public function direct(Request $request, CorporateRep $rep, CorporateReps $reps): RedirectResponse
    {
        $data = $request->validate(['left' => 'required|numeric|min:0|max:'.CorporateAccount::MAX_PAYMENT]);

        return $this->attempt($rep->corporate_contract_id, function () use ($reps, $rep, $data, $request) {
            $reps->allowDirect($rep, (float) $data['left'], $request->user());

            return (float) $data['left'] > 0
                ? __(':name may order up to :amount on the company\'s account: the cashier types his mobile.', ['name' => $rep->name, 'amount' => money((float) $data['left'])])
                : __(':name no longer orders on the company\'s account.', ['name' => $rep->name]);
        });
    }

    /** Runs the change and goes back to the company's page, with what it answered or why it was refused. */
    private function attempt(int $contractId, Closure $change): RedirectResponse
    {
        $to = redirect()->to(route('dash.corporate.show', $contractId).'#reps');
        try {
            return $to->with('ok', $change());
        } catch (OrderException $e) {
            return $to->withInput()->withErrors(['rep' => $e->getMessage()]);
        }
    }
}
