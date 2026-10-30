<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\CorporateBooking;
use App\Models\CorporateContract;
use App\Models\CorporatePayment;
use App\Models\CorporateVoucher;
use App\Models\CorporateVoucherRedemption;
use App\Models\Customer;
use App\Services\Corporate\CorporateAccount;
use App\Services\Corporate\CorporateVouchers;
use App\Services\Import\XlsxWriter;
use App\Services\Orders\OrderException;
use App\Services\Qr;
use App\Services\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * "Corporate contracts" (feature corporate_vouchers, permission contracts.manage): a company buys prepaid vouchers
 * for its staff. The list with each contract's figures, the contract form, pause / void, new expiry dates, printing
 * (A4 cut-out cards or one 80mm receipt per voucher; a reprint says "نسخة"), the CSV / Excel export and the
 * company statement for settlement. Redemption happens at the POS (CorporateRedeemController).
 */
class CorporateContractsController extends Controller
{
    public const PRINT_LAYOUTS = ['a4', 'receipt'];

    /** At most this many vouchers in one print / page. */
    public const PRINT_MAX = 500;

    public function index(Request $request, CorporateVouchers $service): View
    {
        $q = mb_substr(trim((string) $request->query('q', '')), 0, 40);
        $contracts = CorporateContract::query()->latest('id')->paginate(20)->withQueryString();

        return view('dashboard.corporate.index', [
            'contracts' => $contracts,
            'stats' => $contracts->getCollection()->mapWithKeys(fn (CorporateContract $c) => [$c->id => $service->stats($c)]),
            'q' => $q,
            'found' => $q !== '' ? $this->search($q) : null,
            'totals' => [
                'contracts' => CorporateContract::query()->where('status', '!=', 'void')->count(),
                'remaining' => round((float) CorporateVoucher::query()->where('status', 'active')
                    ->whereHas('contract', fn ($c) => $c->where('status', '!=', 'void'))->sum('balance'), 2),
                'month' => round((float) CorporateVoucherRedemption::query()->whereNull('refunded_at')->where('created_at', '>=', local_at()->startOfMonth()->utc())->sum('amount'), 2),
                'unpaid' => CorporateContract::query()->where('status', '!=', 'void')->where('paid_status', '!=', 'paid')->count(),
                'visits' => CorporateBooking::query()->where('status', 'requested')->count(),
            ],
        ]);
    }

    public function create(): View
    {
        return view('dashboard.corporate.form', ['contract' => new CorporateContract(['mode' => 'single', 'expires_on' => local_at()->addMonths(3)->endOfMonth()->toDateString()]), 'branches' => $this->branches()]);
    }

    public function store(Request $request, CorporateVouchers $service): RedirectResponse
    {
        $data = $request->validate($this->rules(null));
        $data['branch_ids'] = $this->ownBranches($data['branch_ids'] ?? []);
        try {
            $contract = $service->createContract($data, $request->user());
        } catch (OrderException $e) {
            return back()->withInput()->withErrors(['name' => $e->getMessage()]);
        }

        return redirect()->route('dash.corporate.show', $contract)->with('ok', __(':n vouchers created. Print them from here.', ['n' => $contract->quantity]));
    }

    public function show(Request $request, CorporateContract $contract, CorporateVouchers $service): View
    {
        $filter = in_array($request->query('filter'), ['active', 'used', 'void', 'expired', 'unprinted', 'linked'], true) ? (string) $request->query('filter') : 'all';
        $vouchers = $contract->vouchers()->with('customer')
            ->when($filter === 'active', fn ($q) => $q->where('status', 'active')->where('expires_on', '>=', local_today()))
            ->when($filter === 'expired', fn ($q) => $q->where('status', 'active')->where('expires_on', '<', local_today()))
            ->when(in_array($filter, ['used', 'void'], true), fn ($q) => $q->where('status', $filter))
            ->when($filter === 'unprinted', fn ($q) => $q->where('print_count', 0))
            ->when($filter === 'linked', fn ($q) => $q->whereNotNull('customer_id'))
            ->orderBy('id')->paginate(50)->withQueryString();

        return view('dashboard.corporate.show', [
            'contract' => $contract, 'stats' => $service->stats($contract), 'vouchers' => $vouchers, 'filter' => $filter,
            'visitsWaiting' => $contract->bookings()->where('status', 'requested')->count(),
            'branchNames' => Branch::query()->pluck('name', 'id'),
        ]);
    }

    public function edit(CorporateContract $contract): View
    {
        abort_if($contract->status === 'void', 404);

        return view('dashboard.corporate.form', ['contract' => $contract, 'branches' => $this->branches()]);
    }

    public function update(Request $request, CorporateContract $contract, CorporateVouchers $service): RedirectResponse
    {
        abort_if($contract->status === 'void', 404);
        $data = $request->validate($this->rules($contract));
        $data['branch_ids'] = $this->ownBranches($data['branch_ids'] ?? []);
        try {
            $service->updateContract($contract, $data);
        } catch (OrderException $e) {
            return back()->withInput()->withErrors(['quantity' => $e->getMessage()]);
        }

        return redirect()->route('dash.corporate.show', $contract)->with('ok', __('Contract saved.'));
    }

    public function status(Request $request, CorporateContract $contract, CorporateVouchers $service): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(array_keys(CorporateContract::STATUSES))]]);
        try {
            $service->setStatus($contract, $data['status']);
        } catch (OrderException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }

        return back()->with('ok', __('Contract status: :status', ['status' => $contract->statusLabel()]));
    }

    public function extend(Request $request, CorporateContract $contract, CorporateVouchers $service): RedirectResponse
    {
        $data = $request->validate(['expires_on' => 'required|date']);
        try {
            $service->extendContract($contract, $data['expires_on']);
        } catch (OrderException $e) {
            return back()->withErrors(['expires_on' => $e->getMessage()]);
        }

        return back()->with('ok', __('Valid until :date now.', ['date' => local_time($contract->refresh()->expires_on, 'Y/m/d')]));
    }

    public function extendVoucher(Request $request, CorporateVoucher $voucher, CorporateVouchers $service): RedirectResponse
    {
        $data = $request->validate(['expires_on' => 'required|date']);
        try {
            $service->extendVoucher($voucher, $data['expires_on']);
        } catch (OrderException $e) {
            return back()->withErrors(['expires_on' => $e->getMessage()]);
        }

        return back()->with('ok', __(':serial is valid until :date now.', ['serial' => $voucher->serial, 'date' => local_time($voucher->expires_on, 'Y/m/d')]));
    }

    public function voidVoucher(CorporateVoucher $voucher, CorporateVouchers $service): RedirectResponse
    {
        try {
            $service->voidVoucher($voucher);
        } catch (OrderException $e) {
            return back()->withErrors(['voucher' => $e->getMessage()]);
        }

        return back()->with('ok', __(':serial voided.', ['serial' => $voucher->serial]));
    }

    /**
     * Prints the contract's vouchers: all, the listed ids, or those never printed. Every voucher printed before
     * comes out marked "copy"; the print is counted and audit-logged.
     */
    public function print(Request $request, CorporateContract $contract, CorporateVouchers $service): View
    {
        $layout = in_array($request->query('layout'), self::PRINT_LAYOUTS, true) ? (string) $request->query('layout') : 'a4';
        $ids = array_filter(array_map('intval', explode(',', (string) $request->query('ids', ''))));
        $vouchers = $contract->vouchers()->where('status', '!=', 'void')
            ->when($ids !== [], fn ($q) => $q->whereIn('id', $ids))
            ->when($request->boolean('unprinted'), fn ($q) => $q->where('print_count', 0))
            ->orderBy('id')->limit(self::PRINT_MAX)->get();
        abort_if($vouchers->isEmpty(), 404);
        $vouchers->each->setRelation('contract', $contract);
        $copies = $service->markPrinted($vouchers, $layout);

        return view($layout === 'receipt' ? 'dashboard.corporate.receipt' : 'dashboard.corporate.print', [
            'contract' => $contract, 'layout' => $layout, 'settings' => Settings::all(),
            'cards' => $vouchers->map(fn (CorporateVoucher $v) => [
                'voucher' => $v, 'copy' => $copies[$v->id] ?? false, 'qr' => Qr::svg($v->publicUrl(), 200, '#111111'),
            ]),
            'query' => array_filter(['ids' => $request->query('ids'), 'unprinted' => $request->query('unprinted')]),
            'branches' => $contract->branch_ids ? Branch::query()->whereIn('id', $contract->branch_ids)->get() : collect(),
        ]);
    }

    /** One voucher on the 80mm printer. */
    public function receipt(CorporateVoucher $voucher): RedirectResponse
    {
        return redirect()->route('dash.corporate.print', ['contract' => $voucher->corporate_contract_id, 'layout' => 'receipt', 'ids' => $voucher->id]);
    }

    /** The vouchers of a contract as CSV or Excel (serial, code, value, balance, status, expiry, link). */
    public function export(Request $request, CorporateContract $contract): Response
    {
        $rows = [[__('Serial'), __('Code'), __('Value'), __('Balance'), __('Status'), __('Valid until'), __('Linked mobile'), __('Prints'), __('Link')]];
        foreach ($contract->vouchers()->with('customer')->orderBy('id')->cursor() as $v) {
            $rows[] = [$v->serial, $v->formattedCode(), $v->value, $v->balance, $v->statusLabel(), $v->expires_on->format('Y-m-d'),
                $v->customer?->phone ?? '', $v->print_count, $v->publicUrl()];
        }

        return $this->download($rows, 'vouchers-'.$contract->prefix.'-'.local_today(), (string) $request->query('format'), __('Vouchers'));
    }

    /** A payment the company made (cash, transfer, cheque…): a credit line of its statement. */
    public function storePayment(Request $request, CorporateContract $contract, CorporateAccount $account): RedirectResponse
    {
        $data = $request->validate([
            'amount' => 'required|numeric|min:0.01|max:'.CorporateAccount::MAX_PAYMENT,
            'method' => ['required', Rule::in(array_keys(CorporatePayment::METHODS))],
            'paid_on' => 'nullable|date|before_or_equal:'.local_today(),
            'reference' => 'nullable|string|max:60',
            'note' => 'nullable|string|max:200',
        ]);
        try {
            $account->recordPayment($contract, $data, $request->user());
        } catch (OrderException $e) {
            return back()->withInput()->withErrors(['amount' => $e->getMessage()]);
        }

        return redirect()->route('dash.corporate.statement', $contract)->with('ok', __('Payment recorded.'));
    }

    /** A payment entered by mistake: crossed out on the statement, no longer counted. */
    public function cancelPayment(Request $request, CorporatePayment $payment, CorporateAccount $account): RedirectResponse
    {
        $data = $request->validate(['reason' => 'required|string|max:200']);
        try {
            $account->cancelPayment($payment, $request->user(), $data['reason']);
        } catch (OrderException $e) {
            return back()->withErrors(['reason' => $e->getMessage()]);
        }

        return redirect()->route('dash.corporate.statement', $payment->corporate_contract_id)->with('ok', __('Payment cancelled.'));
    }

    /**
     * The company statement: the account (charged, paid, owed, drawn, left on the vouchers), the ledger of charges and
     * payments with the balance after each, and every use of the vouchers (refunded uses are shown, not counted).
     */
    public function statement(Request $request, CorporateContract $contract, CorporateAccount $account): View|Response
    {
        $from = $request->date('from');
        $to = $request->date('to');
        $rows = CorporateVoucherRedemption::query()->where('corporate_contract_id', $contract->id)
            ->with(['voucher.customer', 'order:id,number,total', 'branch:id,name,name_en', 'cashier:id,name', 'approver:id,name', 'refunder:id,name'])
            ->when($from, fn ($q) => $q->where('created_at', '>=', local_day_start($from)))->when($to, fn ($q) => $q->where('created_at', '<=', local_day_end($to)))
            ->orderBy('id')->get();

        if (in_array($request->query('format'), ['csv', 'xlsx'], true)) {
            $out = [[__('Date'), __('Branch'), __('Order'), __('Serial'), __('Amount'), __('Lost (one use)'), __('Exception'), __('Cashier')]];
            foreach ($rows->whereNull('refunded_at') as $r) {
                $out[] = [local_time($r->created_at, 'Y-m-d H:i'), $r->branch ? tr($r->branch->name, $r->branch->name_en) : '', self::billLabel($r),
                    $r->voucher?->serial, $r->amount, $r->forfeited, $r->exception_reason ? $r->exception_reason.' · '.$r->approver?->name : '', $r->cashier?->name];
            }
            $out[] = [__('Total'), '', '', '', round($rows->whereNull('refunded_at')->sum('amount'), 2), round($rows->whereNull('refunded_at')->sum('forfeited'), 2), '', ''];

            return $this->download($out, 'statement-'.$contract->prefix.'-'.local_today(), (string) $request->query('format'), __('Statement'));
        }

        return view('dashboard.corporate.statement', [
            'contract' => $contract, 'rows' => $rows, 'from' => $from, 'to' => $to,
            'account' => $account->summary($contract), 'ledger' => $account->ledger($contract),
            'total' => round($rows->whereNull('refunded_at')->sum('amount'), 2), 'forfeited' => round($rows->whereNull('refunded_at')->sum('forfeited'), 2),
        ]);
    }

    /** "#123" for a use on an order, "Paper bill 456 (of 300.00)" for one on a bill written on paper. */
    public static function billLabel(CorporateVoucherRedemption $r): string
    {
        if ($r->order) {
            return '#'.$r->order->number;
        }
        if ($r->isManual()) {
            return trim(__('Paper bill').' '.($r->reference ?? '')).' ('.__('of :amount', ['amount' => money($r->bill_amount)]).')';
        }

        return '';
    }

    /** @return Collection<int,CorporateVoucher> vouchers matching a code, a serial or a linked mobile */
    private function search(string $q)
    {
        $phone = normalize_phone($q);
        $code = CorporateVoucher::normalizeCode($q);
        $customerIds = $phone ? Customer::query()->where('phone', $phone)->pluck('id') : collect();

        return CorporateVoucher::query()->with(['contract', 'customer'])
            ->where(function ($w) use ($q, $code, $phone, $customerIds) {
                $w->where('serial', 'like', '%'.str_replace(['%', '_'], '', mb_strtoupper($q)).'%');
                if ($code !== '') {
                    $w->orWhere('code', $code);
                }
                if ($phone) {
                    $w->orWhereIn('customer_id', $customerIds)->orWhere('pending_phone', $phone);
                }
            })->orderBy('id')->limit(30)->get();
    }

    /** Rows as an Excel file (format xlsx) or a UTF-8 CSV; shared with the vouchers report. */
    public static function download(array $rows, string $name, string $format, string $sheet): Response
    {
        if ($format === 'xlsx') {
            $path = storage_path('app/private/corporate-'.random_token(4).'.xlsx');
            @mkdir(dirname($path), 0775, true);
            (new XlsxWriter)->addSheet($sheet, $rows)->save($path);

            return response()->download($path, "$name.xlsx")->deleteFileAfterSend();
        }

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            foreach ($rows as $row) {
                fputcsv($out, csv_row($row), ',', '"', '\\');
            }
            fclose($out);
        }, "$name.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function branches()
    {
        return Branch::query()->where('is_active', true)->orderBy('sort_order')->get();
    }

    /** @return list<int> only this restaurant's branches (empty = all) */
    private function ownBranches(array $ids): array
    {
        $ids = array_map('intval', $ids);

        return $ids === [] ? [] : Branch::query()->whereIn('id', $ids)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    private function rules(?CorporateContract $contract): array
    {
        $rules = [
            'name' => 'required|string|max:150',
            'contact_name' => 'nullable|string|max:100',
            'contact_phone' => 'nullable|string|max:20',
            'reference' => 'nullable|string|max:60',
            // 0 = a contract for company visits only (their vouchers are made when a visit is confirmed)
            'quantity' => ['required', 'integer', 'min:'.($contract?->quantity ?? 0), 'max:'.CorporateVouchers::MAX_QUANTITY],
            'booking_notice_hours' => 'nullable|integer|min:0|max:720',
            'branch_ids' => 'nullable|array|max:100',
            'branch_ids.*' => 'integer',
            'days' => 'nullable|array|max:7',
            'days.*' => 'integer|between:0,6',
            'hours_from' => 'nullable|date_format:H:i|required_with:hours_to',
            'hours_to' => 'nullable|date_format:H:i|required_with:hours_from',
            'min_order' => 'nullable|numeric|min:0|max:'.CorporateVouchers::MAX_VALUE,
            'billing' => ['nullable', Rule::in(array_keys(CorporateContract::BILLING))],
            'credit_limit' => 'nullable|numeric|min:0|max:'.CorporateAccount::MAX_PAYMENT,
            'notes' => 'nullable|string|max:2000',
        ];
        if (! $contract) {
            // later payments are recorded one by one on the company statement
            $rules += [
                'paid_amount' => 'nullable|numeric|min:0|max:'.CorporateAccount::MAX_PAYMENT,
                'paid_method' => ['nullable', Rule::in(array_keys(CorporatePayment::METHODS))],
                'prefix' => ['nullable', 'regex:/^[A-Za-z0-9]{1,6}$/'],
                'voucher_value' => 'required|numeric|min:1|max:'.CorporateVouchers::MAX_VALUE,
                'expires_on' => 'required|date|after_or_equal:'.local_today(),
                'mode' => ['required', Rule::in(array_keys(CorporateContract::MODES))],
            ];
        }

        return $rules;
    }
}
