<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\DeliveryZone;
use App\Models\LoyaltyCard;
use App\Models\Order;
use App\Services\Delivery\DeliveryPricing;
use App\Services\Geocoding\AddressLookup;
use App\Services\Loyalty\Membership;
use App\Services\Orders\OrderConfirmation;
use App\Services\Orders\OrderException;
use App\Services\Orders\OrderService;
use App\Services\Orders\ShiftService;
use App\Services\Settings;
use App\Services\Wallet\WalletService;
use App\Support\DeliveryAddress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Call-center pack: the POS screen in "phone" mode. Look the caller up by mobile, pick a saved address,
 * take a delivery (zone fee + minimum, or by distance from the branch) or pickup order. Payment is collected later
 * (on delivery) from the POS. A branch priced by distance (DeliveryPricing) has no zone list: the agent finds the
 * customer's place (typed address, saved address, map pin) or picks a distance ring; the server prices it.
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
        // nor are corporate vouchers: no payment is taken here, and without their dialogs pos.js stopped on this screen
        $data['corporate'] = false;
        $data['urls']['order'] = route('dash.callcenter.order');
        $data['urls']['lookup'] = route('dash.callcenter.lookup');
        // manual: main area, then its street / sub-area (as the guest picks it); map: one list plus the map pin
        $data['zoneMode'] = Settings::get('delivery_zone_mode') === 'map' ? 'map' : 'manual';
        $data['branchName'] = tr($branch->name, $branch->name_en);
        // priced by distance: the map opens on the branch, the rings are the fallback without a pin
        $data['pricing'] = DeliveryPricing::method($branch) === DeliveryPricing::DISTANCE ? [
            'method' => DeliveryPricing::DISTANCE, 'ready' => DeliveryPricing::distanceReady($branch),
            'center' => $branch->lat !== null && $branch->lng !== null ? [(float) $branch->lat, (float) $branch->lng] : null,
            'rings' => DeliveryPricing::ringsForMenu($branch, Settings::all()),
        ] : ['method' => DeliveryPricing::ZONES];
        if ($data['pricing']['method'] === DeliveryPricing::DISTANCE) {
            $data['urls']['geoReverse'] = route('dash.callcenter.geo.reverse');
            $data['urls']['geoSuggest'] = route('dash.callcenter.geo.suggest');
            $data['geocoder'] = AddressLookup::enabled();
        }
        $data['zones'] = DeliveryZone::query()->where('branch_id', $branch->id)->where('is_active', true)->with('parent')
            ->get()->sortBy(fn (DeliveryZone $z) => $z->label())
            ->map(fn (DeliveryZone $z) => [
                'id' => $z->id, 'name' => $z->label(), 'fee' => (float) ($z->effective('fee') ?? 0),
                'min' => (float) ($z->effective('min_order') ?? 0), 'minutes' => $z->effective('estimated_minutes'),
                'polygon' => $z->polygon, 'sub' => $z->parent_id !== null,
                'parent' => $z->parent_id, 'short' => tr($z->name, $z->name_en),
            ])->values();

        return $view->with(['data' => $data, 'mode' => 'callcenter', 'needsShift' => false]);
    }

    /** Caller card: name, second phone, saved addresses, notes, the last orders and loyalty (tier, stamps, balance). */
    public function lookup(Request $request, Membership $membership): JsonResponse
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
        // a branch priced by distance: every saved address with its pin comes priced already
        $branch = PosController::branch($request);
        $byDistance = DeliveryPricing::method($branch) === DeliveryPricing::DISTANCE;
        $addresses = array_map(fn (array $a) => $byDistance && isset($a['lat'], $a['lng'])
            ? $a + CallCenterGeoController::delivery($branch, (float) $a['lat'], (float) $a['lng']) : $a,
            array_values(array_filter((array) ($customer->addresses ?? []), 'is_array')));

        return response()->json([
            'found' => true, 'phone' => $phone, 'name' => $customer->name, 'phone_2' => $customer->phone_2,
            'notes' => $customer->notes, 'addresses' => $addresses,
            'orders_count' => Order::query()->where('customer_id', $customer->id)->where('status', '!=', 'cancelled')->count(),
            'tier' => $membership->tierFor($customer)?->displayName(),
            'balance' => WalletService::enabled() ? (float) $customer->credit_balance : null,
            'loyalty' => $customer->loyaltyCards()->with('program')->whereHas('program', fn ($q) => $q->where('is_active', true))
                ->latest('updated_at')->limit(2)->get()->map(fn (LoyaltyCard $card) => [
                    'title' => tr($card->program->title, $card->program->title_en), 'stamps' => $card->stamps,
                    'of' => $card->program->stamps_required, 'rewards' => $card->rewards_available,
                ])->values(),
            'orders' => $orders->map(fn (Order $o) => [
                'number' => $o->number, 'date' => local_time($o->created_at, 'd/m h:i A'), 'total' => money($o->total),
                'status' => $o->statusLabel().(($state = OrderConfirmation::state($o)) ? ' · '.OrderConfirmation::label($state) : ''),
                'type' => Order::TYPES[$o->type][app()->getLocale() === 'en' ? 1 : 0] ?? $o->type,
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
            'zone_id' => ['nullable', 'integer'],
            'address' => ['nullable', 'required_if:type,delivery', 'string', 'max:300'],
            'address_label' => 'nullable|string|max:40',
            // a branch priced by distance: the pin prices it, or a distance ring picked by hand (index, nearest first)
            'ring' => 'nullable|integer|min:0|max:'.(DeliveryPricing::MAX_RINGS - 1),
            ...DeliveryAddress::rules(),
            'lat' => 'nullable|numeric|between:-90,90|required_with:lng',
            'lng' => 'nullable|numeric|between:-180,180|required_with:lat',
        ]);
        if (($data['discount'] ?? 0) > 0 && ! $request->user()->can('pos.discount')) {
            return response()->json(['message' => __('You are not allowed to give discounts.')], 403);
        }
        if ($data['type'] === 'delivery' && DeliveryPricing::method($branch) === DeliveryPricing::DISTANCE) {
            return $this->storeByDistance($request, $data, $branch, $orders);
        }
        $zone = null;
        if ($data['type'] === 'delivery') {
            if (empty($data['zone_id'])) {
                return response()->json(['message' => __('Choose the delivery zone.'), 'errors' => ['zone_id' => [__('Choose the delivery zone.')]]], 422);
            }
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

    /**
     * A delivery from a branch priced by distance, priced here and never from the browser: the pin's ring (a pin
     * past the last ring is refused), or the ring the agent picked when there is no pin (no point is stored then).
     * The minimum of that ring is checked by OrderService like a zone's.
     *
     * @param  array<string,mixed>  $data
     */
    private function storeByDistance(Request $request, array $data, Branch $branch, OrderService $orders): JsonResponse
    {
        if (! DeliveryPricing::distanceReady($branch)) {
            return response()->json(['message' => __('Delivery by distance is not set up for this branch yet.')], 422);
        }
        $point = isset($data['lat'], $data['lng']) ? [round((float) $data['lat'], 6), round((float) $data['lng'], 6)] : null;
        if ($point) {
            $quote = DeliveryPricing::quote($branch, ...$point);
            if (! $quote) {
                return response()->json(['message' => __('This place is outside the delivery area.')], 422);
            }
        } elseif (isset($data['ring'])) {
            $quote = DeliveryPricing::ring($branch, (int) $data['ring']);
            if (! $quote) {
                return response()->json(['message' => __('Choose the delivery distance.')], 422);
            }
        } else {
            return response()->json(['message' => __('Find the customer\'s place on the map, or choose the delivery distance.')], 422);
        }
        $parts = DeliveryAddress::clean($data['address_parts'] ?? null);
        unset($parts['label']);
        $address = ['zone' => $quote['label'], 'text' => trim($data['address']), 'label' => trim((string) ($data['address_label'] ?? ''))]
            + ($point ? ['lat' => $point[0], 'lng' => $point[1]] : [])
            + ($quote['km'] !== null ? ['km' => $quote['km']] : [])
            + (isset($quote['ring_km']) ? ['ring_km' => $quote['ring_km']] : [])
            + ($quote['minutes'] ? ['minutes' => $quote['minutes']] : [])
            + ($parts !== [] ? ['parts' => $parts] : []);

        try {
            $order = $orders->place($data + [
                'source' => 'callcenter', 'delivery_zone' => null, 'delivery_address' => $address,
                'delivery_quote' => ['fee' => $quote['fee'], 'min' => $quote['min'], 'label' => $quote['label']],
            ], $branch, null, $request->user());
        } catch (OrderException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        if ($order->customer_id && $order->wasRecentlyCreated) {
            $this->remember($order->customer()->first(), $data, null, $point, $parts, $quote['label']);
        }

        return response()->json(['ok' => true, 'id' => $order->id, 'number' => $order->number, 'total' => $order->total, 'paid' => false]);
    }

    /**
     * Saves the second phone and the address (most recent first, no duplicates) on the customer. An address of a
     * branch priced by distance has no zone: its pin, its parts and its label ("1.8 km ...") are kept instead.
     *
     * @param  array<string,string>  $parts
     */
    private function remember(?Customer $customer, array $data, ?DeliveryZone $zone, ?array $point = null, array $parts = [], ?string $distanceLabel = null): void
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
        } elseif ($distanceLabel !== null) {
            $text = trim($data['address']);
            $saved = collect($customer->addresses ?? [])->filter(fn ($a) => is_array($a))
                ->reject(fn (array $a) => ($a['text'] ?? '') === $text && empty($a['zone_id']));
            $entry = ['label' => trim((string) ($data['address_label'] ?? '')), 'zone_id' => null, 'zone' => $distanceLabel, 'text' => $text]
                + ($point ? ['lat' => $point[0], 'lng' => $point[1]] : []) + ($parts !== [] ? ['parts' => $parts] : []);
            $customer->addresses = $saved->prepend($entry)->take(self::MAX_ADDRESSES)->values()->all();
        }
        $customer->last_contacted_at = now();
        $customer->save();
    }
}
