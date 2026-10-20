<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\DriverLocation;
use App\Models\Order;
use App\Models\User;
use App\Services\Orders\DeliveryService;
use App\Services\Orders\OrderException;
use App\Services\Orders\ShiftService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The drivers page for the manager / dispatcher: a live map and list of the drivers with their current orders and
 * when each was last seen, the delivery orders still waiting for a driver (assign / reassign), and the cash each
 * driver holds with "settled". Polls /drivers/feed (no websockets). Staff tied to a branch only see that branch.
 */
class DriversController extends Controller
{
    public const POLL_SECONDS = 20;

    public function index(Request $request): View
    {
        $branch = $request->user()->branch_id;
        $center = Branch::query()->whereNotNull('lat')->when($branch, fn ($q) => $q->whereKey($branch))->first();

        return view('dashboard.drivers', [
            'poll' => self::POLL_SECONDS,
            'center' => $center ? [(float) $center->lat, (float) $center->lng] : null,
            'canAssign' => $request->user()->can('delivery.assign'),
            'canSettle' => $request->user()->can('delivery.drivers'),
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
        $today = Order::query()->whereIn('driver_id', $drivers->modelKeys())->where('delivery_status', 'delivered')
            ->where('delivered_at', '>=', today())->when($branch, fn ($q) => $q->where('branch_id', $branch))
            ->selectRaw('driver_id, count(*) as delivered, coalesce(sum(cash_collected), 0) as collected')->groupBy('driver_id')->get()->keyBy('driver_id');
        $holding = Order::query()->whereIn('driver_id', $drivers->modelKeys())->where('delivery_status', 'delivered')->where('status', 'served')
            ->where('cash_collected', '>', 0)->whereNull('cash_settled_at')->when($branch, fn ($q) => $q->where('branch_id', $branch))
            ->selectRaw('driver_id, count(*) as orders, sum(cash_collected) as amount')->groupBy('driver_id')->get()->keyBy('driver_id');

        $waiting = Order::query()->where('type', 'delivery')->whereNotIn('status', ['served', 'cancelled'])
            ->where(fn ($q) => $q->whereNull('driver_id')->orWhereIn('delivery_status', ['assigned', 'failed']))
            ->when($branch, fn ($q) => $q->where('branch_id', $branch))
            ->with('driver')->orderBy('id')->limit(100)->get();

        return response()->json([
            'drivers' => $drivers->map(function (User $d) use ($active, $locations, $today, $holding, $canSettle) {
                $loc = $locations->get($d->id);

                return [
                    'id' => $d->id, 'name' => $d->name, 'phone' => $d->phone,
                    'orders' => ($active->get($d->id) ?? collect())->map(fn (Order $o) => [
                        'id' => $o->id, 'number' => $o->number, 'delivery_status' => $o->delivery_status, 'label' => $o->deliveryStatusLabel(),
                        'address' => trim(($o->delivery_address['zone'] ?? '').' — '.($o->delivery_address['text'] ?? ''), ' —'),
                    ])->values(),
                    'location' => $loc ? ['lat' => $loc->lat, 'lng' => $loc->lng, 'time' => local_time($loc->recorded_at, 'h:i A'),
                        'minutes' => (int) $loc->recorded_at->diffInMinutes(now())] : null,
                    'delivered_today' => (int) ($today->get($d->id)->delivered ?? 0),
                    'collected_today' => $canSettle ? round((float) ($today->get($d->id)->collected ?? 0), 2) : null,
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

        return response()->json(['ok' => true, 'message' => __(':n orders settled: :amount.', ['n' => $result['orders'], 'amount' => money($result['amount'])])] + $result);
    }
}
