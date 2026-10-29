<?php

namespace App\Http\Controllers\Menu;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\DeliveryZone;
use App\Models\Order;
use App\Services\Customers\GuestAccount;
use App\Services\Customers\Outreach;
use App\Services\Customers\SignIn\EmailConfirmation;
use App\Services\Customers\SignIn\GuestIdentity;
use App\Services\Customers\SignIn\PhoneCodeSignIn;
use App\Services\Delivery\DeliveryPricing;
use App\Services\Menu\MenuCatalog;
use App\Services\Orders\BusyMode;
use App\Services\Orders\OrderException;
use App\Services\Orders\OrderService;
use App\Services\Orders\Pager;
use App\Services\Settings;
use App\Services\Wallet\WalletService;
use App\Support\DeliveryAddress;
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
            // optional: an email so the guest can sign in with it next time (kept unproven until a link is used)
            'customer_email' => 'nullable|string|email:rfc|max:190',
            // the personal link of a manual campaign the guest came from (App\Services\Customers\Outreach)
            'ref' => 'nullable|string|max:12',
            'coupon' => 'nullable|string|max:30',
            // online ordering (no table)
            'type' => ['nullable', Rule::in(['takeaway', 'delivery'])],
            'branch_id' => 'nullable|integer',
            'zone_id' => 'nullable|integer',
            'address' => 'nullable|string|max:300',
            // the address in parts (street, building, floor…): the server writes the one line from them (DeliveryAddress)
            ...DeliveryAddress::rules(),
            // the guest's pin on the map: it prices the delivery when no area is picked from the list (DeliveryPricing)
            'lat' => 'nullable|numeric|between:-90,90|required_with:lng',
            'lng' => 'nullable|numeric|between:-180,180|required_with:lat',
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

        // the membership discount follows the mobile only for the guest this phone is signed in as (card cookie)
        $me = GuestAccount::fromRequest($request);
        $data['member_phone_verified'] = $me !== null && normalize_phone((string) ($data['customer_phone'] ?? '')) === $me->phone;

        $data['guest_email'] = GuestIdentity::normalizeEmail($data['customer_email'] ?? null);
        $data['campaign_recipient_id'] = Outreach::recipientFor($data['ref'] ?? null);
        unset($data['customer_email'], $data['ref']);

        $table = MenuController::currentTable($request);
        if (! $table && self::mustProvePhone((string) ($data['type'] ?? 'takeaway'))
            && ! ($me && GuestAccount::provedPhone($request, $me) && normalize_phone((string) ($data['customer_phone'] ?? '')) === $me->phone)) {
            return response()->json([
                'message' => __('Confirm your mobile number first: sign in with it (a code on WhatsApp), then send the order. Your cart stays.'),
                'sign_in_url' => route('menu.account.sign-in'),
            ], 422);
        }
        // a mobile no guest of this restaurant had before: the order creates the guest, so this phone may keep them
        $phone = normalize_phone((string) ($data['customer_phone'] ?? ''));
        $newGuest = $phone !== null && ! Customer::query()->where('phone', $phone)->exists();
        try {
            // at a scanned table the order is always dine-in: a type sent by the page never drops the service charge
            // or takes the order out of the table's bill
            $order = $table
                ? $orders->place(['source' => 'qr', 'type' => 'dine_in'] + $data, $table->branch, $table)
                : $this->placeOnline($data, $orders);
        } catch (OrderException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        // a pickup ordered from the guest's own phone needs no QR: the page they land on is already their pager
        // (on the address the guest is using, like the tracking page)
        $url = Pager::enabled() && Pager::forOrder($order) ? route('menu.pager.show', $order->pagerKey()) : route('menu.order.show', $order->tracking_token);
        $response = response()->json(['ok' => true, 'number' => $order->number, 'url' => $url]);

        if ($order->wasRecentlyCreated && $order->source === 'online') {
            GuestAccount::rememberAddress($order);
        }
        // «أكّد إيميلك»: the email typed with the order becomes a sign-in only through its link (EmailConfirmation)
        if ($order->wasRecentlyCreated && $order->guest_email && $order->customer_id && ($guest = Customer::query()->find($order->customer_id))
            && ! ($guest->email === $order->guest_email && $guest->email_verified_at !== null)) {
            app(EmailConfirmation::class)->request($guest, $order->guest_email, (string) $request->ip());
        }
        // "حسابي" on this phone right away for a brand-new guest; an existing guest's account never opens just
        // because someone typed their mobile (they use their link or mobile + a delivered order's number)
        if ($newGuest && $order->wasRecentlyCreated && $order->source === 'online' && $order->customer_id
            && GuestAccount::fromRequest($request) === null && ($guest = Customer::query()->find($order->customer_id))) {
            $response->withCookie(GuestAccount::rememberCookie(GuestAccount::token($guest)));
        }

        return $response;
    }

    /**
     * The restaurant asks for a proven mobile before this online order (setting online_verify_phone), and a code can
     * reach the guest at all; without any way to send one the setting would only block every order.
     */
    public static function mustProvePhone(string $type): bool
    {
        $rule = Settings::get('online_verify_phone', 'off');

        return ($rule === 'all' || ($rule === 'delivery' && $type === 'delivery')) && PhoneCodeSignIn::canProve();
    }

    /**
     * The coupon line of the cart before sending: what the code takes off these lines, priced exactly like the
     * order will be (OrderService::quoteCoupon), at the scanned table's branch or the branch of the online cart.
     */
    public function coupon(Request $request, OrderService $orders): JsonResponse
    {
        $data = $request->validate([
            'coupon' => 'required|string|max:30',
            'items' => 'required|array|min:1|max:'.OrderService::MAX_LINES,
            'items.*.item_id' => 'required|integer',
            'items.*.qty' => 'required|integer|min:1|max:'.OrderService::MAX_QTY,
            'items.*.option_ids' => 'array|max:30',
            'items.*.option_ids.*' => 'integer',
            'customer_phone' => 'nullable|string|max:20',
            'branch_id' => 'nullable|integer',
        ]);
        $table = MenuController::currentTable($request);
        $branch = $table?->branch
            ?? Branch::query()->where('is_active', true)->find($data['branch_id'] ?? 0)
            ?? MenuCatalog::defaultBranch();
        unset($data['branch_id']);
        try {
            return response()->json($orders->quoteCoupon($data, $branch));
        } catch (OrderException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
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
        $quote = null;
        if ($type === 'delivery') {
            [$zone, $address, $quote] = $this->delivery($data, $branch);
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
            'delivery_quote' => $quote,
        ], $branch);
        if ($scheduled && $order->wasRecentlyCreated) {
            $order->update(['scheduled_for' => $scheduled]);
        }

        return $order;
    }

    /**
     * Where and for how much the order is delivered. An area picked from the list (zone_id) keeps its fee, the pin
     * being optional and inside that area when it is drawn. Otherwise the pin prices it, always on the server
     * (DeliveryPricing: the drawn zone holding it, or the distance ring of the branch); a point the branch does
     * not serve is refused. A fee sent by the browser is never read.
     *
     * @param  array<string,mixed>  $data
     * @return array{0:?DeliveryZone, 1:array<string,mixed>, 2:?array{fee:float, min:float, label:string}}
     */
    private function delivery(array $data, Branch $branch): array
    {
        // the address in parts (the checkout's fields) makes the one line; an older page sends the line itself
        $parts = DeliveryAddress::clean($data['address_parts'] ?? null);
        if ($parts !== [] && ! isset($parts['street'])) {
            throw new OrderException(__('Write the street name.'));
        }
        $text = $parts !== [] ? DeliveryAddress::compose($parts) : trim((string) ($data['address'] ?? ''));
        $kept = $parts !== [] ? ['parts' => $parts] : [];
        if (! empty($data['zone_id'])) {
            $zone = DeliveryZone::query()->with('parent')->where('branch_id', $branch->id)->where('is_active', true)->find($data['zone_id']);
            if (! $zone) {
                throw new OrderException(__('Choose the delivery zone.'));
            }
            if ($text === '') {
                throw new OrderException(__('Write the delivery address.'));
            }

            return [$zone, ['zone' => $zone->label(), 'text' => $text] + $this->pin($data, $zone) + $kept, null];
        }

        if (! isset($data['lat'], $data['lng']) || ! DeliveryPricing::byLocation($branch, Settings::all())) {
            throw new OrderException(DeliveryPricing::byLocation($branch, Settings::all())
                ? __('Pin your location on the map, or choose your area from the list.') : __('Choose the delivery zone.'));
        }
        $point = [round((float) $data['lat'], 6), round((float) $data['lng'], 6)];
        $quote = DeliveryPricing::quote($branch, ...$point);
        if (! $quote) {
            throw new OrderException(__('Sorry, we do not deliver to this location.'));
        }
        if ($text === '') {
            throw new OrderException(__('Write the delivery address.'));
        }
        $address = ['zone' => $quote['label'], 'text' => $text, 'lat' => $point[0], 'lng' => $point[1]]
            + ($quote['km'] !== null ? ['km' => $quote['km']] : [])
            + ($quote['zone'] === null && $quote['minutes'] ? ['minutes' => $quote['minutes']] : [])
            + $kept;

        return $quote['zone']
            ? [$quote['zone'], $address, null]
            : [null, $address, ['fee' => $quote['fee'], 'min' => $quote['min'], 'label' => $quote['label']]];
    }

    /**
     * The point the guest pinned on the map, kept with the address (the driver navigates to it, the tracking map
     * shows it). It must fall inside the zone's drawn area (a sub-area without its own area uses its parent's); a
     * zone without a drawn area (manual zones) takes any pin.
     *
     * @param  array<string,mixed>  $data
     * @return array{lat?:float, lng?:float}
     */
    private function pin(array $data, DeliveryZone $zone): array
    {
        if (! isset($data['lat'], $data['lng'])) {
            return [];
        }
        $point = [round((float) $data['lat'], 6), round((float) $data['lng'], 6)];
        $area = $zone->polygon ? $zone : $zone->parent;
        if ($area?->polygon && ! $area->contains(...$point)) {
            throw new OrderException(__('The pinned location is outside :zone.', ['zone' => $zone->label()]));
        }

        return ['lat' => $point[0], 'lng' => $point[1]];
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
