<?php

namespace App\Http\Controllers\Menu;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\DeliveryZone;
use App\Models\Order;
use App\Services\Menu\MenuCatalog;
use App\Services\Orders\BusyMode;
use App\Services\Orders\OrderException;
use App\Services\Orders\OrderService;
use App\Services\Settings;
use App\Services\Wallet\WalletService;
use App\Support\OrderTracking;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
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
            'client_uuid' => 'required|uuid',
            'items' => 'required|array|min:1|max:'.OrderService::MAX_LINES,
            'items.*.item_id' => 'required|integer',
            'items.*.qty' => 'required|integer|min:1|max:'.OrderService::MAX_QTY,
            'items.*.option_ids' => 'array|max:30',
            'items.*.option_ids.*' => 'integer',
            'items.*.notes' => 'nullable|string|max:200',
            'notes' => 'nullable|string|max:500',
            'customer_name' => 'nullable|string|max:100',
            'customer_phone' => 'nullable|string|max:20',
            'coupon' => 'nullable|string|max:30',
            // online ordering (no table)
            'type' => ['nullable', Rule::in(['takeaway', 'delivery'])],
            'branch_id' => 'nullable|integer',
            'zone_id' => 'nullable|integer',
            'address' => 'nullable|string|max:300',
            'scheduled_for' => 'nullable|date',
            'pay_wallet' => 'nullable|boolean',
        ]);

        // "Pay from my balance": only the guest whose card this phone holds (card link cookie), with their own number
        $data['pay_wallet'] = $request->boolean('pay_wallet');
        if ($data['pay_wallet']) {
            $token = $request->cookie('card_'.tenant()->getTenantKey());
            $guest = WalletService::enabled() && is_string($token) ? Customer::query()->where('card_token', $token)->first() : null;
            if (! $guest || normalize_phone((string) ($data['customer_phone'] ?? '')) !== $guest->phone) {
                return response()->json(['message' => __('Open your card link on this phone and order with its mobile number to pay from your balance.')], 422);
            }
        }

        $table = MenuController::currentTable($request);
        try {
            $order = $table
                ? $orders->place($data + ['source' => 'qr', 'type' => 'dine_in'], $table->branch, $table)
                : $this->placeOnline($data, $orders);
        } catch (OrderException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'number' => $order->number, 'url' => route('menu.order.show', $order->tracking_token)]);
    }

    /**
     * Takeaway or delivery from the menu link (plan feature online_ordering). A mobile number is required so the
     * restaurant can call back; delivery needs a zone of the branch (fee + minimum) and an address.
     */
    private function placeOnline(array $data, OrderService $orders): Order
    {
        $branch = Branch::query()->where('is_active', true)->find($data['branch_id'] ?? 0) ?? MenuCatalog::defaultBranch();
        if (BusyMode::isBusy()) {
            throw new OrderException(BusyMode::message()); // the restaurant paused online ordering for a while
        }
        $online = MenuController::onlineOptions($branch, Settings::all());
        $type = $data['type'] ?? 'takeaway';
        if (! $online || ! $online[$type]) {
            throw new OrderException(__('Scan the QR code on your table to order.'));
        }
        if (empty($data['customer_phone'])) {
            throw new OrderException(__('Enter your mobile number so the restaurant can reach you.'));
        }

        $zone = null;
        $address = null;
        if ($type === 'delivery') {
            $zone = DeliveryZone::query()->with('parent')->where('branch_id', $branch->id)->where('is_active', true)->find($data['zone_id'] ?? 0);
            if (! $zone) {
                throw new OrderException(__('Choose the delivery zone.'));
            }
            if (trim((string) ($data['address'] ?? '')) === '') {
                throw new OrderException(__('Write the delivery address.'));
            }
            $address = ['zone' => $zone->label(), 'text' => trim($data['address'])];
        }

        $scheduled = null;
        if (! empty($data['scheduled_for'])) {
            if (! $online['scheduled']) {
                throw new OrderException(__('Ordering for later is not available.'));
            }
            $scheduled = Carbon::parse($data['scheduled_for'], config('app.timezone'));
            if ($scheduled->lt(now()->addMinutes($online['prep'])) || $scheduled->gt(now()->addDays(7))) {
                throw new OrderException(__('Choose a time between :min minutes and 7 days from now.', ['min' => $online['prep']]));
            }
        }

        $order = $orders->place($data + [
            'source' => 'online', 'type' => $type, 'delivery_zone' => $zone, 'delivery_address' => $address,
        ], $branch);
        if ($scheduled && $order->wasRecentlyCreated) {
            $order->update(['scheduled_for' => $scheduled]);
        }

        return $order;
    }

    public function show(string $token): View
    {
        $order = Order::query()->where('tracking_token', $token)->with(['items', 'table', 'branch', 'deliveryZone.parent'])->firstOrFail();
        $settings = Settings::all();

        return view('menu.order', ['order' => $order, 'settings' => $settings, 'tracking' => (new OrderTracking($order, $settings))->toArray()]);
    }

    /**
     * Polled by the tracking page while the order is open. Keeps status and label, and adds the tracking shape of
     * App\Support\OrderTracking (steps, parts, headline, eta, final) so the page redraws without reloading.
     */
    public function status(string $token): JsonResponse
    {
        $order = Order::query()->where('tracking_token', $token)->with(['items', 'deliveryZone.parent'])->firstOrFail();

        return response()->json((new OrderTracking($order, Settings::all()))->toArray());
    }
}
