<?php

namespace App\Support;

use App\Models\Order;
use App\Services\Orders\DeliveryService;
use Illuminate\Support\Carbon;

/**
 * What the guest reads on the tracking page (/o/{token}) and in its status feed: the steps of the order's type with
 * the time each was reached, the food / drinks readiness, the headline and the expected time. The page renders it
 * once and the polling script redraws it from the same shape, so both always agree.
 */
final class OrderTracking
{
    /** Statuses after which nothing changes any more (the page stops polling). */
    public const FINAL = ['served', 'cancelled'];

    public function __construct(private Order $order, private array $settings) {}

    /**
     * The step keys of an order type, in order.
     *
     * @return list<string>
     */
    public static function stepKeys(string $type): array
    {
        return $type === 'delivery' ? ['new', 'preparing', 'ready', 'on_way', 'served'] : ['new', 'preparing', 'ready', 'served'];
    }

    /**
     * @return array{status:string, label:string, final:bool, cancelled:bool, headline:string, step:int,
     *               steps:list<array{key:string, label:string, done:bool, current:bool, time:?string}>,
     *               parts:list<array{kind:string, label:string, ready:bool}>,
     *               eta:?array{kind:string, at:string, time:string, text:string, late:bool, minutes:?int},
     *               driver:?array{name:string, lat:?float, lng:?float, at:?string, minutes:?int}, now:string}
     */
    public function toArray(): array
    {
        $order = $this->order;
        $keys = self::stepKeys($order->type);
        $cancelled = $order->status === 'cancelled';
        $current = $order->status === 'served' ? count($keys) - 1 : (int) array_search($order->status, $keys, true);
        $onWayAt = $this->onWayAt();
        $times = [
            'new' => $order->created_at, 'preparing' => $order->preparing_at, 'ready' => $order->ready_at,
            'on_way' => $onWayAt, 'served' => $order->completed_at,
        ];

        $steps = [];
        foreach ($keys as $i => $key) {
            $done = ! $cancelled && $i <= $current;
            $steps[] = [
                'key' => $key,
                'label' => $this->stepLabel($key),
                'done' => $done,
                'current' => ! $cancelled && $i === $current && $order->status !== 'served',
                'time' => $done && $times[$key] ? local_time($times[$key], 'g:i A') : null,
            ];
        }

        $parts = [];
        if (in_array($order->status, ['new', 'preparing'], true)) { // split readiness matters until the whole order is ready
            foreach ($order->parts() as $kind => $ready) {
                $parts[] = ['kind' => $kind, 'label' => PrepKind::label($kind), 'ready' => $ready];
            }
        }

        return [
            'status' => $order->status,
            'label' => $order->statusLabel(),
            'final' => in_array($order->status, self::FINAL, true),
            'cancelled' => $cancelled,
            'headline' => $this->headline(),
            'step' => $cancelled ? -1 : $current,
            'steps' => $steps,
            'parts' => $parts,
            'eta' => $this->eta($onWayAt),
            // the driver's first name and latest position, only while this order is out for delivery with them
            'driver' => DeliveryService::guestView($order),
            'now' => now()->toIso8601String(),
        ];
    }

    private function stepLabel(string $key): string
    {
        return match ($key) {
            'new' => __('Sent'),
            'preparing' => __('Preparing'),
            'ready' => $this->order->type === 'takeaway' ? __('Ready for pickup') : __('Ready'),
            'on_way' => __('Out with the rider'),
            'served' => match ($this->order->type) {
                'delivery' => __('Delivered'),
                'takeaway' => __('Picked up'),
                default => __('Served to you'),
            },
            default => $key,
        };
    }

    private function headline(): string
    {
        $order = $this->order;

        return match ($order->status) {
            'new' => $order->scheduled_for?->isFuture() ? __('Your order is booked for the time you chose') : __('Your order reached the restaurant and is waiting to be accepted'),
            'preparing' => __('Your order is being prepared now'),
            'ready' => match (true) {
                $order->type === 'takeaway' => __('Your order is ready, you can pick it up'),
                $order->type === 'delivery' => __('Your order is ready and leaves with the rider soon'),
                $order->dining_table_id !== null => __('Your order is ready and on its way to your table'),
                default => __('Your order is ready!'),
            },
            'on_way' => __('Your order is on its way to you'),
            'served' => match ($order->type) {
                'delivery' => __('Your order was delivered. Enjoy your meal!'),
                'takeaway' => __('You picked up your order. Enjoy your meal!'),
                default => __('Enjoy your meal!'),
            },
            'cancelled' => __('This order was cancelled'),
            default => $order->statusLabel(),
        };
    }

    /** When the order left with the rider (no column of its own: read from the status history). */
    private function onWayAt(): ?Carbon
    {
        if ($this->order->type !== 'delivery' || ! in_array($this->order->status, ['on_way', 'served'], true)) {
            return null;
        }
        $at = $this->order->history()->where('to_status', 'on_way')->value('created_at');

        return $at ? Carbon::parse($at) : null;
    }

    /**
     * The expected time: the scheduled time, else ready = (accepted or placed) + the restaurant's preparation minutes,
     * and for delivery arrival = ready + the zone's minutes when the zone has them.
     *
     * @return ?array{kind:string, at:string, time:string, text:string, late:bool, minutes:?int}
     */
    private function eta(?Carbon $onWayAt): ?array
    {
        $order = $this->order;
        if (in_array($order->status, self::FINAL, true)) {
            return null;
        }
        $prep = self::prepMinutes($this->settings);
        $zoneMinutes = $order->type === 'delivery' ? (int) ($order->deliveryZone?->effective('estimated_minutes') ?? 0) : 0;
        $waiting = in_array($order->status, ['new', 'preparing'], true);

        if ($waiting && $order->scheduled_for?->isFuture()) {
            return $this->etaAt('scheduled', $order->scheduled_for);
        }
        if ($waiting) {
            $readyAt = ($order->preparing_at ?? $order->created_at)->copy()->addMinutes($prep);

            return $zoneMinutes > 0 ? $this->etaAt('arrive', $readyAt->addMinutes($zoneMinutes)) : $this->etaAt('ready', $readyAt);
        }
        if ($zoneMinutes > 0 && in_array($order->status, ['ready', 'on_way'], true)) {
            $from = $order->status === 'on_way' ? ($onWayAt ?? $order->ready_at) : $order->ready_at;

            return $this->etaAt('arrive', ($from ?? now())->copy()->addMinutes($zoneMinutes));
        }

        return null;
    }

    /**
     * The kitchen's preparation minutes of an online order (at least 5): the cart's pickup time and the tracking
     * page's ready time.
     *
     * @param  array<string, mixed>  $settings
     */
    public static function prepMinutes(array $settings): int
    {
        return max(5, (int) ($settings['online_prep_minutes'] ?? 20));
    }

    /**
     * Minutes from sending a delivery order to the door, the same in the cart and on the tracking page: preparation
     * plus the zone's minutes; null when the zone has no time.
     *
     * @param  array<string, mixed>  $settings
     */
    public static function deliveryMinutes(?int $zoneMinutes, array $settings): ?int
    {
        return $zoneMinutes > 0 ? self::prepMinutes($settings) + $zoneMinutes : null;
    }

    /** @return array{kind:string, at:string, time:string, text:string, late:bool, minutes:?int} */
    private function etaAt(string $kind, Carbon $at): array
    {
        $time = local_time($at, 'g:i A');
        $late = $kind !== 'scheduled' && $at->isPast();

        return [
            'kind' => $kind,
            'at' => $at->toIso8601String(),
            'time' => $time,
            'text' => match (true) {
                $late => __('Any minute now'),
                $kind === 'scheduled' => __('Scheduled for :time', ['time' => local_time($at, 'd/m g:i A')]),
                $kind === 'arrive' => __('Expected to arrive around :time', ['time' => $time]),
                default => __('Expected ready around :time', ['time' => $time]),
            },
            'late' => $late,
            'minutes' => $this->minutesLeft($at),
        ];
    }

    /** Whole minutes until $at for the countdown, null when it is past or more than two hours away. */
    private function minutesLeft(Carbon $at): ?int
    {
        $minutes = (int) ceil(now()->diffInSeconds($at, false) / 60);

        return $minutes > 0 && $minutes <= 120 ? $minutes : null;
    }

    /** "About 12 min left" with the Arabic forms of 1, 2, 3–10 and 11+ minutes. */
    public static function minutesLeftText(int $minutes): string
    {
        return match (true) {
            $minutes <= 0 => '',
            $minutes === 1 => __('About a minute left'),
            $minutes === 2 => __('About two minutes left'),
            $minutes <= 10 => __('About :n minutes left', ['n' => $minutes]),
            default => __('About :n min left', ['n' => $minutes]),
        };
    }

    /**
     * The words the tracking script needs to redraw the page on its own (':n' is filled in by the script).
     *
     * @return array{one:string, two:string, few:string, many:string, soon:string, ready:string, preparing:string}
     */
    public static function scriptTexts(): array
    {
        return [
            'one' => __('About a minute left'), 'two' => __('About two minutes left'),
            'few' => __('About :n minutes left'), 'many' => __('About :n min left'),
            'soon' => __('Any minute now'), 'ready' => __('Ready'), 'preparing' => __('Preparing'),
        ];
    }
}
