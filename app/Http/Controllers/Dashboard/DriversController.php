<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\DriverLocation;
use App\Models\Order;
use App\Models\User;
use App\Services\Orders\DeliveryService;
use App\Services\Orders\DriverStats;
use App\Services\Orders\OrderException;
use App\Services\Orders\ShiftService;
use App\Services\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The drivers page for the manager / dispatcher: a live map and list of the drivers with their current orders, when
 * each was last seen and today's numbers (delivered, failed, minutes to the door, cash held), the delivery orders
 * still waiting for a driver (assign / reassign), the cash each driver holds with "settled", a last-7-days table
 * and the trail of a delivered order drawn on the map. Polls /drivers/feed (no websockets). Staff tied to a branch
 * only see that branch.
 */
class DriversController extends Controller
{
    public const POLL_SECONDS = 20;

    /** Delivered orders of today listed for "show the trail". */
    public const RECENT_TRAILS = 30;

    public function index(Request $request): View
    {
        $branch = $request->user()->branch_id;
        $center = Branch::query()->whereNotNull('lat')->when($branch, fn ($q) => $q->whereKey($branch))->first();
        $drivers = DeliveryService::drivers($branch);

        return view('dashboard.drivers', [
            'poll' => self::POLL_SECONDS,
            'center' => $center ? [(float) $center->lat, (float) $center->lng] : null,
            'canAssign' => $request->user()->can('delivery.assign'),
            'canSettle' => $request->user()->can('delivery.drivers'),
            'week' => (new DriverStats(local_day_start(local_at()->subDays(6)), now(), $branch))->perDriver($drivers),
        ]);
    }

    public function feed(Request $request): JsonResponse
    {
        $user = $request->user();
        $branch = $user->branch_id;
        $drivers = DeliveryService::drivers($branch);
        $active = Order::query()->whereIn('driver_id', $drivers->modelKeys())->whereIn('delivery_status', ['assigned', 'picked_up'])
            ->whereNotIn('status', ['served', 'cancelled'])->when($branch, fn ($q) => $q->where('branch_id', $branch))
            ->orderBy('driver_assigned_at')->get()->groupBy('driver_id');
        $locations = DriverLocation::query()->whereIn('user_id', $drivers->modelKeys())->get()->keyBy('user_id');
        $canSettle = $user->can('delivery.drivers');
        $today = collect((new DriverStats(local_day_start(), now(), $branch))->perDriver($drivers))->keyBy('id');
        $holding = Order::query()->whereIn('driver_id', $drivers->modelKeys())->where('delivery_status', 'delivered')->where('status', 'served')
            ->where('cash_collected', '>', 0)->whereNull('cash_settled_at')->when($branch, fn ($q) => $q->where('branch_id', $branch))
            ->selectRaw('driver_id, count(*) as orders, sum(cash_collected) as amount')->groupBy('driver_id')->get()->keyBy('driver_id');

        $waiting = Order::query()->where('type', 'delivery')->whereNotIn('status', ['served', 'cancelled'])
            ->where(fn ($q) => $q->whereNull('driver_id')->orWhereIn('delivery_status', ['assigned', 'failed']))
            ->when($branch, fn ($q) => $q->where('branch_id', $branch))
            ->with('driver')->orderBy('id')->limit(100)->get();
        // today's delivered orders, latest first: the manager picks one to see its trail on the map
        $recent = Order::query()->whereIn('driver_id', $drivers->modelKeys())->where('delivery_status', 'delivered')
            ->where('delivered_at', '>=', local_day_start())->when($branch, fn ($q) => $q->where('branch_id', $branch))
            ->with('driver')->orderByDesc('delivered_at')->limit(self::RECENT_TRAILS)->get();

        return response()->json([
            'drivers' => $drivers->map(function (User $d) use ($active, $locations, $today, $holding, $canSettle) {
                $loc = $locations->get($d->id);
                $stats = $today->get($d->id) ?? [];

                return [
                    'id' => $d->id, 'name' => $d->name, 'phone' => $d->phone,
                    'orders' => ($active->get($d->id) ?? collect())->map(fn (Order $o) => [
                        'id' => $o->id, 'number' => $o->number, 'delivery_status' => $o->delivery_status, 'label' => $o->deliveryStatusLabel(),
                        'address' => trim(($o->delivery_address['zone'] ?? '').' — '.($o->delivery_address['text'] ?? ''), ' —'),
                        'assigned_at_iso' => $o->driver_assigned_at?->toIso8601String(),
                    ])->values(),
                    'location' => $loc ? ['lat' => $loc->lat, 'lng' => $loc->lng, 'time' => local_time($loc->recorded_at, 'h:i A'),
                        'minutes' => (int) $loc->recorded_at->diffInMinutes(now())] : null,
                    'delivered_today' => (int) ($stats['delivered'] ?? 0),
                    'failed_today' => (int) ($stats['failed'] ?? 0),
                    'avg_minutes' => $stats['avg_minutes'] ?? null,
                    'collected_today' => $canSettle ? round((float) ($stats['cash'] ?? 0), 2) : null,
                    'other_today' => $canSettle ? round((float) ($stats['other'] ?? 0), 2) : null,
                    'holding' => $canSettle ? round((float) ($holding->get($d->id)->amount ?? 0), 2) : null,
                    'holding_orders' => $canSettle ? (int) ($holding->get($d->id)->orders ?? 0) : null,
                ];
            })->values(),
            'waiting' => $waiting->map(fn (Order $o) => [
                'id' => $o->id, 'number' => $o->number, 'status' => $o->status, 'status_label' => $o->statusLabel(),
                'delivery_status' => $o->delivery_status, 'delivery_label' => $o->deliveryStatusLabel(),
                'driver_id' => $o->delivery_status === 'assigned' ? $o->driver_id : null, 'driver' => $o->driver?->name,
                'reason' => $o->delivery_status === 'failed' ? $o->delivery_fail_reason : null,
                'customer' => trim($o->customer_name.' '.$o->customer_phone),
                'address' => trim(($o->delivery_address['zone'] ?? '').' — '.($o->delivery_address['text'] ?? ''), ' —'),
                'total' => money($o->total), 'time' => local_time($o->created_at, 'h:i A'),
            ])->values(),
            'recent' => $recent->map(fn (Order $o) => [
                'id' => $o->id, 'number' => $o->number, 'driver' => $o->driver?->name,
                'time' => $o->delivered_at ? local_time($o->delivered_at, 'h:i A') : null,
                'minutes' => $o->picked_up_at && $o->delivered_at ? (int) $o->picked_up_at->diffInMinutes($o->delivered_at) : null,
                'method' => $o->delivery_payment_method,
            ])->values(),
        ]);
    }

    /** The stored trail of one delivered order of this restaurant (and this branch, for staff tied to one), with its address pin. */
    public function trail(Request $request, Order $order): JsonResponse
    {
        abort_if($request->user()->branch_id && $request->user()->branch_id !== $order->branch_id, 404);
        abort_unless($order->type === 'delivery' && $order->driver_id, 404);
        $address = (array) ($order->delivery_address ?? []);

        return response()->json([
            'id' => $order->id, 'number' => $order->number, 'driver' => $order->driver?->name,
            'points' => DeliveryService::trail($order),
            'destination' => isset($address['lat'], $address['lng']) ? ['lat' => (float) $address['lat'], 'lng' => (float) $address['lng']] : null,
            'picked_up_at' => $order->picked_up_at ? local_time($order->picked_up_at, 'h:i A') : null,
            'delivered_at' => $order->delivered_at ? local_time($order->delivered_at, 'h:i A') : null,
        ]);
    }

    /** Gives a delivery order to a driver (or takes it back with driver_id empty). */
    public function assign(Request $request, Order $order, DeliveryService $delivery): JsonResponse
    {
        abort_if($request->user()->branch_id && $request->user()->branch_id !== $order->branch_id, 404);
        $data = $request->validate([
            'driver_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('tenant_id', tenant()->getTenantKey())],
        ]);
        $driver = ! empty($data['driver_id']) ? DeliveryService::driverQuery()->find((int) $data['driver_id']) : null;
        if (! empty($data['driver_id']) && ! $driver) {
            return response()->json(['message' => __('Choose an active driver.')], 422);
        }
        try {
            $delivery->assign($order, $driver, $request->user());
        } catch (OrderException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'driver' => $driver?->name, 'delivery_status' => $order->delivery_status]);
    }

    /** The driver handed in the cash they collected: unpaid orders are paid in cash into the manager's shift. */
    public function settle(Request $request, User $driver, DeliveryService $delivery, ShiftService $shifts): JsonResponse
    {
        abort_unless($driver->hasRole('driver'), 404);
        try {
            $result = $delivery->settle($driver, $request->user(), $shifts->current($request->user()), $request->user()->branch_id);
        } catch (OrderException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $currency = tr(Settings::get('currency'), Settings::get('currency_en'));

        return response()->json(['ok' => true, 'message' => __(':n orders settled: :amount.', ['n' => $result['orders'], 'amount' => money($result['amount']).' '.$currency])] + $result);
    }
}
