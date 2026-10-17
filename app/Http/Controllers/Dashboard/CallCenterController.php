<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\DeliveryZone;
use App\Models\Order;
use App\Services\Orders\OrderException;
use App\Services\Orders\OrderService;
use App\Services\Orders\ShiftService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Call-center pack: the POS screen in "phone" mode. Look the caller up by mobile, pick a saved address,
 * take a delivery (zone fee + minimum) or pickup order. Payment is collected later (on delivery) from the POS.
 */
class CallCenterController extends Controller
{
    private const MAX_ADDRESSES = 5;

    public function screen(Request $request, ShiftService $shifts): View
    {
        $view = app(PosController::class)->screen($request, $shifts);
        $branch = $view->getData()['branch'];
        $data = $view->getData()['data'];
        $data['mode'] = 'callcenter';
        // top-ups and gift-card sales are counter actions: their dialogs are not on this screen, so pos.js must not wire them
        $data['walletManage'] = false;
        $data['urls']['order'] = route('dash.callcenter.order');
        $data['urls']['lookup'] = route('dash.callcenter.lookup');
        $data['zones'] = DeliveryZone::query()->where('branch_id', $branch->id)->where('is_active', true)->with('parent')
            ->get()->sortBy(fn (DeliveryZone $z) => $z->label())
            ->map(fn (DeliveryZone $z) => [
                'id' => $z->id, 'name' => $z->label(), 'fee' => (float) ($z->effective('fee') ?? 0),
                'min' => (float) ($z->effective('min_order') ?? 0), 'minutes' => $z->effective('estimated_minutes'),
                'polygon' => $z->polygon, 'sub' => $z->parent_id !== null,
            ])->values();

        return $view->with(['data' => $data, 'mode' => 'callcenter', 'needsShift' => false]);
    }

    /** Caller card: name, second phone, saved addresses, notes and the last orders. */
    public function lookup(Request $request): JsonResponse
    {
        $phone = normalize_phone((string) $request->query('phone', ''));
        if (! $phone) {
            return response()->json(['message' => __('Enter a valid Egyptian mobile number.')], 422);
        }
        $customer = Customer::query()->where('phone', $phone)->first();
        if (! $customer) {
            return response()->json(['found' => false, 'phone' => $phone]);
        }
        $orders = Order::query()->where('customer_id', $customer->id)->latest('id')->limit(5)->get();

        return response()->json([
            'found' => true, 'phone' => $phone, 'name' => $customer->name, 'phone_2' => $customer->phone_2,
            'notes' => $customer->notes, 'addresses' => array_values($customer->addresses ?? []),
            'orders_count' => Order::query()->where('customer_id', $customer->id)->where('status', '!=', 'cancelled')->count(),
            'orders' => $orders->map(fn (Order $o) => [
                'number' => $o->number, 'date' => local_time($o->created_at, 'd/m h:i A'), 'total' => money($o->total),
                'status' => $o->statusLabel(), 'type' => Order::TYPES[$o->type][app()->getLocale() === 'en' ? 1 : 0] ?? $o->type,
            ]),
        ]);
    }

    public function store(Request $request, OrderService $orders): JsonResponse
    {
        $branch = PosController::branch($request);
        $data = $request->validate([
            'client_uuid' => 'required|uuid',
            'type' => ['required', Rule::in(['delivery', 'takeaway'])],
            'items' => 'required|array|min:1|max:'.OrderService::MAX_LINES,
            'items.*.item_id' => 'required|integer',
            'items.*.qty' => 'required|integer|min:1|max:'.OrderService::MAX_QTY,
            'items.*.option_ids' => 'array|max:30',
            'items.*.option_ids.*' => 'integer',
            'items.*.notes' => 'nullable|string|max:200',
            'notes' => 'nullable|string|max:500',
            'customer_name' => 'nullable|string|max:100',
            'customer_phone' => 'required|string|max:20',
            'phone_2' => 'nullable|string|max:20',
            'discount' => 'nullable|numeric|min:0',
            'zone_id' => ['nullable', 'required_if:type,delivery', 'integer'],
            'address' => ['nullable', 'required_if:type,delivery', 'string', 'max:300'],
            'address_label' => 'nullable|string|max:40',
            'lat' => 'nullable|numeric|between:-90,90|required_with:lng',
            'lng' => 'nullable|numeric|between:-180,180|required_with:lat',
        ]);
        if (($data['discount'] ?? 0) > 0 && ! $request->user()->can('pos.discount')) {
            return response()->json(['message' => __('You are not allowed to give discounts.')], 403);
        }
        $zone = null;
        if ($data['type'] === 'delivery') {
            $zone = DeliveryZone::query()->with('parent')->where('branch_id', $branch->id)->where('is_active', true)->find($data['zone_id']);
            if (! $zone) {
                return response()->json(['message' => __('Choose the delivery zone.')], 422);
            }
        }
        $point = $zone && isset($data['lat'], $data['lng']) ? [round((float) $data['lat'], 6), round((float) $data['lng'], 6)] : null;
        // a pinned location must fall inside the zone's drawn area (a sub-area without its own area uses its parent's)
        $area = $zone?->polygon ? $zone : $zone?->parent;
        if ($point && $area?->polygon && ! $area->contains(...$point)) {
            return response()->json(['message' => __('The pinned location is outside :zone.', ['zone' => $zone->label()])], 422);
        }
        $address = $zone ? ['zone' => $zone->label(), 'text' => trim($data['address']), 'label' => trim((string) ($data['address_label'] ?? ''))]
            + ($point ? ['lat' => $point[0], 'lng' => $point[1]] : []) : null;

        try {
            $order = $orders->place($data + ['source' => 'callcenter', 'delivery_zone' => $zone, 'delivery_address' => $address], $branch, null, $request->user());
        } catch (OrderException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        if ($order->customer_id && $order->wasRecentlyCreated) {
            $this->remember($order->customer()->first(), $data, $zone, $point);
        }

        return response()->json(['ok' => true, 'id' => $order->id, 'number' => $order->number, 'total' => $order->total, 'paid' => false]);
    }

    /** Saves the second phone and the address (most recent first, no duplicates) on the customer. */
    private function remember(?Customer $customer, array $data, ?DeliveryZone $zone, ?array $point = null): void
    {
        if (! $customer) {
            return;
        }
        if (! empty($data['phone_2'])) {
            $customer->phone_2 = mb_substr(trim($data['phone_2']), 0, 20);
        }
        if ($zone) {
            $text = trim($data['address']);
            $saved = collect($customer->addresses ?? [])->reject(fn ($a) => ($a['text'] ?? '') === $text && (int) ($a['zone_id'] ?? 0) === $zone->id);
            $entry = ['label' => trim((string) ($data['address_label'] ?? '')), 'zone_id' => $zone->id, 'text' => $text]
                + ($point ? ['lat' => $point[0], 'lng' => $point[1]] : []);
            $customer->addresses = $saved->prepend($entry)
                ->take(self::MAX_ADDRESSES)->values()->all();
        }
        $customer->last_contacted_at = now();
        $customer->save();
    }
}
