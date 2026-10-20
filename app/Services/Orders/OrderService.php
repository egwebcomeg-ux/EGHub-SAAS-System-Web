<?php

namespace App\Services\Orders;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\DeliveryZone;
use App\Models\DiningTable;
use App\Models\ItemOption;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\PosTerminal;
use App\Models\Shift;
use App\Models\TableCall;
use App\Models\TableSession;
use App\Models\User;
use App\Services\Audit;
use App\Services\Corporate\CorporateVouchers;
use App\Services\Entitlements;
use App\Services\Inventory\StockService;
use App\Services\Loyalty\LoyaltyService;
use App\Services\Loyalty\Membership;
use App\Services\Menu\MenuCatalog;
use App\Services\Notify\GuestNotifier;
use App\Services\Settings;
use App\Services\Wallet\WalletService;
use App\Support\DineIn;
use App\Support\PrepKind;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Places and moves orders. Prices are always recomputed here from the menu (branch overrides included): the
 * client only sends item ids, option ids and quantities, never amounts. Every status change is recorded.
 */
final class OrderService
{
    public const MAX_LINES = 40;

    public const MAX_QTY = 30;

    /** Column stamped when an order enters a status. */
    private const STAMPS = ['preparing' => 'preparing_at', 'ready' => 'ready_at', 'served' => 'completed_at', 'cancelled' => 'cancelled_at'];

    /**
     * @param array{client_uuid?:string, items:array<int,array{item_id:int, qty:int, option_ids?:int[], notes?:string}>,
     *              notes?:string, customer_name?:string, customer_phone?:string, type?:string, source?:string,
     *              delivery_zone?:DeliveryZone, delivery_address?:array<string,string>, pay_wallet?:bool, terminal_id?:?int} $data
     *
     * With `pay_wallet` the order is paid at once from the customer's prepaid balance (whole total, or nothing is placed).
     */
    public function place(array $data, Branch $branch, ?DiningTable $table = null, ?User $by = null): Order
    {
        if (! Entitlements::current()->allows('qr_ordering') && ($data['source'] ?? 'qr') === 'qr') {
            throw new OrderException(__('Ordering is not available right now.'));
        }
        if (Settings::get('ordering_enabled') !== '1' && ($data['source'] ?? 'qr') === 'qr') {
            throw new OrderException(__('Ordering is not available right now.'));
        }
        // the owner can keep the hall to a list for the waiter (or menu only): then nothing is sent from the table
        if (($data['source'] ?? 'qr') === 'qr' && DineIn::mode(Settings::all()) !== 'order') {
            throw new OrderException(__('Orders here are taken by the waiter. Show your list to the waiter.'));
        }
        if ($table && $table->branch_id !== $branch->id) {
            throw new OrderException(__('This table belongs to another branch.'));
        }
        // busy mode pauses the orders guests place without a table; the tables, POS and call center go on
        if (BusyMode::blocks((string) ($data['source'] ?? 'qr'), $table !== null)) {
            throw new OrderException(BusyMode::message());
        }

        $uuid = $data['client_uuid'] ?? null;
        if ($uuid && ($existing = Order::query()->where('client_uuid', $uuid)->first())) {
            return $existing; // the same submit retried (double tap, flaky network)
        }

        $catalog = MenuCatalog::forBranch($branch);
        $lines = $this->priceLines($data['items'] ?? [], $catalog);
        $subtotal = round(array_sum(array_column($lines, 'line_total')), 2);
        // Only staff (POS) can discount; the caller checks the permission. Never more than the order itself.
        $discount = round(min($subtotal, max(0.0, (float) ($data['discount'] ?? 0))), 2);
        // A guest's coupon code (campaigns, loyalty vouchers): validated now, counted inside the transaction below.
        $coupon = null;
        $couponOff = 0.0;
        if (trim((string) ($data['coupon'] ?? '')) !== '') {
            [$coupon, $couponOff] = app(Coupons::class)->check((string) $data['coupon'], $subtotal - $discount, normalize_phone((string) ($data['customer_phone'] ?? '')), $lines);
            $discount = round($discount + $couponOff, 2);
        }
        // Cart goal of the menu ("add X and get Y free"): decided here with the server's prices, never by the client.
        $goalOff = 0.0;
        $goalReward = null;
        $goal = in_array($data['source'] ?? 'qr', CartGoal::SOURCES, true) ? CartGoal::current() : null;
        if ($goal && ($reward = $goal->rewardFor($subtotal - $discount + $couponOff, $coupon !== null, $catalog))) {
            $goalOff = round(min($reward['discount'], $subtotal - $discount), 2);
            $discount = round($discount + $goalOff, 2);
            $goalReward = $reward['label'];
            if ($reward['line']) {
                $lines[] = $reward['line'];
            }
        }
        // Membership tier of the guest behind the mobile number: its discount comes off what is left.
        [$tier, $memberOff] = app(Membership::class)->discountFor(normalize_phone((string) ($data['customer_phone'] ?? '')), $subtotal - $discount);
        $discount = round($discount + $memberOff, 2);
        $type = in_array($data['type'] ?? null, array_keys(Order::TYPES), true) ? $data['type'] : ($table ? 'dine_in' : 'takeaway');
        $service = $type === 'dine_in' ? round(($subtotal - $discount) * $this->percent('service_percent'), 2) : 0.0;
        $tax = round(($subtotal - $discount + $service) * $this->percent('tax_percent'), 2);

        // Delivery (call center): the zone sets the fee and the minimum; its sub-areas inherit empty values.
        $zone = $type === 'delivery' ? ($data['delivery_zone'] ?? null) : null;
        $deliveryFee = 0.0;
        if ($zone instanceof DeliveryZone) {
            if ($zone->branch_id !== $branch->id || ! $zone->is_active) {
                throw new OrderException(__('This zone is not served by this branch.'));
            }
            $min = (float) ($zone->effective('min_order') ?? 0);
            if ($min > 0 && $subtotal - $discount < $min) {
                throw new OrderException(__('The minimum order for :zone is :amount.', ['zone' => $zone->label(), 'amount' => money($min)]));
            }
            $deliveryFee = round((float) ($zone->effective('fee') ?? 0), 2);
        }

        $phone = isset($data['customer_phone']) && $data['customer_phone'] !== '' ? normalize_phone((string) $data['customer_phone']) : null;
        if (isset($data['customer_phone']) && $data['customer_phone'] !== '' && ! $phone) {
            throw new OrderException(__('Enter a valid Egyptian mobile number.'));
        }

        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction(function () use ($data, $branch, $table, $by, $lines, $subtotal, $discount, $service, $tax, $type, $phone, $uuid, $zone, $deliveryFee, $coupon, $couponOff, $tier, $memberOff, $goalOff, $goalReward) {
                    if ($coupon) {
                        app(Coupons::class)->redeem($coupon);
                    }
                    $customer = $phone && Entitlements::current()->allows('customers|wallet')
                        ? $this->customer($phone, (string) ($data['customer_name'] ?? ''), ($data['source'] ?? 'qr') === 'callcenter' ? 'callcenter' : 'menu') : null;

                    $order = Order::query()->create([
                        'branch_id' => $branch->id, 'dining_table_id' => $table?->id, 'customer_id' => $customer?->id,
                        'number' => (int) Order::query()->lockForUpdate()->max('number') + 1,
                        'client_uuid' => $uuid, 'tracking_token' => random_token(12),
                        'type' => $type, 'source' => $data['source'] ?? 'qr', 'status' => 'new',
                        'customer_name' => mb_substr(trim((string) ($data['customer_name'] ?? '')), 0, 100),
                        'customer_phone' => $phone ?? '',
                        'notes' => mb_substr(trim((string) ($data['notes'] ?? '')), 0, 500) ?: null,
                        'subtotal' => $subtotal, 'discount' => $discount, 'service' => $service, 'tax' => $tax,
                        'member_discount' => $memberOff, 'member_tier' => $memberOff > 0 ? mb_substr($tier->displayName(), 0, 40) : null,
                        'coupon_id' => $coupon?->id, 'coupon_code' => $coupon?->code, 'coupon_discount' => $couponOff,
                        'goal_discount' => $goalOff, 'goal_reward' => $goalReward,
                        'delivery_zone_id' => $zone?->id, 'delivery_fee' => $deliveryFee,
                        'delivery_address' => $type === 'delivery' ? ($data['delivery_address'] ?? null) : null,
                        'total' => round($subtotal - $discount + $service + $tax + $deliveryFee, 2), 'created_by' => $by?->id,
                        'terminal_id' => $data['terminal_id'] ?? null,
                    ]);
                    foreach ($lines as $line) {
                        $order->items()->create($line);
                    }
                    if ($table && $type === 'dine_in') {
                        $this->joinTableSession($order, $table);
                    }
                    OrderStatusHistory::query()->create(['order_id' => $order->id, 'to_status' => 'new', 'user_id' => $by?->id]);
                    app(StockService::class)->deductForOrder($order); // inventory pack only
                    DB::afterCommit(fn () => app(GuestNotifier::class)->orderPlaced($order)); // WhatsApp "we got your order"

                    if (! empty($data['pay_wallet'])) {
                        app(WalletService::class)->payOrder($order, $by);
                        $order->update(['payment_status' => 'paid', 'payment_method' => WalletService::METHOD]);
                        app(LoyaltyService::class)->orderCompleted($order);
                    }

                    return $order;
                });
            } catch (QueryException $e) {
                // two orders took the same number at once (or the same client_uuid raced): retry / return the winner
                if ($uuid && ($existing = Order::query()->where('client_uuid', $uuid)->first())) {
                    return $existing;
                }
                if ($attempt >= 3) {
                    throw $e;
                }
            }
        }
    }

    /**
     * What a coupon takes off a cart before it is placed (POS preview), priced exactly like place() does.
     *
     * @param  array{items:array<int,array{item_id:int, qty:int, option_ids?:int[]}>, coupon:string, customer_phone?:string, discount?:float}  $data
     * @return array{code:string, discount:float, label:string}
     *
     * @throws OrderException
     */
    public function quoteCoupon(array $data, Branch $branch): array
    {
        $lines = $this->priceLines($data['items'] ?? [], MenuCatalog::forBranch($branch));
        $subtotal = round(array_sum(array_column($lines, 'line_total')), 2);
        $discount = round(min($subtotal, max(0.0, (float) ($data['discount'] ?? 0))), 2);
        [$coupon, $off] = app(Coupons::class)->check((string) $data['coupon'], $subtotal - $discount, normalize_phone((string) ($data['customer_phone'] ?? '')), $lines);

        return ['code' => $coupon->code, 'discount' => $off, 'label' => $coupon->headline()];
    }

    /**
     * Collects the money of an order (POS). Cash goes into the cashier's open shift drawer. With corporate vouchers
     * ($vouchers: codes typed / scanned, or ids of vouchers linked to $voucherPhone) they pay first, earliest expiry
     * first, and $method pays the rest; when they cover everything the order is paid by "corporate". With $parts
     * (split payment) the rest is paid by several methods (cash, card, InstaPay, mobile wallet) that add up to it:
     * each line is kept on the order (orders.payments) and counted under its own method in the shift and reports.
     *
     * @param  list<array{code?:?string, id?:?int, approval?:?string}>  $vouchers
     * @param  list<array{method:string, amount:float|int|string, tendered?:float|int|string|null}>  $parts
     */
    public function pay(Order $order, string $method, User $by, ?Shift $shift, array $vouchers = [], ?string $voucherPhone = null, array $parts = []): Order
    {
        $parts = self::paymentParts($parts);
        if ($parts !== []) {
            $method = count(array_unique(array_column($parts, 'method'))) > 1 ? self::SPLIT : $parts[0]['method'];
        } elseif ($method === self::SPLIT) {
            throw new OrderException(__('Add how each part is paid.'));
        }
        if ($order->payment_status === 'paid') {
            throw new OrderException(__('This order is already paid.'));
        }
        if ($order->status === 'cancelled') {
            throw new OrderException(__('This order was cancelled.'));
        }
        if (! in_array($method, ShiftService::METHODS, true) && $method !== self::SPLIT) {
            throw new OrderException(__('Choose a payment method.'));
        }
        if (! $shift && Entitlements::current()->allows('shifts')) {
            throw new OrderException(__('Open a shift first to take payments.'));
        }
        if ($method === CorporateVouchers::METHOD && $vouchers === []) {
            throw new OrderException(__('Add a corporate voucher first.'));
        }
        if ($vouchers !== [] && $method === WalletService::METHOD) {
            throw new OrderException(__('With corporate vouchers, pay the rest in cash, by card, InstaPay or a mobile wallet.'));
        }

        return DB::transaction(function () use ($order, $method, $by, $shift, $vouchers, $voucherPhone, $parts) {
            // locked: two taps on "pay" (or two cashiers) never take the money twice
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            if ($locked->payment_status === 'paid') {
                throw new OrderException(__('This order is already paid.'));
            }
            $covered = $vouchers !== [] ? app(CorporateVouchers::class)->redeemForOrder($locked, $vouchers, $voucherPhone, $by) : 0.0;
            $rest = round((float) $locked->total - $covered, 2);
            if ($parts !== [] && $rest > 0.004 && abs(round(array_sum(array_column($parts, 'amount')), 2) - $rest) > 0.004) {
                throw new OrderException(__('The parts add up to :sum, the order needs :rest.', ['sum' => money(array_sum(array_column($parts, 'amount'))), 'rest' => money($rest)]));
            }
            if ($rest <= 0.004) {
                $parts = []; // the vouchers paid it all
            }
            if ($method === CorporateVouchers::METHOD && $rest > 0.004) {
                throw new OrderException(__('The vouchers cover :covered of :total: choose how the rest (:rest) is paid.', [
                    'covered' => money($covered), 'total' => money($locked->total), 'rest' => money($rest),
                ]));
            }
            if ($covered > 0 && $rest <= 0.004) {
                $method = CorporateVouchers::METHOD;
            }
            if ($method === WalletService::METHOD) {
                app(WalletService::class)->payOrder($locked, $by, $shift);
            }
            $order->update(['payment_status' => 'paid', 'payment_method' => $method, 'shift_id' => $shift?->id, 'corporate_amount' => $covered, 'payments' => $parts ?: null]);
            app(LoyaltyService::class)->orderCompleted($order);

            return $order;
        });
    }

    /** Payment methods a POS sale can take while the internet is down (no balance, vouchers or coupons offline). */
    public const OFFLINE_METHODS = ['cash', 'card', 'instapay', 'wallet'];

    /** orders.payment_method of an order paid by more than one method (its lines are in orders.payments). */
    public const SPLIT = 'split';

    /** Methods a split payment's parts can use (the guest's balance and the vouchers keep their own flow). */
    public const SPLIT_METHODS = ['cash', 'card', 'instapay', 'wallet'];

    /**
     * The parts of a split payment, cleaned: known methods, amounts above zero; cash keeps what the guest handed over
     * (tendered) when it is at least the part, and the change.
     *
     * @param  list<array{method?:string, amount?:mixed, tendered?:mixed}>  $parts
     * @return list<array{method:string, amount:float, tendered?:float, change?:float}>
     */
    public static function paymentParts(array $parts): array
    {
        $out = [];
        foreach (array_slice(array_values(array_filter($parts, 'is_array')), 0, 8) as $p) {
            $method = (string) ($p['method'] ?? '');
            $amount = round((float) ($p['amount'] ?? 0), 2);
            if (! in_array($method, self::SPLIT_METHODS, true)) {
                throw new OrderException(__('A split payment takes cash, card, InstaPay or a mobile wallet.'));
            }
            if ($amount <= 0) {
                throw new OrderException(__('Each part must be more than zero.'));
            }
            $line = ['method' => $method, 'amount' => $amount];
            $tendered = isset($p['tendered']) && $p['tendered'] !== '' ? round((float) $p['tendered'], 2) : null;
            if ($method === 'cash' && $tendered !== null && $tendered + 0.004 >= $amount) {
                $line += ['tendered' => $tendered, 'change' => round($tendered - $amount, 2)];
            }
            $out[] = $line;
        }

        return $out;
    }

    /**
     * Saves a sale made at the POS while the internet was down, as it was sold: the price snapshot the device kept is
     * what the guest paid, even when the menu changed since (then the order is flagged needs_review with the reasons).
     * The real number is given now; created_at is the time of the sale (created_offline_at), so the shift and the
     * reports count it where it happened. Idempotent by client_uuid: a sale sent twice is saved once.
     *
     * @param array{client_uuid:string, offline_ref:string, created_offline_at:string, offline_since?:?string, type:string,
     *              table_id?:?int, customer_name?:?string, customer_phone?:?string, notes?:?string, discount?:float,
     *              subtotal:float, service:float, tax:float, total:float, method:string, tendered?:?float,
     *              items:list<array{item_id:?int, qty:int, name:string, unit_price:float, option_ids?:list<int>,
     *              options?:list<array{id?:int, group?:string, name:string, price:float}>, notes?:?string}>} $data
     * @return array{order:Order, duplicate:bool}
     *
     * @throws OrderException a refused sale (shift closed, over the offline limit, amounts that do not add up)
     */
    public function placeOffline(array $data, Branch $branch, User $by, Shift $shift, ?PosTerminal $terminal): array
    {
        if ($existing = Order::query()->where('client_uuid', $data['client_uuid'])->first()) {
            return ['order' => $existing, 'duplicate' => true];
        }
        $soldAt = rescue(fn () => Carbon::parse($data['created_offline_at']), null, false);
        if (! $soldAt) {
            throw new OrderException(__('The time of the sale is missing.'));
        }
        if ($shift->branch_id !== $branch->id || ($terminal ? $shift->terminal_id !== $terminal->id : $shift->user_id !== $by->id)) {
            throw new OrderException(__('This sale belongs to another shift.'));
        }
        if (! $shift->isOpen()) {
            throw new OrderException(__('The shift of this sale was closed before it synced: settle it by hand and mark it resolved.'));
        }
        if ($soldAt->gt(now()->addMinutes(5)) || $soldAt->lt($shift->opened_at->copy()->subMinute())) {
            throw new OrderException(__('The time of the sale is outside its shift: check the device clock.'));
        }
        $since = ! empty($data['offline_since']) ? rescue(fn () => Carbon::parse($data['offline_since']), null, false) : null;
        if ($since && $since->copy()->addHours(Terminals::offlineHours())->addMinutes(5)->lt($soldAt)) {
            throw new OrderException(__('Sold after the offline limit (:n hours): settle it by hand and mark it resolved.', ['n' => Terminals::offlineHours()]));
        }
        $ref = strtoupper((string) $data['offline_ref']);
        $code = $terminal?->code ?? 'X';
        if (! preg_match('/^OFF-'.preg_quote($code, '/').'-(\d{1,7})$/', $ref, $m)) {
            throw new OrderException(__('The offline reference is not of this terminal.'));
        }
        if (Order::query()->where('offline_ref', $ref)->exists()) {
            throw new OrderException(__('Another sale already has the reference :ref.', ['ref' => $ref]));
        }
        $parts = self::paymentParts((array) ($data['payments'] ?? []));
        $method = $parts !== [] ? (count(array_unique(array_column($parts, 'method'))) > 1 ? self::SPLIT : $parts[0]['method']) : (string) $data['method'];
        if ($method !== self::SPLIT && ! in_array($method, self::OFFLINE_METHODS, true)) {
            throw new OrderException(__('Offline, only cash, card, InstaPay or a mobile wallet can be taken.'));
        }
        $items = array_values((array) $data['items']);
        if ($items === [] || count($items) > self::MAX_LINES) {
            throw new OrderException(__('Your cart is empty.'));
        }

        // the lines as sold, and what the menu says today (a difference is noted for the review)
        $catalog = MenuCatalog::forBranch($branch)->allDay();
        $menu = MenuItem::query()->whereIn('id', array_filter(array_map(fn ($l) => (int) ($l['item_id'] ?? 0), $items)))
            ->with(['optionGroups.options', 'category'])->get()->keyBy('id');
        $lines = [];
        $notes = [];
        foreach ($items as $l) {
            $qty = (int) ($l['qty'] ?? 0);
            $unit = round((float) ($l['unit_price'] ?? -1), 2);
            $name = mb_substr(trim((string) ($l['name'] ?? '')), 0, 150);
            if ($qty < 1 || $qty > self::MAX_QTY || $unit < 0 || $unit > 100000 || $name === '') {
                throw new OrderException(__('A line of this sale is not valid.'));
            }
            $options = array_values(array_map(fn ($o) => [
                'id' => isset($o['id']) ? (int) $o['id'] : null, 'group' => mb_substr((string) ($o['group'] ?? ''), 0, 80),
                'name' => mb_substr((string) ($o['name'] ?? ''), 0, 120), 'price' => round((float) ($o['price'] ?? 0), 2),
            ], array_filter((array) ($l['options'] ?? []), 'is_array')));
            $item = $menu->get((int) ($l['item_id'] ?? 0));
            if (! $item || ! $catalog->isAvailable($item)) {
                $notes[] = __(':item is no longer on the menu.', ['item' => $name]);
            } else {
                $catalog->apply($item);
                $now = round($item->price + array_sum(array_map(fn ($o) => (float) ($item->optionGroups->flatMap->options->firstWhere('id', $o['id'])?->price ?? $o['price']), $options)), 2);
                if (abs($now - $unit) > 0.004) {
                    $notes[] = __(':item was sold at :sold, the menu says :now.', ['item' => $name, 'sold' => money($unit), 'now' => money($now)]);
                }
            }
            $lines[] = [
                'menu_item_id' => $item?->id, 'kitchen_station_id' => $item?->category?->kitchen_station_id,
                'prep_kind' => PrepKind::valid($item?->category?->prep_kind), 'name' => $name, 'unit_price' => $unit,
                'original_price' => null, 'quantity' => $qty, 'options' => $options,
                'notes' => mb_substr(trim((string) ($l['notes'] ?? '')), 0, 200), 'line_total' => round($unit * $qty, 2),
            ];
        }

        $subtotal = round(array_sum(array_column($lines, 'line_total')), 2);
        $discount = round(max(0.0, (float) ($data['discount'] ?? 0)), 2);
        $service = round(max(0.0, (float) ($data['service'] ?? 0)), 2);
        $tax = round(max(0.0, (float) ($data['tax'] ?? 0)), 2);
        $total = round($subtotal - $discount + $service + $tax, 2);
        if ($discount > $subtotal || abs($subtotal - (float) $data['subtotal']) > 0.011 || abs($total - (float) $data['total']) > 0.011) {
            throw new OrderException(__('The amounts of this sale do not add up.'));
        }
        if ($discount > 0 && ! $by->can('pos.discount')) {
            throw new OrderException(__('You are not allowed to give discounts.'));
        }
        $type = ($data['type'] ?? '') === 'dine_in' ? 'dine_in' : 'takeaway';
        $expectedService = $type === 'dine_in' ? round(($subtotal - $discount) * $this->percent('service_percent'), 2) : 0.0;
        $expectedTax = round(($subtotal - $discount + $service) * $this->percent('tax_percent'), 2);
        if (abs($expectedService - $service) > 0.011 || abs($expectedTax - $tax) > 0.011) {
            $notes[] = __('Service / tax differ from today\'s settings.');
        }
        $table = $type === 'dine_in' && ! empty($data['table_id']) ? DiningTable::query()->where('branch_id', $branch->id)->find((int) $data['table_id']) : null;
        if ($parts !== [] && abs(round(array_sum(array_column($parts, 'amount')), 2) - $total) > 0.011) {
            throw new OrderException(__('The amounts of this sale do not add up.'));
        }
        $tendered = isset($data['tendered']) && $method === 'cash' ? round((float) $data['tendered'], 2) : null;
        $parts = $parts ?: ($total > 0 ? self::paymentParts([['method' => $method, 'amount' => $total, 'tendered' => $tendered]]) : []);

        for ($attempt = 1; ; $attempt++) {
            try {
                $order = DB::transaction(function () use ($data, $branch, $by, $shift, $terminal, $lines, $notes, $subtotal, $discount, $service, $tax, $total, $type, $table, $soldAt, $ref, $m, $method, $parts) {
                    $order = Order::query()->create([
                        'branch_id' => $branch->id, 'dining_table_id' => $table?->id,
                        'number' => (int) Order::query()->lockForUpdate()->max('number') + 1,
                        'client_uuid' => $data['client_uuid'], 'tracking_token' => random_token(12),
                        'type' => $type, 'source' => 'pos', 'status' => 'served',
                        'customer_name' => mb_substr(trim((string) ($data['customer_name'] ?? '')), 0, 100),
                        'customer_phone' => normalize_phone((string) ($data['customer_phone'] ?? '')) ?? '',
                        'notes' => mb_substr(trim((string) ($data['notes'] ?? '')), 0, 500) ?: null,
                        'subtotal' => $subtotal, 'discount' => $discount, 'service' => $service, 'tax' => $tax, 'total' => $total,
                        'payment_status' => 'paid', 'payment_method' => $method, 'shift_id' => $shift->id, 'terminal_id' => $terminal?->id,
                        'payments' => $parts,
                        'offline_ref' => $ref, 'created_offline_at' => $soldAt, 'synced_at' => now(),
                        'needs_review' => $notes !== [], 'review_notes' => $notes ?: null,
                        'completed_at' => $soldAt, 'created_by' => $by->id, 'created_at' => $soldAt,
                    ]);
                    foreach ($lines as $line) {
                        $order->items()->create($line);
                    }
                    OrderStatusHistory::query()->create(['order_id' => $order->id, 'to_status' => 'new', 'user_id' => $by->id, 'created_at' => $soldAt]);
                    OrderStatusHistory::query()->create(['order_id' => $order->id, 'from_status' => 'new', 'to_status' => 'served', 'user_id' => $by->id]);
                    app(StockService::class)->deductForOrder($order);
                    if ($terminal && (int) $m[1] > $terminal->offline_seq) {
                        PosTerminal::query()->whereKey($terminal->id)->where('offline_seq', '<', (int) $m[1])->update(['offline_seq' => (int) $m[1]]);
                    }

                    return $order;
                });
                break;
            } catch (QueryException $e) {
                if ($existing = Order::query()->where('client_uuid', $data['client_uuid'])->first()) {
                    return ['order' => $existing, 'duplicate' => true];
                }
                if ($attempt >= 3) {
                    throw $e;
                }
            }
        }
        if ($notes !== []) {
            Audit::record('pos.offline_review', $order->reference(), ['notes' => $notes]);
        }

        return ['order' => $order, 'duplicate' => false];
    }

    /** Moves an order to its next status, stamping the time and recording who did it. */
    public function transition(Order $order, string $to, ?User $by = null, ?string $reason = null): Order
    {
        if ($to === 'cancelled' && $order->refunded_amount > 0) {
            throw new OrderException(__('This order was refunded: it cannot be cancelled as well.'));
        }
        if (! $order->canMoveTo($to)) {
            throw new OrderException(__('This order can no longer be moved to ":status".', ['status' => Order::STATUSES[$to][app()->getLocale() === 'en' ? 1 : 0] ?? $to]));
        }

        return DB::transaction(function () use ($order, $to, $by, $reason) {
            // locked and re-read: two screens cancelling the same order at once must not give the coupon, stock,
            // stamps or wallet money back twice (the second sees the order already cancelled)
            $current = Order::query()->lockForUpdate()->whereKey($order->id)->value('status');
            if ($current !== $order->status) {
                $order->status = $current;
                if (! $order->canMoveTo($to)) {
                    throw new OrderException(__('This order can no longer be moved to ":status".', ['status' => Order::STATUSES[$to][app()->getLocale() === 'en' ? 1 : 0] ?? $to]));
                }
            }
            $from = $order->status;
            $order->status = $to;
            if ($column = self::STAMPS[$to] ?? null) {
                $order->{$column} = now();
            }
            if ($to === 'cancelled') {
                $order->cancel_reason = $reason ? mb_substr($reason, 0, 200) : null;
            }
            DeliveryService::syncWithStatus($order, $from, $to); // the driver's steps follow the order (picked up, delivered)
            $order->save();
            OrderStatusHistory::query()->create(['order_id' => $order->id, 'from_status' => $from, 'to_status' => $to, 'user_id' => $by?->id]);
            // the visit, spend and loyalty stamps count once the guest got the order (or paid, see pay())
            match ($to) {
                'served' => app(LoyaltyService::class)->orderCompleted($order),
                'cancelled' => [app(LoyaltyService::class)->orderReversed($order), app(StockService::class)->restoreForOrder($order),
                    app(WalletService::class)->refundOrder($order, $by), app(CorporateVouchers::class)->refundOrder($order, $by)],
                default => null,
            };
            if ($to === 'cancelled') {
                app(Coupons::class)->release($order);
            }
            if ($to === 'ready') {
                // the whole order is ready: every part with it (food / drinks, every station)
                $order->items()->whereNull('ready_at')->update(['ready_at' => now()]);
            }
            if ($to === 'cancelled' && $order->table_session_id) {
                $order->tableSession?->refreshTotal();
            }
            if (! ($from === 'on_way' && $to === 'ready')) { // a failed delivery back at the restaurant is no "ready" news for the guest
                DB::afterCommit(fn () => app(GuestNotifier::class)->orderMoved($order, $to)); // WhatsApp "ready" / "on the way"
            }

            return $order;
        });
    }

    /**
     * Marks part of an order ready: the lines of one kind (food / drinks), of one kitchen station, or all of them. A
     * new order starts preparing first. Once no line is left the order moves to ready through transition() (the order
     * row is locked, so two screens marking the last two parts at once move it only once).
     */
    public function markReady(Order $order, ?User $by = null, ?string $kind = null, ?int $stationId = null): Order
    {
        return DB::transaction(function () use ($order, $by, $kind, $stationId) {
            $order->status = Order::query()->lockForUpdate()->whereKey($order->id)->value('status');
            if ($order->status === 'cancelled') {
                throw new OrderException(__('This order was cancelled.'));
            }
            if (! in_array($order->status, ['new', 'preparing'], true)) {
                return $order; // already ready (another screen got there first)
            }
            if ($order->status === 'new') {
                $this->transition($order, 'preparing', $by);
            }
            $order->items()->whereNull('ready_at')
                ->when($stationId, fn ($q) => $q->where('kitchen_station_id', $stationId))
                ->when($kind, fn ($q) => $q->where('prep_kind', $kind))
                ->update(['ready_at' => now()]);
            if ($order->items()->whereNull('ready_at')->doesntExist()) {
                $this->transition($order, 'ready', $by);
            }

            return $order;
        });
    }

    /** A dine-in order on a table joins the table's open session, or opens one (the table is locked meanwhile). */
    private function joinTableSession(Order $order, DiningTable $table): void
    {
        DiningTable::query()->lockForUpdate()->whereKey($table->id)->value('id');
        $session = TableSession::query()->open()->where('dining_table_id', $table->id)->latest('id')->first()
            ?? TableSession::query()->create(['branch_id' => $table->branch_id, 'dining_table_id' => $table->id, 'opened_at' => now()]);
        $order->update(['table_session_id' => $session->id]);
        $session->refreshTotal();
    }

    /**
     * One bill for every order of a table session (cancelled ones left out): the lines added up, and the totals as
     * each order was priced (its own discount, service and tax).
     *
     * @return array{orders:Collection<int,Order>, lines:list<array{name:string, options:string, qty:int, unit_price:float, total:float}>,
     *               subtotal:float, discount:float, service:float, tax:float, total:float, paid:float, due:float, unpaid:int, in_kitchen:int}
     */
    public function sessionBill(TableSession $session): array
    {
        $orders = $session->orders()->where('status', '!=', 'cancelled')->with('items')->get();
        $lines = [];
        foreach ($orders->flatMap->items as $item) {
            $options = collect($item->options ?? [])->pluck('name')->implode('، ');
            $key = $item->name.'|'.$options.'|'.$item->unit_price;
            $lines[$key] ??= ['name' => $item->name, 'options' => $options, 'qty' => 0, 'unit_price' => (float) $item->unit_price, 'total' => 0.0];
            $lines[$key]['qty'] += $item->quantity;
            $lines[$key]['total'] = round($lines[$key]['total'] + $item->line_total, 2);
        }
        $sum = fn (string $column) => round((float) $orders->sum($column), 2);
        $paid = round((float) $orders->where('payment_status', 'paid')->sum('total'), 2);

        return [
            'orders' => $orders, 'lines' => array_values($lines),
            'subtotal' => $sum('subtotal'), 'discount' => $sum('discount'), 'service' => $sum('service'), 'tax' => $sum('tax'),
            'total' => $sum('total'), 'paid' => $paid, 'due' => round($sum('total') - $paid, 2),
            'unpaid' => $orders->where('payment_status', '!=', 'paid')->count(),
            'in_kitchen' => $orders->whereIn('status', ['new', 'preparing'])->count(),
        ];
    }

    /**
     * Takes the payment of a whole table session: each unpaid order is paid through pay() (same method, same shift),
     * so the shift drawer, loyalty and the reports see ordinary paid orders. Orders already paid are skipped; all or
     * nothing (one refused order leaves every order unpaid).
     *
     * @return int how many orders were paid now
     */
    public function paySession(TableSession $session, string $method, User $by, ?Shift $shift): int
    {
        if ($method === WalletService::METHOD || $method === CorporateVouchers::METHOD) {
            throw new OrderException(__('Prepaid balance is taken per order from the POS.'));
        }

        return DB::transaction(function () use ($session, $method, $by, $shift) {
            $locked = TableSession::query()->lockForUpdate()->findOrFail($session->id);
            if (! $locked->isOpen()) {
                throw new OrderException(__('This table is already closed.'));
            }
            $unpaid = $locked->orders()->where('status', '!=', 'cancelled')->where('payment_status', '!=', 'paid')->get();
            foreach ($unpaid as $order) {
                $this->pay($order, $method, $by, $shift);
            }

            return $unpaid->count();
        });
    }

    /**
     * Closes a table: every order that is not cancelled must be paid and out of the kitchen. Ready orders are marked
     * served, the open waiter calls of the table are handled and the table is free for the next guests (their first
     * order opens a new session).
     */
    public function closeSession(TableSession $session, User $by): TableSession
    {
        return DB::transaction(function () use ($session, $by) {
            $locked = TableSession::query()->lockForUpdate()->findOrFail($session->id);
            if (! $locked->isOpen()) {
                throw new OrderException(__('This table is already closed.'));
            }
            $orders = $locked->orders()->where('status', '!=', 'cancelled')->get();
            if ($orders->contains(fn (Order $o) => $o->payment_status !== 'paid')) {
                throw new OrderException(__('Some orders of this table are not paid yet. Take the payment or cancel them first.'));
            }
            if ($orders->contains(fn (Order $o) => in_array($o->status, ['new', 'preparing'], true))) {
                throw new OrderException(__('Some orders of this table are still being prepared.'));
            }
            foreach ($orders->whereIn('status', ['ready', 'on_way']) as $order) {
                $this->transition($order, 'served', $by);
            }
            TableCall::query()->where('dining_table_id', $locked->dining_table_id)->whereNull('handled_at')
                ->update(['handled_at' => now(), 'handled_by' => $by->id]);
            $locked->update(['closed_at' => now(), 'closed_by' => $by->id]);
            $locked->refreshTotal();
            $session->setRawAttributes($locked->getAttributes(), true);

            return $session;
        });
    }

    /** @return list<array{menu_item_id:int, name:string, unit_price:float, quantity:int, options:array, notes:string, line_total:float}> */
    private function priceLines(array $items, MenuCatalog $catalog): array
    {
        if ($items === [] || count($items) > self::MAX_LINES) {
            throw new OrderException(__('Your cart is empty.'));
        }

        $ids = array_map(fn ($l) => (int) ($l['item_id'] ?? 0), $items);
        $menu = MenuItem::query()->whereIn('id', $ids)->with(['optionGroups.options', 'comboParts', 'category'])->get()->keyBy('id');

        $lines = [];
        foreach ($items as $l) {
            $item = $menu->get((int) ($l['item_id'] ?? 0));
            $qty = (int) ($l['qty'] ?? 0);
            if (! $item || ! $catalog->isAvailable($item)) {
                throw new OrderException(__('An item in your cart is no longer on the menu. Please review your cart.'));
            }
            $catalog->apply($item);
            if ($item->is_sold_out) {
                throw new OrderException(__(':item is sold out.', ['item' => $item->displayName()]));
            }
            if ($qty < 1 || $qty > self::MAX_QTY) {
                throw new OrderException(__('Choose a quantity between 1 and :max.', ['max' => self::MAX_QTY]));
            }

            $chosen = array_values(array_unique(array_map('intval', (array) ($l['option_ids'] ?? []))));
            $options = $this->options($item, $chosen);
            $extras = array_sum(array_column($options, 'price'));
            // a combo's contents travel with the line so the kitchen sees what to prepare
            foreach ($item->comboParts as $part) {
                $options[] = ['group' => __('Combo'), 'name' => $part->pivot->quantity.'× '.$part->displayName(), 'price' => 0.0];
            }
            $unit = round($item->price + $extras, 2);
            $original = $item->getAttribute('offer_from') !== null ? round($item->getAttribute('offer_from') + $extras, 2) : null;

            $lines[] = [
                'menu_item_id' => $item->id, 'kitchen_station_id' => $item->category?->kitchen_station_id,
                'prep_kind' => PrepKind::valid($item->category?->prep_kind),
                'name' => $item->displayName(), 'unit_price' => $unit, 'original_price' => $original, 'quantity' => $qty, 'options' => $options,
                'notes' => mb_substr(trim((string) ($l['notes'] ?? '')), 0, 200), 'line_total' => round($unit * $qty, 2),
            ];
        }

        return $lines;
    }

    /** Validates the chosen options against the item's groups. @return list<array{id:int, group:string, name:string, price:float}> */
    private function options(MenuItem $item, array $chosen): array
    {
        $out = [];
        $known = [];
        foreach ($item->optionGroups as $group) {
            $picked = $group->options->filter(fn (ItemOption $o) => in_array($o->id, $chosen, true));
            foreach ($picked as $o) {
                $known[] = $o->id;
            }
            $count = $picked->count();
            if ($group->is_required && $count === 0) {
                throw new OrderException(__('Choose :group for :item.', ['group' => tr($group->name, $group->name_en), 'item' => $item->displayName()]));
            }
            if (($group->type === 'single' && $count > 1) || ($group->max_choices && $count > $group->max_choices)) {
                throw new OrderException(__('Too many choices in :group.', ['group' => tr($group->name, $group->name_en)]));
            }
            foreach ($picked as $o) {
                if ($o->is_sold_out) {
                    throw new OrderException(__(':item is sold out.', ['item' => tr($o->name, $o->name_en)]));
                }
                $out[] = ['id' => $o->id, 'group' => tr($group->name, $group->name_en), 'name' => tr($o->name, $o->name_en), 'price' => (float) $o->price];
            }
        }
        if (array_diff($chosen, $known) !== []) {
            throw new OrderException(__('An item in your cart is no longer on the menu. Please review your cart.'));
        }

        return $out;
    }

    private function percent(string $key): float
    {
        return max(0.0, min(100.0, (float) Settings::get($key))) / 100;
    }

    private function customer(string $phone, string $name, string $source = 'menu'): Customer
    {
        $customer = Customer::query()->firstOrCreate(['phone' => $phone], ['name' => mb_substr($name, 0, 100), 'source' => $source]);
        if ($customer->name === '' && $name !== '') {
            $customer->name = mb_substr($name, 0, 100);
        }
        $customer->last_visit_at = now();
        $customer->save();

        return $customer;
    }
}
