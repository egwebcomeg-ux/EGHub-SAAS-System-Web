<?php

namespace App\Http\Controllers\Admin;

use App\Billing\Billing;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Platform invoices: confirm manual transfers (InstaPay / Vodafone Cash) or void. */
class InvoicesController extends Controller
{
    public function index(Request $request): View
    {
        $status = (string) $request->query('status', 'awaiting');
        $invoices = Invoice::query()->with(['tenant', 'plan', 'payments'])
            ->when($status === 'awaiting', fn ($q) => $q->where('status', 'open')->whereHas('payments', fn ($p) => $p->where('status', 'pending')))
            ->when(in_array($status, ['open', 'paid', 'void'], true), fn ($q) => $q->where('status', $status))
            ->latest('id')->paginate(30)->withQueryString();

        return view('admin.invoices', ['invoices' => $invoices, 'status' => $status]);
    }

    public function markPaid(Request $request, Invoice $invoice, Billing $billing): RedirectResponse
    {
        $data = $request->validate([
            'gateway'   => 'required|in:instapay,vodafone_cash,manual',
            'reference' => 'nullable|string|max:80',
            'amount'    => 'required|numeric|min:0',
        ]);
        $applied = $billing->markPaid($invoice, $data['gateway'], $data['reference'] ?? null, (float) $data['amount'],
            'admin:' . $invoice->id, $request->user('admin')->id);

        return back()->with('ok', $applied ? __('Payment confirmed and plan activated.') : __('This invoice was already settled.'));
    }

    public function void(Invoice $invoice): RedirectResponse
    {
        if ($invoice->status === 'open') {
            $invoice->update(['status' => 'void']);
        }
        return back()->with('ok', __('Invoice voided.'));
    }
}
