<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\DriverLocation;
use App\Models\Order;
use App\Services\Orders\DeliveryService;
use App\Services\Orders\OrderException;
use App\Services\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The delivery driver's screen (/driver): their own active orders with big buttons — picked up, delivered, failed —
 * and, while an order is out for delivery, the phone's position sent every DeliveryService::PING_SECONDS. Every
 * action is a JSON endpoint as well, so the driver app can wrap the same routes (session auth).
 */
class DriverController extends Controller
{
    public function screen(Request $request): Response
    {
        $settings = Settings::all();

        return response()->view('dashboard.driver', [
            'orders' => $this->orders($request)->getData(true)['orders'],
            'ping' => DeliveryService::PING_SECONDS,
            'currency' => tr($settings['currency'], $settings['currency_en']),
        ])->header('Permissions-Policy', 'camera=(), microphone=(), geolocation=(self)'); // the rest of the site keeps geolocation off
    }

    /** The driver's orders still to deliver, oldest first. */
    public function orders(Request $request): JsonResponse
    {
        $orders = DeliveryService::activeFor($request->user())->with(['items', 'branch'])->orderBy('driver_assigned_at')->orderBy('id')->get();
        $location = DriverLocation::query()->where('user_id', $request->user()->id)->first();

        return response()->json([
            'orders' => $orders->map(fn (Order $o) => self::present($o))->values()->all(),
            'sharing' => $orders->contains(fn (Order $o) => $o->delivery_status === 'picked_up'),
            'last_location_at' => $location?->recorded_at->toIso8601String(),
            'ping' => DeliveryService::PING_SECONDS,
        ]);
    }

    public function pickUp(Request $request, Order $order, DeliveryService $delivery): JsonResponse
    {
        return $this->act($request, $order, fn () => $delivery->pickUp($order, $request->user()));
    }

    public function deliver(Request $request, Order $order, DeliveryService $delivery): JsonResponse
    {
        return $this->act($request, $order, fn () => $delivery->deliver($order, $request->user()));
    }

    public function fail(Request $request, Order $order, DeliveryService $delivery): JsonResponse
    {
        $reason = (string) $request->validate(['reason' => 'required|string|max:200'])['reason'];

        return $this->act($request, $order, fn () => $delivery->fail($order, $request->user(), $reason));
    }

    /** The phone's position while an order is out for delivery (refused otherwise). */
    public function location(Request $request, DeliveryService $delivery): JsonResponse
    {
        $data = $request->validate([
            'lat' => 'required|numeric|between:-90,90',
            'lng' => 'required|numeric|between:-180,180',
            'accuracy' => 'nullable|numeric|min:0|max:100000',
        ]);
        try {
            $result = $delivery->recordLocation($request->user(), (float) $data['lat'], (float) $data['lng'],
                isset($data['accuracy']) ? (int) round((float) $data['accuracy']) : null);
        } catch (OrderException $e) {
            return response()->json(['message' => $e->getMessage(), 'sharing' => false], 409);
        }

        return response()->json(['ok' => true, 'sharing' => true] + $result);
    }

    private function act(Request $request, Order $order, \Closure $action): JsonResponse
    {
        abort_unless($order->driver_id === $request->user()->id, 404); // another driver's order does not exist for this one
        try {
            $action();
        } catch (OrderException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        $order->refresh()->load(['items', 'branch']);

        return response()->json(['ok' => true, 'order' => self::present($order)]);
    }

    /** @return array<string,mixed> */
    public static function present(Order $o): array
    {
        $address = (array) ($o->delivery_address ?? []);
        $collect = $o->amountToCollect();

        return [
            'id' => $o->id, 'number' => $o->number, 'status' => $o->status, 'status_label' => $o->statusLabel(),
            'delivery_status' => $o->delivery_status, 'delivery_label' => $o->deliveryStatusLabel(),
            'ready' => in_array($o->status, ['ready', 'on_way'], true),
            'customer' => $o->customer_name, 'phone' => $o->customer_phone ?: null,
            'zone' => $address['zone'] ?? null, 'address' => $address['text'] ?? null, 'address_label' => $address['label'] ?? null,
            'notes' => $o->notes, 'maps' => DeliveryService::mapsUrl($o),
            'branch' => $o->branch ? tr($o->branch->name, $o->branch->name_en) : null,
            'items_count' => (int) $o->items->sum('quantity'),
            'items' => $o->items->map(fn ($i) => ['name' => $i->name, 'qty' => $i->quantity])->values()->all(),
            'total' => (float) $o->total, 'collect' => $collect, 'collect_text' => money($collect),
            'paid' => $o->payment_status === 'paid',
            'payment' => $o->payment_status === 'paid' ? __('Paid already: collect nothing') : __('Cash on delivery'),
            'assigned_at' => $o->driver_assigned_at ? local_time($o->driver_assigned_at, 'h:i A') : null,
            'picked_up_at' => $o->picked_up_at ? local_time($o->picked_up_at, 'h:i A') : null,
        ];
    }
}
