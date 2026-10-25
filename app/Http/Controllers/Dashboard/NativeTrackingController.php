<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Orders\DeliveryService;
use App\Services\Orders\NativeTracking;
use App\Services\Orders\OrderException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The fixes the EgHub driver app's background service posts while an order is out for delivery
 * (App\Services\Orders\NativeTracking): {lat, lng, accuracy, speed, heading, at} with the bearer token it was given.
 * No session: the signed URL and the token are the only keys. Stored exactly like the driver screen's own positions
 * (DeliveryService::recordLocation), so the guest's tracking page and the drivers map follow without a change.
 */
class NativeTrackingController extends Controller
{
    public function store(Request $request, string $order): JsonResponse
    {
        if (! $request->hasValidSignature()) {
            return response()->json(['message' => 'Invalid or expired link.', 'stop' => true], 403);
        }
        $claims = NativeTracking::verify($request->bearerToken());
        if ($claims === null || $claims['o'] !== (int) $order) {
            return response()->json(['message' => 'Invalid or expired token.', 'stop' => true], 401);
        }
        $data = $request->validate([
            'lat' => 'required|numeric|between:-90,90',
            'lng' => 'required|numeric|between:-180,180',
            'accuracy' => 'nullable|numeric|min:0|max:100000',
            'speed' => 'nullable|numeric|min:0|max:1000',
            'heading' => 'nullable|numeric|min:0|max:360',
            'at' => 'nullable',
        ]);
        $tenant = Tenant::query()->find($claims['t']);
        if (! $tenant) {
            return response()->json(['message' => 'Gone.', 'stop' => true], 410);
        }
        tenancy()->initialize($tenant);
        $delivery = Order::query()->find($claims['o']);
        $driver = User::query()->where('is_active', true)->find($claims['u']);
        // delivered, failed, cancelled, given to someone else: the app stops reporting
        if (! $delivery || ! $driver || $delivery->driver_id !== $driver->id || $delivery->delivery_status !== 'picked_up' || $delivery->status !== 'on_way') {
            return response()->json(['message' => 'This delivery is over.', 'stop' => true], 410);
        }
        try {
            $result = app(DeliveryService::class)->recordLocation($driver, (float) $data['lat'], (float) $data['lng'],
                isset($data['accuracy']) ? (int) round((float) $data['accuracy']) : null);
        } catch (OrderException) {
            return response()->json(['message' => 'This delivery is over.', 'stop' => true], 410);
        }

        return response()->json(['ok' => true] + $result);
    }
}
