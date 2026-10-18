<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Reservation;
use App\Models\TableCall;
use App\Services\Audit;
use App\Services\Entitlements;
use App\Services\Orders\BusyMode;
use App\Services\Orders\OrderException;
use App\Services\Orders\OrderService;
use App\Services\Settings;
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

    public function board(): View
    {
        return view('dashboard.orders.board', ['poll' => self::POLL_SECONDS, 'busy' => BusyMode::state()]);
    }

    public function feed(Request $request): JsonResponse
    {
        $orders = $this->scoped($request)
            ->where(fn ($q) => $q->whereIn('status', ['new', 'preparing', 'ready', 'on_way'])
                ->orWhere(fn ($q) => $q->whereIn('status', ['served', 'cancelled'])->where('updated_at', '>=', now()->subHours(3))))
            ->with(['items', 'table', 'branch'])->orderBy('id')->limit(200)->get();

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
        ]);
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

        return $counts;
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

        return response()->json(['ok' => true, 'order' => $this->present($order->fresh()->load(['items', 'table', 'branch']))]);
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
                'when' => local_time($r->starts_at, $r->starts_at->isToday() ? 'h:i A' : 'd/m h:i A'),
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
                : now()->setTimeFromTimeString($data['time'].':00');
            if ($until->lte(now())) {
                $until->addDay();
            }
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

    public function transition(Request $request, Order $order, OrderService $service): JsonResponse
    {
        abort_unless($this->visible($request, $order), 404);
        $data = $request->validate(['status' => ['required', Rule::in(array_keys(Order::STATUSES))], 'reason' => 'nullable|string|max:200']);
        try {
            $service->transition($order, $data['status'], $request->user(), $data['reason'] ?? null);
        } catch (OrderException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        if ($data['status'] === 'cancelled') {
            Audit::record('order.cancelled', '#'.$order->number, ['id' => $order->id, 'reason' => $data['reason'] ?? null]);
        }

        return response()->json(['ok' => true, 'order' => $this->present($order->load(['items', 'table', 'branch']))]);
    }

    /** 80mm receipt for the thermal printer (customer copy, or kitchen copy without prices). */
    public function receipt(Request $request, Order $order): View
    {
        abort_unless($this->visible($request, $order), 404);

        return view('dashboard.orders.receipt', [
            'order' => $order->load(['items', 'table', 'branch']),
            'settings' => Settings::all(),
            'kitchen' => $request->query('copy') === 'kitchen',
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
            'id' => $o->id, 'number' => $o->number, 'status' => $o->status, 'status_label' => $o->statusLabel(),
            'type' => $o->type, 'type_label' => Order::TYPES[$o->type][app()->getLocale() === 'en' ? 1 : 0] ?? $o->type,
            'source' => $o->source, 'table' => $o->table?->name, 'branch' => $o->branch ? tr($o->branch->name, $o->branch->name_en) : null,
            'customer' => trim($o->customer_name.' '.$o->customer_phone), 'notes' => $o->notes,
            'scheduled' => $o->scheduled_for ? local_time($o->scheduled_for, 'd/m h:i A') : null,
            'address' => $o->delivery_address ? trim(($o->delivery_address['zone'] ?? '').' — '.($o->delivery_address['text'] ?? ''), ' —') : null,
            'total' => money($o->total), 'created_at' => $o->created_at->toIso8601String(),
            'time' => local_time($o->created_at, 'h:i A'),
            'reason' => $o->status === 'cancelled' ? $o->cancelReasonLabel() : null,
            'next' => array_values(array_filter(Order::TRANSITIONS[$o->status] ?? [], fn ($s) => $o->canMoveTo($s))),
            'kinds' => $o->kinds(),
            'parts' => collect($o->parts())->map(fn (bool $ready, string $kind) => ['kind' => $kind, 'label' => PrepKind::label($kind), 'ready' => $ready])->values(),
            'items' => $o->items->map(fn ($i) => [
                'name' => $i->name, 'qty' => $i->quantity, 'notes' => $i->notes, 'kind' => $i->prep_kind ?: PrepKind::FOOD,
                'options' => collect($i->options ?? [])->pluck('name')->implode('، '),
            ])->values(),
        ];
    }
}
