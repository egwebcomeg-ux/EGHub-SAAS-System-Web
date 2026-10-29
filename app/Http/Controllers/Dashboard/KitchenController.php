<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\KitchenStation;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Entitlements;
use App\Services\Orders\OrderConfirmation;
use App\Services\Orders\OrderException;
use App\Services\Orders\OrderFlow;
use App\Services\Orders\OrderService;
use App\Support\PrepKind;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Kitchen display (KDS). Tickets oldest first, amber after 10 min, red after 20 (Filetto thresholds).
 * With stations, a screen shows only its station's lines; "ready" bumps those lines and the order turns ready
 * once every line is done. Scheduled orders join the queue 45 minutes before their time.
 */
class KitchenController extends Controller
{
    public const SCHEDULE_LEAD_MINUTES = 45;

    /** Tickets older than this leave the screen (closed by the board, or left for the manager). */
    public const STALE_HOURS = 12;

    public function screen(Request $request): View
    {
        $stations = Entitlements::current()->allows('kitchen_stations') ? KitchenStation::query()->orderBy('sort_order')->get() : collect();

        return view('dashboard.kitchen', [
            'stations' => $stations,
            'station' => $stations->firstWhere('id', (int) $request->query('station')),
            'branch' => PosController::branch($request),
        ]);
    }

    public function feed(Request $request): JsonResponse
    {
        $branch = PosController::branch($request);
        $station = $this->station($request);

        OrderFlow::kitchenSeen();
        // the last STALE_HOURS: an order forgotten since yesterday never pushes today's off the screen
        $orders = Order::query()->where('branch_id', $branch->id)->whereIn('status', ['new', 'preparing'])
            ->where(fn ($q) => $q->whereNull('scheduled_for')->where('created_at', '>=', now()->subHours(self::STALE_HOURS))
                ->orWhere(fn ($q) => $q->whereNotNull('scheduled_for')->where('scheduled_for', '<=', now()->addMinutes(self::SCHEDULE_LEAD_MINUTES))))
            ->with(['items', 'table'])->orderBy('id')->limit(60)->get();

        $tickets = $orders->map(function (Order $o) use ($station, $request) {
            // a station's lines, and the lines of categories left on "all screens" (no station), so nothing is lost
            $lines = $station ? $o->items->filter(fn (OrderItem $i) => $i->kitchen_station_id === null || (int) $i->kitchen_station_id === $station->id) : $o->items;
            // nothing (left) for this screen: its lines are all bumped while another station still works
            if ($lines->isEmpty() || ($station && $lines->every(fn (OrderItem $i) => $i->ready_at !== null))) {
                return null;
            }

            return [
                'id' => $o->id, 'number' => $o->number, 'status' => $o->status, 'type' => $o->type,
                'type_label' => Order::TYPES[$o->type][app()->getLocale() === 'en' ? 1 : 0] ?? $o->type,
                'table' => $o->table?->name, 'notes' => $o->notes, 'since' => ($o->preparing_at ?? $o->created_at)->toIso8601String(),
                'created' => $o->created_at->toIso8601String(), 'scheduled' => $o->scheduled_for?->toIso8601String(),
                // the guest pager: whether the guest has the page open, "Call" and the guest's pager ticket to print
                'pager' => PagerController::orderView($o, $request),
                'confirm' => OrderConfirmation::view($o),
                'items' => $lines->map(fn (OrderItem $i) => [
                    'id' => $i->id, 'name' => $i->name, 'qty' => $i->quantity, 'notes' => $i->notes, 'done' => $i->ready_at !== null,
                    'kind' => $i->prep_kind ?: PrepKind::FOOD,
                    'options' => collect($i->options ?? [])->pluck('name')->implode('، '),
                ])->values(),
            ];
        })->filter()->values();

        return response()->json(['max_id' => (int) Order::query()->where('branch_id', $branch->id)->max('id'), 'tickets' => $tickets]);
    }

    /**
     * start: new → preparing. ready: this station's lines done (only the food or the drinks with `kind`); the order is
     * ready when all lines are.
     */
    public function bump(Request $request, Order $order, OrderService $orders): JsonResponse
    {
        abort_if($request->user()->branch_id && $request->user()->branch_id !== $order->branch_id, 404);
        $data = $request->validate(['action' => 'required|in:start,ready', 'kind' => ['nullable', Rule::in(array_keys(PrepKind::KINDS))]]);
        $station = $this->station($request);

        try {
            if ($data['action'] === 'start' && $order->status === 'new') {
                $orders->transition($order, 'preparing', $request->user());
            }
            if ($data['action'] === 'ready') {
                $orders->markReady($order, $request->user(), $data['kind'] ?? null, $station?->id);
            }
        } catch (OrderException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'status' => $order->fresh()->status]);
    }

    private function station(Request $request): ?KitchenStation
    {
        if (! $request->filled('station') || ! Entitlements::current()->allows('kitchen_stations')) {
            return null;
        }

        return KitchenStation::query()->find((int) $request->input('station'));
    }
}
