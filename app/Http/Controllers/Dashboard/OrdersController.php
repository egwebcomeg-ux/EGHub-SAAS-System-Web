<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Reservation;
use App\Models\TableCall;
use App\Services\Audit;
use App\Services\Entitlements;
use App\Services\Orders\BusyMode;
use App\Services\Orders\DeliveryService;
use App\Services\Orders\ManagerApproval;
use App\Services\Orders\OrderConfirmation;
use App\Services\Orders\OrderException;
use App\Services\Orders\OrderService;
use App\Services\Orders\Pager;
use App\Services\Settings;
use App\Services\Wallet\WalletService;
use App\Support\PrepKind;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Live order board: columns new → preparing → ready (+ served today). The page polls /orders/feed every few
 * seconds (no websockets yet) and chimes when a new order arrives. Staff tied to a branch only see that branch.
 */
class OrdersController extends Controller
{
    public const POLL_SECONDS = 5;

    /** Cancelling an order is a manager's call: the same right that approves refunds, or that manager's PIN. */
    public const CANCEL_PERMISSION = 'pos.refund';

    /** How an order is paid on the board (no cashier screen): no balance, vouchers or credit without the POS. */
    public const BOARD_PAY_METHODS = ['cash', 'card', 'instapay'];

    public function board(Request $request, ManagerApproval $approvals): View
    {
        return view('dashboard.orders.board', [
            'poll' => self::POLL_SECONDS, 'busy' => BusyMode::state(), 'confirmations' => OrderConfirmation::enabled(),
            // staff who may not cancel alone pick the manager who approves with their PIN
            'cancelApprovers' => $request->user()->can(self::CANCEL_PERMISSION) ? [] : $approvals->approvers(self::CANCEL_PERMISSION)
                ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])->values()->all(),
        ]);
    }

    public function feed(Request $request): JsonResponse
    {
        $orders = $this->scoped($request)
            ->where(fn ($q) => $q->whereIn('status', ['new', 'preparing', 'ready', 'on_way'])
                ->orWhere(fn ($q) => $q->whereIn('status', ['served', 'cancelled'])->where('updated_at', '>=', now()->subHours(3))))
            // the newest 200, oldest first: orders nobody closed never push the new ones off the board
            ->with(['items', 'table', 'branch', 'driver'])->orderByDesc('id')->limit(200)->get()->sortBy('id')->values();

        $branch = $request->user()->branch_id;
        $calls = TableCall::query()->whereNull('handled_at')->when($branch, fn ($q) => $q->where('branch_id', $branch))
            ->with('table')->orderBy('id')->limit(50)->get();

        return response()->json([
            'max_id' => (int) $this->scoped($request)->max('id'),
            'busy' => BusyMode::state(),
            'orders' => $orders->map(fn (Order $o) => $this->present($o))->values(),
            'calls' => $calls->map(fn (TableCall $c) => [
                'id' => $c->id, 'kind' => $c->kind, 'label' => $c->kindLabel(), 'table' => $c->table?->name,
                'minutes' => (int) $c->created_at->diffInMinutes(now()),
            ])->values(),
            'reservations' => $this->pendingReservations($request),
            'counts' => self::counts($orders),
            // open orders older than KitchenController::STALE_HOURS: forgotten, waiting for someone to close or cancel them
            'stale' => $this->scoped($request)->whereIn('status', ['new', 'preparing', 'ready', 'on_way'])
                ->where('created_at', '<', now()->subHours(KitchenController::STALE_HOURS))->whereNull('scheduled_for')->count(),
            'drivers' => self::assignableDrivers($request),
        ]);
    }

    /**
     * The drivers the board can give delivery orders to (delivery plan feature and the assign permission), with the
     * branch each works for (null = every branch).
     *
     * @return list<array{id:int, name:string, branch_id:?int}>
     */
    public static function assignableDrivers(Request $request): array
    {
        if (! Entitlements::current()->allows('delivery_zones|online_ordering') || ! $request->user()->can('delivery.assign')) {
            return [];
        }

        return DeliveryService::drivers($request->user()->branch_id)
            ->map(fn ($d) => ['id' => $d->id, 'name' => $d->name, 'branch_id' => $d->branch_id])->values()->all();
    }

    /**
     * The quick filters on top of the board, counted over the open orders (not served or cancelled): all, in the
     * kitchen (new + preparing), ready (+ on the way), each order type, and orders with food / with drinks.
     *
     * @param  Collection<int,Order>  $orders
     * @return array<string,int>
     */
    public static function counts(Collection $orders): array
    {
        $open = $orders->whereIn('status', ['new', 'preparing', 'ready', 'on_way']);
        $counts = [
            'all' => $open->count(),
            'kitchen' => $open->whereIn('status', ['new', 'preparing'])->count(),
            'ready' => $open->whereIn('status', ['ready', 'on_way'])->count(),
        ];
        foreach (array_keys(Order::TYPES) as $type) {
            $counts[$type] = $open->where('type', $type)->count();
        }
        foreach (array_keys(PrepKind::KINDS) as $kind) {
            $counts[$kind] = $open->filter(fn (Order $o) => in_array($kind, $o->kinds(), true))->count();
        }
        // cash-on-delivery orders whose guest has not confirmed (yet), wants a change or asks to cancel
        $counts['confirm'] = $open->filter(fn (Order $o) => in_array(OrderConfirmation::state($o), OrderConfirmation::OPEN_STATES, true))->count();

        return $counts;
    }

    /** «اتأكد بالتليفون»: staff called the guest and the cash-on-delivery order is fine. */
    public function confirmByPhone(Request $request, Order $order, OrderConfirmation $confirmation): JsonResponse
    {
        abort_unless($this->visible($request, $order), 404);
        if (! $confirmation->confirmByPhone($order, $request->user())) {
            return response()->json(['message' => __('This order has no confirmation to mark.')], 422);
        }

        return response()->json(['ok' => true, 'order' => $this->present($order->fresh()->load(['items', 'table', 'branch', 'driver']))]);
    }

    /**
     * «اتدفع»: a takeaway, call-center or kiosk order paid at the counter of a restaurant without the cashier screen
     * (with it, payments go through the POS, its drawer and shift). Table orders are paid with the table's bill.
     */
    public function pay(Request $request, Order $order, OrderService $service): JsonResponse
    {
        abort_unless($this->visible($request, $order), 404);
        abort_unless(self::paysOnBoard(), 404);
        $data = $request->validate(['method' => ['required', Rule::in(self::BOARD_PAY_METHODS)]]);
        if ($order->dining_table_id) {
            return response()->json(['message' => __('A table order is paid with the table\'s bill (the Tables tab).')], 422);
        }
        try {
            $service->pay($order, $data['method'], $request->user(), null);
        } catch (OrderException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        Audit::record('order.paid_on_board', '#'.$order->number, ['method' => $data['method'], 'total' => $order->total]);

        return response()->json(['ok' => true, 'order' => $this->present($order->fresh()->load(['items', 'table', 'branch', 'driver']))]);
    }

    /** Without the cashier screen the board takes the payment of orders off a table. */
    public static function paysOnBoard(): bool
    {
        return ! Entitlements::current()->allows('pos');
    }

    /** "Food ready" / "drinks ready" on the board: marks that part; the order turns ready when every part is. */
    public function partReady(Request $request, Order $order, OrderService $service): JsonResponse
    {
        abort_unless($this->visible($request, $order), 404);
        $kind = $request->validate(['kind' => ['required', Rule::in(array_keys(PrepKind::KINDS))]])['kind'];
        try {
            $service->markReady($order, $request->user(), $kind);
        } catch (OrderException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'order' => $this->present($order->fresh()->load(['items', 'table', 'branch', 'driver']))]);
    }

    /** Bookings waiting for confirmation (reservations feature), shown in the strip on top of the board. */
    private function pendingReservations(Request $request): array
    {
        if (! Entitlements::current()->allows('reservations') || ! $request->user()->can('reservations.manage')) {
            return [];
        }
        $branch = $request->user()->branch_id;

        return Reservation::query()->where('status', 'pending')->where('starts_at', '>=', now()->subHours(2))
            ->when($branch, fn ($q) => $q->where('branch_id', $branch))->orderBy('starts_at')->limit(20)->get()
            ->map(fn (Reservation $r) => [
                'id' => $r->id, 'name' => $r->name, 'phone' => $r->phone, 'party' => $r->party_size, 'notes' => $r->notes,
                'when' => local_time($r->starts_at, local_at($r->starts_at)->isToday() ? 'h:i A' : 'd/m h:i A'),
            ])->values()->all();
    }

    /**
     * Busy mode from the board: `mode` off | minutes (30, 60…) | time ("21:00" today, or tomorrow when past), with an
     * optional message guests read on the menu. While busy, online / takeaway / delivery ordering is paused.
     */
    public function busy(Request $request): JsonResponse
    {
        $data = $request->validate([
            'mode' => ['required', Rule::in(['off', 'minutes', 'time'])],
            'minutes' => 'required_if:mode,minutes|nullable|integer|min:5|max:'.(BusyMode::MAX_HOURS * 60),
            'time' => 'required_if:mode,time|nullable|date_format:H:i',
            'message' => 'nullable|string|max:200',
        ]);
        if ($data['mode'] === 'off') {
            BusyMode::stop();
            Audit::record('busy.off');
        } else {
            $until = $data['mode'] === 'minutes'
                ? now()->addMinutes((int) $data['minutes'])
                : local_at()->setTimeFromTimeString($data['time'].':00');
            if ($until->lte(now())) {
                $until->addDay();
            }
            $until = $until->utc();
            BusyMode::start($until, (string) ($data['message'] ?? ''));
            Audit::record('busy.on', local_time($until), ['until' => $until->toIso8601String()]);
        }

        return response()->json(['ok' => true, 'busy' => BusyMode::state()]);
    }

    /** A waiter answered the table: the call disappears from every screen. */
    public function handleCall(Request $request, TableCall $call): JsonResponse
    {
        abort_if($request->user()->branch_id && $request->user()->branch_id !== $call->branch_id, 404);
        if (! $call->handled_at) {
            $call->update(['handled_at' => now(), 'handled_by' => $request->user()->id]);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Moves an order along. Cancelling needs a manager (their right, or their PIN typed on this device), and an order
     * paid in cash, by card or the like is never cancelled here: its money goes back through a refund, so the drawer
     * and the shift report keep the trace. An order paid from the guest's balance may be cancelled: the balance comes back.
     */
    public function transition(Request $request, Order $order, OrderService $service, ManagerApproval $approvals): JsonResponse
    {
        abort_unless($this->visible($request, $order), 404);
        $data = $request->validate([
            'status' => ['required', Rule::in(array_keys(Order::STATUSES))], 'reason' => 'nullable|string|max:200',
            'approver_id' => 'nullable|integer', 'pin' => 'nullable|string|max:12',
        ]);
        $cancel = $data['status'] === 'cancelled';
        try {
            if ($cancel && $order->payment_status === 'paid' && $order->payment_method !== WalletService::METHOD) {
                throw new OrderException(__('This order is paid: give the money back with a refund from the cashier screen (a manager approves).'));
            }
            $approver = $cancel
                ? $approvals->approve($request->user(), self::CANCEL_PERMISSION, isset($data['approver_id']) ? (int) $data['approver_id'] : null, $data['pin'] ?? null, $order->reference())
                : null;
            $service->transition($order, $data['status'], $request->user(), $data['reason'] ?? null);
        } catch (OrderException $e) {
            return response()->json(['message' => $e->getMessage(), 'approval' => $cancel && ! $request->user()->can(self::CANCEL_PERMISSION)], 422);
        }
        if ($cancel) {
            Audit::record('order.cancelled', '#'.$order->number, ['id' => $order->id, 'reason' => $data['reason'] ?? null, 'approved_by' => $approver?->name]);
        }

        return response()->json(['ok' => true, 'order' => $this->present($order->load(['items', 'table', 'branch', 'driver']))]);
    }

    /**
     * 80mm receipt for the thermal printer (customer copy, or kitchen copy without prices). With the guest pager the
     * customer copy carries the pager QR ("scan so we can call you"), and copy=pager prints only the number and that
     * QR (a takeaway ticket to hand over).
     */
    public function receipt(Request $request, Order $order): View
    {
        abort_unless($this->visible($request, $order), 404);
        $copy = $request->query('copy');
        // with only the pager right (the kitchen), only the guest's pager ticket prints: no prices, no customer
        abort_unless($copy === 'pager' || $request->user()->canAny(['orders.view', 'pos.use']), 403);
        $pager = Pager::enabled() && Pager::forOrder($order) && ! Pager::isDone($order) ? $order->pagerUrl() : null;
        if ($copy === 'pager') {
            abort_unless($pager, 404);

            return view('dashboard.pager-ticket', [
                'number' => $order->number, 'url' => $pager, 'branch' => $order->branch, 'settings' => Settings::all(), 'at' => $order->created_at,
            ]);
        }

        return view('dashboard.orders.receipt', [
            'order' => $order->load(['items', 'table', 'branch', 'driver']),
            'settings' => Settings::all(),
            'kitchen' => $copy === 'kitchen',
            'pagerUrl' => $pager,
        ]);
    }

    private function scoped(Request $request)
    {
        $branch = $request->user()->branch_id;

        return Order::query()->when($branch, fn ($q) => $q->where('branch_id', $branch));
    }

    private function visible(Request $request, Order $order): bool
    {
        return ! $request->user()->branch_id || $request->user()->branch_id === $order->branch_id;
    }

    /** @return array<string,mixed> */
    private function present(Order $o): array
    {
        return [
            'id' => $o->id, 'number' => $o->number, 'offline_ref' => $o->offline_ref, 'status' => $o->status, 'status_label' => $o->statusLabel(),
            'type' => $o->type, 'type_label' => Order::TYPES[$o->type][app()->getLocale() === 'en' ? 1 : 0] ?? $o->type,
            'source' => $o->source, 'table' => $o->table?->name, 'branch' => $o->branch ? tr($o->branch->name, $o->branch->name_en) : null,
            'customer' => trim($o->customer_name.' '.$o->customer_phone), 'notes' => $o->notes,
            'scheduled' => $o->scheduled_for ? local_time($o->scheduled_for, 'd/m h:i A') : null,
            'address' => $o->delivery_address ? trim(($o->delivery_address['zone'] ?? '').' — '.($o->delivery_address['text'] ?? ''), ' —') : null,
            'total' => money($o->total), 'created_at' => $o->created_at->toIso8601String(),
            'time' => local_time($o->created_at, 'h:i A'),
            'reason' => $o->status === 'cancelled' ? $o->cancelReasonLabel() : null,
            // on_way → ready is how a failed delivery comes back (the driver reports it), not a board button
            'next' => array_values(array_filter(Order::TRANSITIONS[$o->status] ?? [], fn ($s) => $o->canMoveTo($s) && ! ($o->status === 'on_way' && $s === 'ready'))),
            'branch_id' => $o->branch_id, 'driver_id' => $o->driver_id, 'driver' => $o->driver?->name,
            'delivery_status' => $o->delivery_status, 'delivery_label' => $o->deliveryStatusLabel(),
            'delivery_reason' => $o->delivery_status === 'failed' ? $o->delivery_fail_reason : null,
            'pager' => PagerController::orderView($o, request()),
            'confirm' => OrderConfirmation::view($o),
            'kinds' => $o->kinds(),
            'paid' => $o->payment_status === 'paid',
            'can_pay' => $o->payment_status !== 'paid' && $o->status !== 'cancelled' && ! $o->dining_table_id && self::paysOnBoard(),
            'parts' => collect($o->parts())->map(fn (bool $ready, string $kind) => ['kind' => $kind, 'label' => PrepKind::label($kind), 'ready' => $ready])->values(),
            'items' => $o->items->map(fn ($i) => [
                'name' => $i->name, 'qty' => $i->quantity, 'notes' => $i->notes, 'kind' => $i->prep_kind ?: PrepKind::FOOD,
                'options' => collect($i->options ?? [])->pluck('name')->implode('، '),
            ])->values(),
        ];
    }
}
