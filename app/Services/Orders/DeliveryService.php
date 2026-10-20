<?php

namespace App\Services\Orders;

use App\Models\DeliveryTrackPoint;
use App\Models\DriverLocation;
use App\Models\Order;
use App\Models\Shift;
use App\Models\User;
use App\Services\Audit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Delivery drivers: a manager / cashier gives a delivery order to a driver, the driver picks it up (the order moves
 * to on_way), delivers it (served) or reports that it failed with a reason (the order is back at the restaurant:
 * on_way → ready, and can be given to another driver or cancelled). The order's own status always moves through
 * OrderService::transition(), which calls syncWithStatus() so a move made on the board keeps the driver's steps
 * right as well. The cash a driver collects at the door stays on the order until a manager settles it: then the
 * unpaid orders are paid in cash through OrderService::pay() into the manager's shift, like any POS payment.
 */
final class DeliveryService
{
    /** How often the driver screen sends its position while an order is out for delivery. */
    public const PING_SECONDS = 20;

    /** Positions closer together than this are not stored (a second tab, a retry). */
    public const MIN_PING_SECONDS = 8;

    /** A position less precise than this (metres) is not stored. */
    public const MAX_ACCURACY = 5000;

    /** Points kept per order for its trail (about four hours at one point every 20 seconds). */
    public const TRAIL_POINTS = 720;

    /** The guest stops seeing a position older than this. */
    public const FRESH_MINUTES = 15;

    public function __construct(private OrderService $orders) {}

    /**
     * Active drivers who can take an order of $branchId: those of every branch and those of that branch.
     *
     * @return Collection<int,User>
     */
    public static function drivers(?int $branchId = null): Collection
    {
        return self::driverQuery()->where('is_active', true)
            ->when($branchId, fn ($q) => $q->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $branchId)))
            ->orderBy('name')->get();
    }

    /** Users with the driver role (a whereHas, so a database where the role was not seeded yet simply has none). */
    public static function driverQuery(): Builder
    {
        return User::query()->whereHas('roles', fn ($q) => $q->where('name', 'driver'));
    }

    /**
     * Orders a driver is working on: given to them and not delivered, failed, served or cancelled.
     *
     * @return Builder<Order>
     */
    public static function activeFor(User $driver): Builder
    {
        return Order::query()->where('driver_id', $driver->id)->whereIn('delivery_status', ['assigned', 'picked_up'])
            ->whereNotIn('status', ['served', 'cancelled']);
    }

    /** Gives a delivery order to a driver, gives it to another one, or takes it back ($driver null). */
    public function assign(Order $order, ?User $driver, User $by): Order
    {
        if ($order->type !== 'delivery') {
            throw new OrderException(__('Only delivery orders go with a driver.'));
        }
        if ($driver && (! $driver->is_active || ! $driver->hasRole('driver'))) {
            throw new OrderException(__('Choose an active driver.'));
        }
        if ($driver && $driver->branch_id && $driver->branch_id !== $order->branch_id) {
            throw new OrderException(__('This driver works for another branch.'));
        }

        return DB::transaction(function () use ($order, $driver, $by) {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            if (in_array($locked->status, ['served', 'cancelled'], true)) {
                throw new OrderException(__('This order is already closed.'));
            }
            if ($locked->delivery_status === 'picked_up') {
                throw new OrderException(__('The driver already left with this order: it must be delivered or marked failed first.'));
            }
            $order->forceFill([
                'driver_id' => $driver?->id, 'delivery_status' => $driver ? 'assigned' : null,
                'driver_assigned_at' => $driver ? now() : null, 'picked_up_at' => null, 'delivered_at' => null,
                'delivery_failed_at' => null, 'delivery_fail_reason' => null,
            ])->save();
            Audit::record('order.driver', '#'.$order->number, ['id' => $order->id, 'driver' => $driver?->id, 'by' => $by->id]);

            return $order;
        });
    }

    /** The driver took the order from the restaurant: it is out for delivery (on_way). */
    public function pickUp(Order $order, User $driver): Order
    {
        $this->ensureTheirs($order, $driver, ['assigned']);
        if (in_array($order->status, ['new', 'preparing'], true)) {
            throw new OrderException(__('The order is not ready yet.'));
        }

        return $this->orders->transition($order, 'on_way', $driver);
    }

    /** The guest got the order: it is served, and what was collected at the door is noted for the driver. */
    public function deliver(Order $order, User $driver): Order
    {
        $this->ensureTheirs($order, $driver, ['picked_up']);

        return $this->orders->transition($order, 'served', $driver);
    }

    /** The delivery did not happen: noted with its reason, and an order out for delivery is back at the restaurant. */
    public function fail(Order $order, User $driver, string $reason): Order
    {
        $this->ensureTheirs($order, $driver, ['assigned', 'picked_up']);
        $reason = mb_substr(trim($reason), 0, 200);
        if ($reason === '') {
            throw new OrderException(__('Write why the delivery failed.'));
        }

        return DB::transaction(function () use ($order, $driver, $reason) {
            $order->forceFill(['delivery_status' => 'failed', 'delivery_failed_at' => now(), 'delivery_fail_reason' => $reason]);
            if ($order->status === 'on_way') {
                $this->orders->transition($order, 'ready', $driver); // saves the order with the fields above
            } else {
                $order->save();
            }
            Audit::record('delivery.failed', '#'.$order->number, ['id' => $order->id, 'driver' => $driver->id, 'reason' => $reason]);

            return $order;
        });
    }

    /**
     * Keeps the driver's steps in line with a move of the order itself (called by OrderService::transition() before
     * it saves): on_way = picked up, served = delivered (with the cash to collect), on_way → ready = failed.
     */
    public static function syncWithStatus(Order $order, string $from, string $to): void
    {
        if (! $order->driver_id) {
            return;
        }
        if ($to === 'on_way' && $order->delivery_status === 'assigned') {
            $order->forceFill(['delivery_status' => 'picked_up', 'picked_up_at' => now()]);
        } elseif ($to === 'served' && in_array($order->delivery_status, ['assigned', 'picked_up'], true)) {
            $order->forceFill([
                'delivery_status' => 'delivered', 'delivered_at' => now(), 'picked_up_at' => $order->picked_up_at ?? now(),
                'cash_collected' => $order->amountToCollect(),
            ]);
        } elseif ($to === 'ready' && $from === 'on_way' && $order->delivery_status === 'picked_up') {
            $order->forceFill(['delivery_status' => 'failed', 'delivery_failed_at' => now(), 'delivery_fail_reason' => __('Back at the restaurant')]);
        }
    }

    /**
     * Stores the position the driver's phone sent. Only accepted while one of the driver's orders is out for delivery;
     * positions closer together than MIN_PING_SECONDS, or too imprecise, are skipped.
     *
     * @return array{stored:bool, orders:int}
     */
    public function recordLocation(User $driver, float $lat, float $lng, ?int $accuracy = null): array
    {
        $out = Order::query()->where('driver_id', $driver->id)->where('delivery_status', 'picked_up')->where('status', 'on_way')
            ->orderBy('picked_up_at')->pluck('id');
        if ($out->isEmpty()) {
            throw new OrderException(__('No order is out for delivery with you.'));
        }
        $last = DriverLocation::query()->where('user_id', $driver->id)->first();
        if (($last && $last->recorded_at->gt(now()->subSeconds(self::MIN_PING_SECONDS))) || ($accuracy !== null && $accuracy > self::MAX_ACCURACY)) {
            return ['stored' => false, 'orders' => $out->count()];
        }
        $lat = round($lat, 7);
        $lng = round($lng, 7);
        DriverLocation::query()->updateOrCreate(['user_id' => $driver->id], [
            'order_id' => $out->first(), 'lat' => $lat, 'lng' => $lng, 'accuracy' => $accuracy, 'recorded_at' => now(),
        ]);
        foreach ($out as $orderId) {
            if (DeliveryTrackPoint::query()->where('order_id', $orderId)->count() < self::TRAIL_POINTS) {
                DeliveryTrackPoint::query()->create(['order_id' => $orderId, 'user_id' => $driver->id, 'lat' => $lat, 'lng' => $lng, 'recorded_at' => now()]);
            }
        }

        return ['stored' => true, 'orders' => $out->count()];
    }

    /**
     * What the guest following $order sees of its driver: the first name and the latest position, only while this
     * order is out for delivery with them (never before pick-up, never after delivered / failed).
     *
     * @return ?array{name:string, lat:?float, lng:?float, at:?string, minutes:?int}
     */
    public static function guestView(Order $order): ?array
    {
        if ($order->type !== 'delivery' || $order->status !== 'on_way' || $order->delivery_status !== 'picked_up' || ! $order->driver_id) {
            return null;
        }
        $driver = User::query()->find($order->driver_id);
        if (! $driver) {
            return null;
        }
        // a position from before this pick-up (the previous trip) or too old is not shown
        $since = now()->subMinutes(self::FRESH_MINUTES);
        if ($order->picked_up_at && $order->picked_up_at->gt($since)) {
            $since = $order->picked_up_at;
        }
        $location = DriverLocation::query()->where('user_id', $driver->id)->where('recorded_at', '>=', $since)->first();

        return [
            'name' => (string) strtok(trim($driver->name), ' '),
            'lat' => $location?->lat, 'lng' => $location?->lng,
            'at' => $location?->recorded_at->toIso8601String(),
            'minutes' => $location ? (int) $location->recorded_at->diffInMinutes(now()) : null,
        ];
    }

    /**
     * Pays the driver's collected cash in: every delivered order whose cash is not settled yet. Orders still unpaid
     * are paid in cash by $by into $shift (the shift drawer counts them); orders the POS already took the money for
     * are only marked settled. All or nothing.
     *
     * @return array{orders:int, amount:float}
     */
    public function settle(User $driver, User $by, ?Shift $shift, ?int $branchId = null): array
    {
        return DB::transaction(function () use ($driver, $by, $shift, $branchId) {
            $orders = self::unsettled($driver, $branchId)->lockForUpdate()->get();
            if ($orders->isEmpty()) {
                throw new OrderException(__('This driver has no cash to settle.'));
            }
            foreach ($orders as $order) {
                if ($order->payment_status !== 'paid') {
                    $this->orders->pay($order, 'cash', $by, $shift);
                }
                $order->forceFill(['cash_settled_at' => now(), 'cash_settled_by' => $by->id])->save();
            }
            $amount = round((float) $orders->sum('cash_collected'), 2);
            Audit::record('driver.settled', $driver->name, ['driver' => $driver->id, 'orders' => $orders->count(), 'amount' => $amount]);

            return ['orders' => $orders->count(), 'amount' => $amount];
        });
    }

    /**
     * Delivered orders of a driver whose collected cash was not handed in yet.
     *
     * @return Builder<Order>
     */
    public static function unsettled(User $driver, ?int $branchId = null): Builder
    {
        return Order::query()->where('driver_id', $driver->id)->where('delivery_status', 'delivered')->where('status', 'served')
            ->where('cash_collected', '>', 0)->whereNull('cash_settled_at')
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId));
    }

    /** "Open in Google Maps" for the address: the pinned point when there is one, else the written address. */
    public static function mapsUrl(Order $order): ?string
    {
        $a = (array) ($order->delivery_address ?? []);
        if (isset($a['lat'], $a['lng']) && is_numeric($a['lat']) && is_numeric($a['lng'])) {
            return 'https://www.google.com/maps/dir/?api=1&destination='.((float) $a['lat']).','.((float) $a['lng']);
        }
        $text = trim(($a['text'] ?? '').' '.($a['zone'] ?? ''));

        return $text === '' ? null : 'https://www.google.com/maps/dir/?api=1&destination='.rawurlencode($text);
    }

    /** @param list<string> $steps the delivery statuses the action starts from */
    private function ensureTheirs(Order $order, User $driver, array $steps): void
    {
        if ($order->driver_id !== $driver->id) {
            throw new OrderException(__('This order is not with you.'));
        }
        if (in_array($order->status, ['served', 'cancelled'], true) || ! in_array($order->delivery_status, $steps, true)) {
            throw new OrderException(__('This order can no longer be updated.'));
        }
    }
}
