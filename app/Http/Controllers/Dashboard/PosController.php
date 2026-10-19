<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Customer;
use App\Models\DiningTable;
use App\Models\MenuItem;
use App\Models\Order;
use App\Services\Corporate\CorporateVouchers;
use App\Services\Entitlements;
use App\Services\Menu\MenuCatalog;
use App\Services\Orders\OrderException;
use App\Services\Orders\OrderService;
use App\Services\Orders\ShiftService;
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
        $catalog = MenuCatalog::forBranch($branch)->allDay(); // the cashier sells breakfast items at any hour
        $categories = $catalog->categories();
        $settings = Settings::all();
        $shift = $shifts->current($request->user());

        return view('dashboard.pos.screen', [
            'branch' => $branch,
            'branches' => $request->user()->branch_id ? collect([$branch]) : Branch::query()->where('is_active', true)->orderBy('sort_order')->get(),
            'shift' => $shift,
            'needsShift' => Entitlements::current()->allows('shifts') && ! $shift,
            'data' => [
                'currency' => tr($settings['currency'], $settings['currency_en']),
                'service' => (float) $settings['service_percent'], 'tax' => (float) $settings['tax_percent'],
                'canDiscount' => $request->user()->can('pos.discount'),
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
                'urls' => [
                    'order' => route('dash.pos.order'), 'pay' => url('/pos/orders/__ID__/pay'), 'open' => route('dash.pos.open'),
                    'receipt' => url('/orders/__ID__/receipt'), 'display' => route('dash.pos.display'),
                ] + (self::couponsAllowed() ? ['coupon' => route('dash.pos.coupon')] : []) + (WalletService::enabled() ? [
                    'walletLookup' => route('dash.wallet.lookup'), 'topup' => route('dash.wallet.topup'), 'giftSell' => route('dash.wallet.gift.sell'),
                ] : []) + (self::corporateAllowed($request) ? [
                    'corporateCheck' => route('dash.pos.corporate.check'), 'corporateLink' => route('dash.pos.corporate.link'),
                    'corporateException' => url('/pos/vouchers/__ID__/exception'),
                ] : []),
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
            ],
        ]);
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
            'pay' => ['nullable', Rule::in(ShiftService::METHODS)],
        ] + self::voucherRules());
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
            // with corporate vouchers it is all or nothing: a refused voucher leaves no order behind
            $run = function () use ($data, $branch, $table, $request, $orders, $shifts) {
                $order = $orders->place(collect($data)->except(['vouchers', 'voucher_phone'])->all() + ['source' => 'pos'], $branch, $table, $request->user());
                if (! empty($data['pay']) && $order->payment_status !== 'paid') {
                    $orders->pay($order, $data['pay'], $request->user(), $shifts->current($request->user()), self::vouchers($data), $data['voucher_phone'] ?? null);
                }

                return $order;
            };
            $order = empty($data['vouchers']) ? $run() : DB::transaction($run);
        } catch (OrderException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'id' => $order->id, 'number' => $order->number, 'total' => $order->total, 'paid' => $order->payment_status === 'paid']);
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
        $data = $request->validate(['method' => ['required', Rule::in(ShiftService::METHODS)], 'phone' => 'nullable|string|max:20'] + self::voucherRules());
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
                $orders->pay($order, $data['method'], $request->user(), $shifts->current($request->user()), self::vouchers($data), $data['voucher_phone'] ?? null);
            });
        } catch (OrderException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true]);
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
