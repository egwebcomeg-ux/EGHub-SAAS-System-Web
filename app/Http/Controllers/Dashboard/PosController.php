<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Customer;
use App\Models\DiningTable;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\PosTerminal;
use App\Models\Shift;
use App\Services\Corporate\CorporateVouchers;
use App\Services\Entitlements;
use App\Services\Menu\MenuCatalog;
use App\Services\Orders\ManagerApproval;
use App\Services\Orders\OrderException;
use App\Services\Orders\OrderService;
use App\Services\Orders\ShiftService;
use App\Services\Orders\Terminals;
use App\Services\Printing\PrintJobs;
use App\Services\Settings;
use App\Services\Wallet\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Cashier / waiter screen. Takes takeaway and table orders, sends them to the kitchen and collects payment.
 * Staff tied to a branch work in that branch; others pick one (remembered in the session).
 */
class PosController extends Controller
{
    public function screen(Request $request, ShiftService $shifts): View
    {
        $branch = self::branch($request);
        $settings = Settings::all();
        $shift = $shifts->current($request->user());

        return view('dashboard.pos.screen', [
            'branch' => $branch,
            'branches' => $request->user()->branch_id ? collect([$branch]) : Branch::query()->where('is_active', true)->orderBy('sort_order')->get(),
            'shift' => $shift,
            'needsShift' => Entitlements::current()->allows('shifts') && ! $shift,
            'data' => self::catalogData($branch) + [
                'currency' => tr($settings['currency'], $settings['currency_en']),
                'canDiscount' => $request->user()->can('pos.discount'),
                'canDiscardHold' => $request->user()->can('pos.hold.discard'),
                'share' => true, // the receipt's link on WhatsApp after payment
                // cash in / out of the drawer from the POS (the open shift; shifts.manage)
                'canCash' => Entitlements::current()->allows('shifts') && $request->user()->can('shifts.manage'),
                // refunds: a manager signed in approves; a cashier needs a manager's PIN on this device
                'canRefund' => $request->user()->can('pos.refund'),
                'refundApprovers' => $request->user()->can('pos.refund') ? [] : app(ManagerApproval::class)->approvers('pos.refund')
                    ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])->values()->all(),
                'coupons' => self::couponsAllowed(),
                'shifts' => Entitlements::current()->allows('shifts'),
                // prepaid wallet: anyone at the POS can take a payment from the balance; top-ups and gift cards need wallet.manage
                'wallet' => WalletService::enabled(),
                'walletManage' => WalletService::enabled() && $request->user()->can('wallet.manage'),
                // corporate vouchers: any cashier redeems; an expired one needs a manager (here, or their PIN on this device)
                'corporate' => self::corporateAllowed($request),
                'corporateApprove' => self::corporateAllowed($request) && $request->user()->can('vouchers.exception'),
                'approvers' => self::corporateAllowed($request)
                    ? app(CorporateVouchers::class)->approvers()->reject(fn ($u) => $u->id === $request->user()->id)->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])->values() : [],
                // offline mode: the device keeps this catalog, its terminal and the open shift to sell while the internet is down
                'tenant' => (string) tenant()->getTenantKey(),
                'user' => ['id' => $request->user()->id, 'name' => $request->user()->name],
                'branchId' => $branch->id,
                'shift' => self::shiftData($shift),
                'offlineHours' => Terminals::offlineHours(),
                'offlineMethods' => OrderService::OFFLINE_METHODS,
                'receiptHeader' => self::receiptHeader($branch),
                'generatedAt' => now()->toIso8601String(),
                // printing from the EgHub cashier app (resources/js/native-print.js): the owner's settings and the
                // printer roles of this branch (receipt, one per kitchen station); unused in a browser
                'printing' => [
                    'receiptAuto' => PrintJobs::settings()['receipt_auto'], 'kitchenAuto' => PrintJobs::settings()['kitchen_auto'],
                    'roles' => PrintJobs::kitchenRoles($branch),
                ],
                'urls' => [
                    'printJobs' => url('/pos/orders/__ID__/print-jobs'), 'printRelease' => url('/pos/orders/__ID__/print-jobs/release'),
                    'printQueue' => route('dash.pos.print.queue'),
                    'order' => route('dash.pos.order'), 'pay' => url('/pos/orders/__ID__/pay'), 'open' => route('dash.pos.open'),
                    'receipt' => url('/orders/__ID__/receipt'), 'display' => route('dash.pos.display'),
                    'catalog' => route('dash.pos.catalog'), 'ping' => route('dash.pos.ping'), 'sync' => route('dash.pos.sync'),
                    'claim' => url('/pos/terminals/__ID__/claim'), 'shifts' => route('dash.shifts'),
                    'holds' => route('dash.pos.holds'), 'holdsStore' => route('dash.pos.holds.store'),
                    'holdsResume' => url('/pos/holds/__ID__/resume'), 'holdsDiscard' => url('/pos/holds/__ID__/discard'),
                    'recent' => route('dash.pos.orders.recent'), 'refund' => url('/pos/orders/__ID__/refund'), 'cashMove' => route('dash.shifts.move'),
                ] + (self::couponsAllowed() ? ['coupon' => route('dash.pos.coupon')] : []) + (WalletService::enabled() ? [
                    'walletLookup' => route('dash.wallet.lookup'), 'topup' => route('dash.wallet.topup'), 'giftSell' => route('dash.wallet.gift.sell'),
                ] : []) + (self::corporateAllowed($request) ? [
                    'corporateCheck' => route('dash.pos.corporate.check'), 'corporateLink' => route('dash.pos.corporate.link'),
                    'corporateException' => url('/pos/vouchers/__ID__/exception'),
                ] : []),
            ],
        ]);
    }

    /**
     * What the device keeps to sell offline: the branch menu as the POS shows it (prices with the branch overrides
     * and running offers, options, availability), tables, service and tax, the terminals and the open shift. The
     * POS page refreshes it whenever it is online, together with a fresh CSRF token.
     */
    public function catalog(Request $request, ShiftService $shifts): JsonResponse
    {
        $branch = self::branch($request);
        Terminals::fromRequest($request, $branch); // marks the device as seen

        return response()->json(self::catalogData($branch) + [
            'branchId' => $branch->id, 'shift' => self::shiftData($shifts->current($request->user())),
            'offlineHours' => Terminals::offlineHours(), 'receiptHeader' => self::receiptHeader($branch),
            'csrf' => csrf_token(), 'fetchedAt' => now()->toIso8601String(),
        ]);
    }

    /** A small "am I online?" check: the session is alive, a fresh CSRF token. */
    public function ping(Request $request): JsonResponse
    {
        Terminals::fromRequest($request, self::branch($request));

        return response()->json(['ok' => true, 'csrf' => csrf_token(), 'time' => now()->toIso8601String()]);
    }

    /**
     * One sale made offline, sent by the device once it is back online (oldest first). Saved once per client_uuid;
     * a refused sale answers 422 with the reason (the device keeps it in its sync log to retry or mark resolved).
     */
    public function sync(Request $request, OrderService $orders): JsonResponse
    {
        $branch = self::branch($request);
        $data = $request->validate([
            'client_uuid' => 'required|uuid',
            'offline_ref' => 'required|string|max:24',
            'created_offline_at' => 'required|date',
            'offline_since' => 'nullable|date',
            'shift_id' => 'required|integer',
            'type' => ['required', Rule::in(['dine_in', 'takeaway'])],
            'table_id' => 'nullable|integer',
            'items' => 'required|array|min:1|max:'.OrderService::MAX_LINES,
            'items.*.item_id' => 'nullable|integer',
            'items.*.qty' => 'required|integer|min:1|max:'.OrderService::MAX_QTY,
            'items.*.name' => 'required|string|max:150',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.options' => 'nullable|array|max:30',
            'items.*.notes' => 'nullable|string|max:200',
            'notes' => 'nullable|string|max:500',
            'customer_name' => 'nullable|string|max:100',
            'customer_phone' => 'nullable|string|max:20',
            'discount' => 'nullable|numeric|min:0',
            'subtotal' => 'required|numeric|min:0',
            'service' => 'nullable|numeric|min:0',
            'tax' => 'nullable|numeric|min:0',
            'total' => 'required|numeric|min:0',
            'method' => ['required', 'string', 'max:20'],
            'tendered' => 'nullable|numeric|min:0',
        ] + self::paymentRules());
        $terminal = Terminals::fromRequest($request, $branch);
        if (! $terminal) {
            return response()->json(['message' => __('This device has no terminal of this branch: choose the terminal again.'), 'refused' => true], 422);
        }
        $shift = Shift::query()->find((int) $data['shift_id']);
        if (! $shift) {
            return response()->json(['message' => __('This sale belongs to another shift.'), 'refused' => true], 422);
        }
        try {
            ['order' => $order, 'duplicate' => $duplicate] = $orders->placeOffline($data, $branch, $request->user(), $shift, $terminal);
        } catch (OrderException $e) {
            return response()->json(['message' => $e->getMessage(), 'refused' => true], 422);
        }

        return response()->json([
            'ok' => true, 'id' => $order->id, 'number' => $order->number, 'offline_ref' => $order->offline_ref,
            'duplicate' => $duplicate, 'review' => (bool) $order->needs_review,
        ]);
    }

    /** This device takes a terminal of the branch (its token goes back once, to localStorage). */
    public function claim(Request $request, PosTerminal $terminal, Terminals $terminals): JsonResponse
    {
        $branch = self::branch($request);
        abort_if($terminal->branch_id !== $branch->id, 404);
        $data = $request->validate(['takeover' => 'nullable|boolean', 'device' => 'nullable|string|max:120']);
        try {
            ['terminal' => $terminal, 'token' => $token] = $terminals->claim($terminal, $request->user(), (string) ($data['device'] ?? ''), (bool) ($data['takeover'] ?? false));
        } catch (OrderException $e) {
            return response()->json(['message' => $e->getMessage(), 'claimed' => $terminal->isClaimed()], 422);
        }

        return response()->json(['ok' => true, 'id' => $terminal->id, 'name' => $terminal->name, 'code' => $terminal->code, 'token' => $token, 'seq' => $terminal->offline_seq]);
    }

    /** @return array{service:float, tax:float, tables:mixed, categories:mixed, items:mixed, terminals:mixed} */
    private static function catalogData(Branch $branch): array
    {
        $settings = Settings::all();
        $categories = MenuCatalog::forBranch($branch)->allDay()->categories(); // the cashier sells breakfast items at any hour

        return [
            'service' => (float) $settings['service_percent'], 'tax' => (float) $settings['tax_percent'],
            'tables' => DiningTable::query()->where('branch_id', $branch->id)->where('is_active', true)->orderBy('sort_order')->get(['id', 'name']),
            'categories' => $categories->map(fn (Category $c) => ['id' => $c->id, 'name' => tr($c->name, $c->name_en)])->values(),
            'items' => $categories->flatMap(fn (Category $c) => $c->items->map(fn (MenuItem $i) => [
                'id' => $i->id, 'category' => $c->id, 'name' => $i->displayName(), 'price' => (float) $i->price,
                'soldOut' => $i->is_sold_out, 'image' => $i->image ? '/storage/'.$i->image : null,
                'groups' => $i->optionGroups->map(fn ($g) => [
                    'id' => $g->id, 'name' => tr($g->name, $g->name_en), 'type' => $g->type, 'required' => $g->is_required, 'max' => $g->max_choices,
                    'options' => $g->options->map(fn ($o) => ['id' => $o->id, 'name' => tr($o->name, $o->name_en), 'price' => (float) $o->price, 'soldOut' => $o->is_sold_out, 'default' => $o->is_default])->values(),
                ])->values(),
            ]))->values(),
            'terminals' => PosTerminal::query()->where('branch_id', $branch->id)->where('is_active', true)->orderBy('code')->get()
                ->map(fn (PosTerminal $t) => ['id' => $t->id, 'name' => $t->name, 'code' => $t->code, 'claimed' => $t->isClaimed()])->values(),
        ];
    }

    /** @return array{id:int, opened_at:string, terminal:?int}|null */
    private static function shiftData(?Shift $shift): ?array
    {
        return $shift ? ['id' => $shift->id, 'opened_at' => $shift->opened_at->toIso8601String(), 'terminal' => $shift->terminal_id] : null;
    }

    /** @return array{brand:string, branch:string, phone:string, currency:string, url:string} the top of a receipt printed offline */
    private static function receiptHeader(Branch $branch): array
    {
        $s = Settings::all();

        return [
            'brand' => tr($s['brand_name'], $s['brand_name_en']) ?: (string) tenant()->name, 'branch' => tr($branch->name, $branch->name_en),
            'phone' => (string) $branch->phone, 'currency' => tr($s['currency'], $s['currency_en']), 'url' => tenant()->url(),
        ];
    }

    /** Places a POS order; with `pay` it is paid at once (takeaway), otherwise it stays open (table). */
    public function store(Request $request, OrderService $orders, ShiftService $shifts): JsonResponse
    {
        $branch = self::branch($request);
        $data = $request->validate([
            'client_uuid' => 'required|uuid',
            'type' => ['required', Rule::in(['dine_in', 'takeaway'])],
            'table_id' => ['nullable', 'integer'],
            'items' => 'required|array|min:1|max:'.OrderService::MAX_LINES,
            'items.*.item_id' => 'required|integer',
            'items.*.qty' => 'required|integer|min:1|max:'.OrderService::MAX_QTY,
            'items.*.option_ids' => 'array|max:30',
            'items.*.option_ids.*' => 'integer',
            'items.*.notes' => 'nullable|string|max:200',
            'notes' => 'nullable|string|max:500',
            'customer_name' => 'nullable|string|max:100',
            'customer_phone' => 'nullable|string|max:20',
            'discount' => 'nullable|numeric|min:0',
            'coupon' => 'nullable|string|max:30',
            'pay' => ['nullable', Rule::in([...ShiftService::METHODS, OrderService::SPLIT])],
        ] + self::voucherRules() + self::paymentRules());
        if (! empty($data['vouchers']) && ! self::corporateAllowed($request)) {
            return response()->json(['message' => __('You are not allowed to take corporate vouchers.')], 403);
        }
        if (! empty($data['vouchers']) && empty($data['pay'])) {
            return response()->json(['message' => __('Choose a payment method.')], 422);
        }
        if (($data['discount'] ?? 0) > 0 && ! $request->user()->can('pos.discount')) {
            return response()->json(['message' => __('You are not allowed to give discounts.')], 403);
        }
        if (trim((string) ($data['coupon'] ?? '')) !== '' && ! self::couponsAllowed()) {
            return response()->json(['message' => __('Vouchers are not in your plan.')], 403);
        }
        $table = $data['type'] === 'dine_in' && ! empty($data['table_id'])
            ? DiningTable::query()->where('branch_id', $branch->id)->find($data['table_id']) : null;
        if ($data['type'] === 'dine_in' && ! $table) {
            return response()->json(['message' => __('Choose a table.')], 422);
        }

        try {
            // paid at once, it is all or nothing: a refused payment (no open shift, short balance, a voucher) leaves no
            // order behind in the kitchen, and the cashier's retry with the same ticket places it once
            $terminal = Terminals::fromRequest($request, $branch);
            $run = function () use ($data, $branch, $table, $request, $orders, $shifts, $terminal) {
                $order = $orders->place(collect($data)->except(['vouchers', 'voucher_phone'])->all() + ['source' => 'pos', 'terminal_id' => $terminal?->id], $branch, $table, $request->user());
                if (! empty($data['pay']) && $order->payment_status !== 'paid') {
                    $orders->pay($order, $data['pay'], $request->user(), $shifts->current($request->user()), self::vouchers($data), $data['voucher_phone'] ?? null, $data['payments'] ?? []);
                }

                return $order;
            };
            $order = empty($data['pay']) ? $run() : DB::transaction($run);
        } catch (OrderException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'ok' => true, 'id' => $order->id, 'number' => $order->number, 'total' => $order->total, 'paid' => $order->payment_status === 'paid',
            'receipt_url' => $order->receiptUrl(), 'phone' => $order->customer_phone,
        ]);
    }

    /** Checks a voucher / coupon code against the cart (same rules as placing the order) and returns its discount. */
    public function coupon(Request $request, OrderService $orders): JsonResponse
    {
        $data = $request->validate([
            'coupon' => 'required|string|max:30',
            'items' => 'required|array|min:1|max:'.OrderService::MAX_LINES,
            'items.*.item_id' => 'required|integer',
            'items.*.qty' => 'required|integer|min:1|max:'.OrderService::MAX_QTY,
            'items.*.option_ids' => 'array|max:30',
            'items.*.option_ids.*' => 'integer',
            'customer_phone' => 'nullable|string|max:20',
            'discount' => 'nullable|numeric|min:0',
        ]);
        try {
            return response()->json($orders->quoteCoupon($data, self::branch($request)));
        } catch (OrderException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /** Staff can take voucher / coupon codes at the POS when the restaurant has the loyalty card or campaigns. */
    public static function couponsAllowed(): bool
    {
        return Entitlements::current()->allows('loyalty_stamps|campaigns');
    }

    /** Corporate vouchers at this POS: the plan has them and this user may redeem. */
    public static function corporateAllowed(Request $request): bool
    {
        return CorporateVouchers::enabled() && $request->user()->can('vouchers.redeem');
    }

    /** @return array<string,mixed> the vouchers sent with a payment: a code typed / scanned, or a linked voucher's id */
    private static function voucherRules(): array
    {
        return [
            'vouchers' => 'nullable|array|max:'.CorporateVouchers::MAX_PER_ORDER,
            'vouchers.*.code' => 'nullable|string|max:300',
            'vouchers.*.id' => 'nullable|integer',
            'vouchers.*.approval' => 'nullable|string|max:64',
            'voucher_phone' => 'nullable|string|max:20',
        ];
    }

    /** @return array<string,mixed> a split payment: its parts, each by its own method (cash with what was handed over) */
    private static function paymentRules(): array
    {
        return [
            'payments' => 'nullable|array|max:8',
            'payments.*.method' => ['required', Rule::in(OrderService::SPLIT_METHODS)],
            'payments.*.amount' => 'required|numeric|min:0.01|max:1000000',
            'payments.*.tendered' => 'nullable|numeric|min:0|max:1000000',
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

    public function pay(Request $request, Order $order, OrderService $orders, ShiftService $shifts): JsonResponse
    {
        abort_if($request->user()->branch_id && $request->user()->branch_id !== $order->branch_id, 404);
        $data = $request->validate(['method' => ['required', Rule::in([...ShiftService::METHODS, OrderService::SPLIT])], 'phone' => 'nullable|string|max:20']
            + self::voucherRules() + self::paymentRules());
        if (! empty($data['vouchers']) && ! self::corporateAllowed($request)) {
            return response()->json(['message' => __('You are not allowed to take corporate vouchers.')], 403);
        }
        $customer = null;
        // paying an open (table) order from the wallet: the cashier may name the guest now
        if ($data['method'] === WalletService::METHOD && ! $order->customer_id && ! empty($data['phone'])) {
            $customer = Customer::query()->where('phone', normalize_phone($data['phone']) ?? '')->first();
            if (! $customer) {
                return response()->json(['message' => __('No wallet for this mobile number.')], 422);
            }
        }
        try {
            // one transaction: a refused payment (not enough balance, already paid) leaves the order untouched
            DB::transaction(function () use ($order, $customer, $data, $orders, $request, $shifts) {
                if ($customer) {
                    $order->update(['customer_id' => $customer->id, 'customer_phone' => $customer->phone, 'customer_name' => $order->customer_name ?: $customer->name]);
                }
                $orders->pay($order, $data['method'], $request->user(), $shifts->current($request->user()), self::vouchers($data), $data['voucher_phone'] ?? null, $data['payments'] ?? []);
            });
        } catch (OrderException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'receipt_url' => $order->receiptUrl(), 'phone' => $order->fresh()->customer_phone]);
    }

    /** Open (unpaid, not cancelled) orders of the branch: the tables still to settle. */
    public function open(Request $request): JsonResponse
    {
        $branch = self::branch($request);
        $orders = Order::query()->where('branch_id', $branch->id)->where('payment_status', 'unpaid')->where('status', '!=', 'cancelled')
            ->with('table')->latest('id')->limit(50)->get();

        return response()->json(['orders' => $orders->map(fn (Order $o) => [
            'id' => $o->id, 'number' => $o->number, 'table' => $o->table?->name, 'type' => $o->type, 'status' => $o->statusLabel(),
            'total' => (float) $o->total, 'time' => local_time($o->created_at, 'h:i A'),
        ])]);
    }

    /** Second screen facing the guest: mirrors the cart through BroadcastChannel (same browser, no server). */
    public function display(): View
    {
        return view('dashboard.pos.display', ['settings' => Settings::all()]);
    }

    public static function branch(Request $request): Branch
    {
        if ($id = $request->user()->branch_id) {
            return Branch::query()->findOrFail($id);
        }
        if ($request->filled('branch')) {
            $request->session()->put('pos.branch', (int) $request->query('branch'));
        }
        $chosen = Branch::query()->where('is_active', true)->find((int) $request->session()->get('pos.branch'));

        return $chosen ?? MenuCatalog::defaultBranch();
    }
}
