<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\CorporateVoucherRedemption;
use App\Models\Order;
use App\Services\Corporate\CorporateVouchers;
use App\Services\Entitlements;
use App\Services\Orders\OrderException;
use App\Services\Orders\OrderService;
use App\Services\Orders\ShiftService;
use App\Services\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * "صرف الفاوتشرات" (feature corporate_vouchers, permission vouchers.redeem — no POS needed): the cashier scans or
 * types a voucher code, or the guest's mobile, and takes the vouchers on an open order of the branch (through
 * OrderService::pay(), like the POS) or on a bill written on paper (a redemption without an order). The lookup,
 * the exception after expiry and the link to a mobile are CorporateRedeemController's, the rules CorporateVouchers'.
 */
class VoucherRedeemController extends Controller
{
    /** How the rest of an order is paid next to the vouchers (the guest's prepaid wallet can't be mixed with them). */
    public const REST_METHODS = ['cash', 'card', 'instapay', 'wallet'];

    public function index(Request $request, CorporateVouchers $service, ShiftService $shifts): View
    {
        $branch = PosController::branch($request);
        $user = $request->user();

        return view('dashboard.corporate.redeem', [
            'branch' => $branch,
            'branches' => $user->branch_id ? collect([$branch]) : Branch::query()->where('is_active', true)->orderBy('sort_order')->get(),
            'needsShift' => Entitlements::current()->allows('shifts') && ! $shifts->current($user),
            'recent' => $this->recent($branch),
            'canApprove' => $user->can('vouchers.exception'),
            'approvers' => $service->approvers()->reject(fn ($u) => $u->id === $user->id)->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])->values(),
            'settings' => Settings::all(),
            // opened from the guest desk: that guest's mobile is looked up as the screen opens
            'lookup' => normalize_phone((string) $request->query('phone', '')),
        ]);
    }

    /** Today's unpaid orders of the branch (the order board's open bills): number, table / type, total. */
    public function orders(Request $request): JsonResponse
    {
        $branch = PosController::branch($request);
        $orders = Order::query()->where('branch_id', $branch->id)->where('payment_status', 'unpaid')->where('status', '!=', 'cancelled')
            ->where('created_at', '>=', now()->startOfDay())->with('table')->latest('id')->limit(60)->get();

        return response()->json(['orders' => $orders->map(fn (Order $o) => [
            'id' => $o->id, 'number' => $o->number, 'table' => $o->table?->name, 'type' => $o->type, 'typeLabel' => self::typeLabel($o->type),
            'status' => $o->statusLabel(), 'total' => (float) $o->total, 'time' => local_time($o->created_at, 'h:i A'),
        ])->values()]);
    }

    /** Pays an open order: the vouchers first (earliest expiry first), the rest by the method chosen. */
    public function pay(Request $request, Order $order, OrderService $orders, ShiftService $shifts, CorporateVouchers $service): JsonResponse
    {
        abort_if($request->user()->branch_id && $request->user()->branch_id !== $order->branch_id, 404);
        $data = $request->validate([
            'method' => ['required', Rule::in([...self::REST_METHODS, CorporateVouchers::METHOD])],
        ] + self::voucherRules());
        try {
            DB::transaction(fn () => $orders->pay($order, $data['method'], $request->user(), $shifts->current($request->user()),
                self::vouchers($data), $data['voucher_phone'] ?? null));
        } catch (OrderException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        $batch = (string) CorporateVoucherRedemption::query()->where('order_id', $order->id)->whereNull('refunded_at')->latest('id')->value('batch');

        return response()->json($this->done($service, $batch));
    }

    /** A bill written on paper: the vouchers pay up to the amount typed, the rest is collected the usual way. */
    public function manual(Request $request, CorporateVouchers $service): JsonResponse
    {
        $data = $request->validate([
            'amount' => 'required|numeric|min:0.01|max:'.(CorporateVouchers::MAX_VALUE * CorporateVouchers::MAX_PER_ORDER),
            'reference' => 'nullable|string|max:60',
            'note' => 'nullable|string|max:200',
        ] + self::voucherRules());
        try {
            $result = $service->redeemManual(self::vouchers($data), $data['voucher_phone'] ?? null, (float) $data['amount'],
                PosController::branch($request), $request->user(), $data['reference'] ?? null, $data['note'] ?? null);
        } catch (OrderException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->done($service, $result['batch']));
    }

    /** A manager cancels a paper-bill redemption (here, or with their PIN on this device): the balances come back. */
    public function cancel(Request $request, string $batch, CorporateVouchers $service): JsonResponse
    {
        $data = $request->validate(['reason' => 'required|string|max:200', 'approver_id' => 'nullable|integer', 'pin' => 'nullable|string|max:12']);
        try {
            $service->cancelManual($batch, $request->user(), $data['reason'], $data['approver_id'] ?? null, $data['pin'] ?? null);
        } catch (OrderException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'message' => __('Cancelled: the vouchers got their value back.')]);
    }

    /** The guest's 80mm slip: what each voucher gave and what is left on it. */
    public function receipt(string $batch, CorporateVouchers $service): View
    {
        try {
            $summary = $service->batchSummary($batch);
        } catch (OrderException) {
            abort(404);
        }

        return view('dashboard.corporate.redeem-receipt', ['s' => $summary, 'settings' => Settings::all()]);
    }

    /** @return array<string,mixed> the confirmation the screen shows after a payment */
    private function done(CorporateVouchers $service, string $batch): array
    {
        $s = $service->batchSummary($batch);

        return [
            'ok' => true, 'batch' => $batch, 'covered' => $s['covered'], 'bill' => $s['bill'], 'rest' => round(max(0, $s['bill'] - $s['covered']), 2),
            'lost' => $s['lost'], 'order' => $s['order']?->number, 'reference' => $s['reference'], 'lines' => $s['lines'],
            'receipt' => route('dash.redeem.receipt', $batch),
        ];
    }

    /**
     * Today's uses in this branch, one line per payment (batch).
     *
     * @return Collection<int,array<string,mixed>>
     */
    private function recent(Branch $branch): Collection
    {
        return CorporateVoucherRedemption::query()->with(['voucher:id,serial,corporate_contract_id', 'voucher.contract:id,name', 'order:id,number', 'cashier:id,name', 'refunder:id,name'])
            ->where('branch_id', $branch->id)->where('created_at', '>=', now()->startOfDay())->whereNotNull('batch')
            ->latest('id')->limit(200)->get()->groupBy('batch')->take(30)
            ->map(fn (Collection $rows, string $batch) => [
                'batch' => $batch, 'at' => $rows->first()->created_at, 'order' => $rows->first()->order?->number,
                'manual' => $rows->first()->isManual(), 'reference' => $rows->first()->reference, 'bill' => $rows->first()->bill_amount,
                'amount' => round((float) $rows->sum('amount'), 2), 'serials' => $rows->pluck('voucher.serial')->filter()->values()->all(),
                'companies' => $rows->pluck('voucher.contract.name')->filter()->unique()->values()->all(),
                'cashier' => $rows->first()->cashier?->name, 'cancelled' => $rows->every(fn ($r) => $r->refunded_at !== null),
                'cancelledBy' => $rows->first()->refunder?->name, 'exception' => $rows->contains(fn ($r) => $r->exception_reason !== null),
            ])->values();
    }

    public static function typeLabel(?string $type): string
    {
        return ['dine_in' => __('Dine-in'), 'takeaway' => __('Takeaway'), 'delivery' => __('Delivery')][$type] ?? (string) $type;
    }

    /** @return array<string,mixed> */
    private static function voucherRules(): array
    {
        return [
            'vouchers' => 'required|array|min:1|max:'.CorporateVouchers::MAX_PER_ORDER,
            'vouchers.*.code' => 'nullable|string|max:300',
            'vouchers.*.id' => 'nullable|integer',
            'vouchers.*.approval' => 'nullable|string|max:64',
            'voucher_phone' => 'nullable|string|max:20',
        ];
    }

    /** @return list<array{code:?string, id:?int, approval:?string}> */
    private static function vouchers(array $data): array
    {
        return array_values(array_map(fn ($v) => [
            'code' => isset($v['code']) ? (string) $v['code'] : null, 'id' => isset($v['id']) ? (int) $v['id'] : null,
            'approval' => isset($v['approval']) ? (string) $v['approval'] : null,
        ], array_filter((array) ($data['vouchers'] ?? []), 'is_array')));
    }
}
