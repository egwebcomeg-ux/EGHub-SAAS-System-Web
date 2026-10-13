<?php

namespace App\Http\Controllers\Menu;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Orders\OrderException;
use App\Services\Orders\OrderService;
use App\Services\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Guest ordering from a table. Only possible after scanning that table's QR in this session; the branch is the
 * table's. The guest follows the order on /o/{tracking_token} (unguessable, no login).
 */
class OrderController extends Controller
{
    public function store(Request $request, OrderService $orders): JsonResponse
    {
        $data = $request->validate([
            'client_uuid'           => 'required|uuid',
            'items'                 => 'required|array|min:1|max:' . OrderService::MAX_LINES,
            'items.*.item_id'       => 'required|integer',
            'items.*.qty'           => 'required|integer|min:1|max:' . OrderService::MAX_QTY,
            'items.*.option_ids'    => 'array|max:30',
            'items.*.option_ids.*'  => 'integer',
            'items.*.notes'         => 'nullable|string|max:200',
            'notes'                 => 'nullable|string|max:500',
            'customer_name'         => 'nullable|string|max:100',
            'customer_phone'        => 'nullable|string|max:20',
        ]);

        $table = MenuController::currentTable($request);
        if (!$table) {
            return response()->json(['message' => __('Scan the QR code on your table to order.')], 422);
        }

        try {
            $order = $orders->place($data + ['source' => 'qr', 'type' => 'dine_in'], $table->branch, $table);
        } catch (OrderException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'number' => $order->number, 'url' => route('menu.order.show', $order->tracking_token)]);
    }

    public function show(string $token): View
    {
        $order = Order::query()->where('tracking_token', $token)->with(['items', 'table'])->firstOrFail();
        return view('menu.order', ['order' => $order, 'settings' => Settings::all()]);
    }

    public function status(string $token): JsonResponse
    {
        $order = Order::query()->where('tracking_token', $token)->firstOrFail();
        return response()->json(['status' => $order->status, 'label' => $order->statusLabel()]);
    }
}
