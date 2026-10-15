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
use App\Models\Shift;
use App\Models\User;
use App\Services\Entitlements;
use App\Services\Inventory\StockService;
use App\Services\Loyalty\LoyaltyService;
use App\Services\Menu\MenuCatalog;
use App\Services\Notify\GuestNotifier;
use App\Services\Settings;
use Illuminate\Database\QueryException;
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
     *              delivery_zone?:DeliveryZone, delivery_address?:array<string,string>} $data
     */
    public function place(array $data, Branch $branch, ?DiningTable $table = null, ?User $by = null): Order
    {
        if (! Entitlements::current()->allows('qr_ordering') && ($data['source'] ?? 'qr') === 'qr') {
            throw new OrderException(__('Ordering is not available right now.'));
        }
        if (Settings::get('ordering_enabled') !== '1' && ($data['source'] ?? 'qr') === 'qr') {
            throw new OrderException(__('Ordering is not available right now.'));
        }
        if ($table && $table->branch_id !== $branch->id) {
            throw new OrderException(__('This table belongs to another branch.'));
        }

        $uuid = $data['client_uuid'] ?? null;
        if ($uuid && ($existing = Order::query()->where('client_uuid', $uuid)->first())) {
            return $existing; // the same submit retried (double tap, flaky network)
        }

        $lines = $this->priceLines($data['items'] ?? [], MenuCatalog::forBranch($branch));
        $subtotal = round(array_sum(array_column($lines, 'line_total')), 2);
        // Only staff (POS) can discount; the caller checks the permission. Never more than the order itself.
        $discount = round(min($subtotal, max(0.0, (float) ($data['discount'] ?? 0))), 2);
        // A guest's coupon code (campaigns): validated now, counted inside the transaction below.
        $coupon = null;
        if (trim((string) ($data['coupon'] ?? '')) !== '') {
            [$coupon, $off] = app(Coupons::class)->check((string) $data['coupon'], $subtotal - $discount, normalize_phone((string) ($data['customer_phone'] ?? '')));
            $discount = round($discount + $off, 2);
        }
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
                return DB::transaction(function () use ($data, $branch, $table, $by, $lines, $subtotal, $discount, $service, $tax, $type, $phone, $uuid, $zone, $deliveryFee, $coupon) {
                    if ($coupon) {
                        app(Coupons::class)->redeem($coupon);
                    }
                    $customer = $phone && Entitlements::current()->allows('customers')
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
                        'coupon_id' => $coupon?->id, 'coupon_code' => $coupon?->code,
                        'delivery_zone_id' => $zone?->id, 'delivery_fee' => $deliveryFee,
                        'delivery_address' => $type === 'delivery' ? ($data['delivery_address'] ?? null) : null,
                        'total' => round($subtotal - $discount + $service + $tax + $deliveryFee, 2), 'created_by' => $by?->id,
                    ]);
                    foreach ($lines as $line) {
                        $order->items()->create($line);
                    }
                    OrderStatusHistory::query()->create(['order_id' => $order->id, 'to_status' => 'new', 'user_id' => $by?->id]);
                    app(StockService::class)->deductForOrder($order); // inventory pack only
                    DB::afterCommit(fn () => app(GuestNotifier::class)->orderPlaced($order)); // WhatsApp "we got your order"

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

    /** Collects the money of an order (POS). Cash goes into the cashier's open shift drawer. */
    public function pay(Order $order, string $method, User $by, ?Shift $shift): Order
    {
        if ($order->payment_status === 'paid') {
            throw new OrderException(__('This order is already paid.'));
        }
        if ($order->status === 'cancelled') {
            throw new OrderException(__('This order was cancelled.'));
        }
        if (! in_array($method, ShiftService::METHODS, true)) {
            throw new OrderException(__('Choose a payment method.'));
        }
        if (! $shift && Entitlements::current()->allows('shifts')) {
            throw new OrderException(__('Open a shift first to take payments.'));
        }
        $order->update(['payment_status' => 'paid', 'payment_method' => $method, 'shift_id' => $shift?->id]);
        app(LoyaltyService::class)->orderCompleted($order);

        return $order;
    }

    /** Moves an order to its next status, stamping the time and recording who did it. */
    public function transition(Order $order, string $to, ?User $by = null, ?string $reason = null): Order
    {
        if (! $order->canMoveTo($to)) {
            throw new OrderException(__('This order can no longer be moved to ":status".', ['status' => Order::STATUSES[$to][app()->getLocale() === 'en' ? 1 : 0] ?? $to]));
        }

        return DB::transaction(function () use ($order, $to, $by, $reason) {
            $from = $order->status;
            $order->status = $to;
            if ($column = self::STAMPS[$to] ?? null) {
                $order->{$column} = now();
            }
            if ($to === 'cancelled') {
                $order->cancel_reason = $reason ? mb_substr($reason, 0, 200) : null;
            }
            $order->save();
            OrderStatusHistory::query()->create(['order_id' => $order->id, 'from_status' => $from, 'to_status' => $to, 'user_id' => $by?->id]);
            // the visit, spend and loyalty stamps count once the guest got the order (or paid, see pay())
            match ($to) {
                'served' => app(LoyaltyService::class)->orderCompleted($order),
                'cancelled' => [app(LoyaltyService::class)->orderReversed($order), app(StockService::class)->restoreForOrder($order)],
                default => null,
            };
            if ($to === 'cancelled') {
                app(Coupons::class)->release($order);
            }
            DB::afterCommit(fn () => app(GuestNotifier::class)->orderMoved($order, $to)); // WhatsApp "ready" / "on the way"

            return $order;
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
