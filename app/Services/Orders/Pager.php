<?php

namespace App\Services\Orders;

use App\Models\Branch;
use App\Models\Order;
use App\Models\PagerTicket;
use App\Models\User;
use App\Services\Entitlements;
use App\Services\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

/**
 * The guest pager (plan feature pager): the guest's own phone replaces the buzzer. The guest scans a QR (on the
 * receipt of a real order, or on a pager-station ticket), keeps the page open, and it rings when staff call or the
 * order turns ready. Nothing to install, no account: the page polls its state every few seconds, so the sound only
 * plays while the page is open on the phone.
 *
 * A pager is either an Order (counter dine-in or takeaway, columns pager_*) or a PagerTicket of the standalone
 * station (for places that take orders on another POS). Every query runs in the restaurant's tenant scope.
 */
final class Pager
{
    /** The guest page counts as open when it asked for its state this recently. */
    public const CONNECTED_SECONDS = 30;

    /** How often the guest page asks for its state while waiting (seconds). */
    public const POLL_SECONDS = 5;

    /** The "last seen" stamp is written at most this often per pager (the page polls more often). */
    private const SEEN_EVERY_SECONDS = 10;

    /** The longest a station ticket may stay open before it expires (setting pager_expire_hours). */
    public const MAX_EXPIRE_HOURS = 24;

    /** Order types a guest waits for at the counter (a delivery guest is at home; a table is served by the waiter). */
    public const ORDER_TYPES = ['dine_in', 'takeaway'];

    public static function enabled(): bool
    {
        return Entitlements::current()->allows('pager');
    }

    /** Whether an order turning ready calls the guest by itself (setting pager_auto_call, on by default). */
    public static function autoCalls(): bool
    {
        return Settings::get('pager_auto_call') === '1';
    }

    /** Hours after which an open station ticket (and an order's pager) closes by itself. */
    public static function expireHours(): int
    {
        return max(1, min(self::MAX_EXPIRE_HOURS, (int) Settings::get('pager_expire_hours')));
    }

    /** Whether an order can carry a pager: a counter dine-in or a takeaway order (no table, no delivery). */
    public static function forOrder(Order $order): bool
    {
        return in_array($order->type, self::ORDER_TYPES, true) && $order->dining_table_id === null;
    }

    /**
     * The order or station ticket behind a pager key from a QR (Order::pagerKey() "{id}-{signature}" or a ticket's
     * token), in the current restaurant only; null when nothing matches.
     */
    public static function find(string $key): Order|PagerTicket|null
    {
        if (preg_match('/^(\d{1,12})-[a-f0-9]{24}$/', $key, $m)) {
            $order = Order::query()->find((int) $m[1]);

            return $order && hash_equals($order->pagerKey(), $key) && self::forOrder($order) ? $order : null;
        }
        if (! preg_match('/^[a-f0-9]{24}$/', $key)) {
            return null;
        }
        $ticket = PagerTicket::query()->where('token', $key)->first();
        if ($ticket && ! $ticket->isClosed() && self::isStale($ticket)) {
            $ticket->update(['status' => 'expired']);
        }

        return $ticket;
    }

    /** Whether the pager is over: the guest picked up, the order is closed, or it waited longer than the expiry. */
    public static function isDone(Order|PagerTicket $pager): bool
    {
        if ($pager instanceof PagerTicket) {
            return $pager->isClosed() || self::isStale($pager);
        }

        return $pager->pager_picked_up_at !== null || in_array($pager->status, ['served', 'cancelled'], true) || self::isStale($pager);
    }

    private static function isStale(Order|PagerTicket $pager): bool
    {
        return $pager->created_at->lt(now()->subHours(self::expireHours()));
    }

    /**
     * Rings the guest's phone (again): one more call with its time. Refused once the pager is over.
     *
     * @throws OrderException
     */
    public static function call(Order|PagerTicket $pager): void
    {
        if (self::isDone($pager)) {
            throw new OrderException(__('This pager is closed: the guest picked up the order or it expired.'));
        }
        if ($pager instanceof PagerTicket) {
            $pager->increment('call_count', 1, ['called_at' => now(), 'status' => 'called', 'first_called_at' => $pager->first_called_at ?? now()]);
        } else {
            $pager->increment('pager_calls', 1, ['pager_called_at' => now()]);
        }
    }

    /**
     * The order turned ready (OrderService::transition): with the pager on and auto-call set, the guest's phone rings
     * by itself. Orders that cannot carry a pager, or whose guest already picked up, are left alone.
     */
    public static function orderReady(Order $order): void
    {
        if (! self::forOrder($order) || $order->pager_picked_up_at !== null || ! self::enabled() || ! self::autoCalls()) {
            return;
        }
        $order->increment('pager_calls', 1, ['pager_called_at' => now()]);
    }

    /** The guest's page asked for its state: "the guest has the page open" on the staff screens. */
    public static function seen(Order|PagerTicket $pager): void
    {
        $column = $pager instanceof PagerTicket ? 'last_seen_at' : 'pager_seen_at';
        if ($pager->{$column} && $pager->{$column}->gt(now()->subSeconds(self::SEEN_EVERY_SECONDS))) {
            return;
        }
        $pager->{$column} = now();
        // straight to the row: being looked at is not a change of the order (updated_at stays)
        $pager->newQuery()->toBase()->where('id', $pager->id)->update([$column => $pager->{$column}]);
    }

    /** The guest (or staff) says the order was picked up: no more calls. */
    public static function pickedUp(Order|PagerTicket $pager): void
    {
        if ($pager instanceof PagerTicket) {
            if (! $pager->isClosed()) {
                $pager->update(['status' => 'picked_up', 'picked_up_at' => now()]);
            }

            return;
        }
        if ($pager->pager_picked_up_at === null) {
            $pager->pager_picked_up_at = now();
            $pager->newQuery()->toBase()->where('id', $pager->id)->update(['pager_picked_up_at' => $pager->pager_picked_up_at]);
        }
    }

    public static function calls(Order|PagerTicket $pager): int
    {
        return (int) ($pager instanceof PagerTicket ? $pager->call_count : $pager->pager_calls);
    }

    private static function calledAt(Order|PagerTicket $pager): ?Carbon
    {
        return $pager instanceof PagerTicket ? $pager->called_at : $pager->pager_called_at;
    }

    private static function seenAt(Order|PagerTicket $pager): ?Carbon
    {
        return $pager instanceof PagerTicket ? $pager->last_seen_at : $pager->pager_seen_at;
    }

    private static function pickedUpAt(Order|PagerTicket $pager): ?Carbon
    {
        return $pager instanceof PagerTicket ? $pager->picked_up_at : $pager->pager_picked_up_at;
    }

    public static function connected(Order|PagerTicket $pager): bool
    {
        $seen = self::seenAt($pager);

        return $seen !== null && $seen->gt(now()->subSeconds(self::CONNECTED_SECONDS));
    }

    /**
     * What the guest page shows and polls: the number, where it stands, how many times staff called (the page rings
     * when this grows), and whether it is over. Nothing personal: no name, phone, items or prices.
     *
     * @return array{kind:string, number:int, phase:string, headline:string, hint:string, calls:int, called_at:?string,
     *               done:bool, picked_up:bool, steps:list<array{key:string, label:string, done:bool, current:bool}>, now:string}
     */
    public static function state(Order|PagerTicket $pager): array
    {
        $done = self::isDone($pager);
        $calls = self::calls($pager);
        $pickedUp = self::pickedUpAt($pager) !== null || ($pager instanceof Order && $pager->status === 'served');
        $phase = match (true) {
            $pickedUp => 'picked_up',
            $pager instanceof Order && $pager->status === 'cancelled' => 'cancelled',
            $done => 'expired',
            $calls > 0 || ($pager instanceof Order && in_array($pager->status, ['ready', 'on_way'], true)) => 'ready',
            $pager instanceof Order && $pager->status === 'preparing' => 'preparing',
            default => 'waiting',
        };

        $keys = $pager instanceof Order ? ['waiting', 'preparing', 'ready', 'picked_up'] : ['waiting', 'ready', 'picked_up'];
        $at = array_search($phase, $keys, true);
        $steps = [];
        foreach ($keys as $i => $key) {
            $steps[] = [
                'key' => $key, 'label' => self::stepLabel($key),
                'done' => $at !== false && $i <= $at, 'current' => $at !== false && $i === $at && $phase !== 'picked_up',
            ];
        }

        return [
            'kind' => $pager instanceof Order ? 'order' : 'ticket',
            'number' => (int) $pager->number,
            'phase' => $phase,
            'headline' => match ($phase) {
                'ready' => __('Your order is ready! Come and pick it up'),
                'preparing' => __('Your order is being prepared now'),
                'picked_up' => __('You picked up your order. Enjoy your meal!'),
                'cancelled' => __('This order was cancelled'),
                'expired' => __('This pager has closed'),
                default => __('We have your order'),
            },
            'hint' => match ($phase) {
                'ready' => __('Show this screen at the counter.'),
                'picked_up', 'cancelled', 'expired' => '',
                default => __('Keep this page open: we will ring you as soon as it is ready.'),
            },
            'calls' => $calls,
            'called_at' => self::calledAt($pager)?->toIso8601String(),
            'done' => $done || $pickedUp,
            'picked_up' => $pickedUp,
            'steps' => $steps,
            'now' => now()->toIso8601String(),
        ];
    }

    private static function stepLabel(string $key): string
    {
        return match ($key) {
            'waiting' => __('The order reached us'),
            'preparing' => __('Preparing'),
            'ready' => __('Ready, come and get it'),
            'picked_up' => __('Picked up'),
            default => $key,
        };
    }

    /**
     * The pager on a staff screen (order board, kitchen, POS, station): the calls so far, the last call's time,
     * whether the guest has the page open right now, and whether it is over.
     *
     * @return array{calls:int, called_at:?string, connected:bool, seen:bool, picked_up:bool, done:bool}
     */
    public static function staffView(Order|PagerTicket $pager): array
    {
        $calledAt = self::calledAt($pager);

        return [
            'calls' => self::calls($pager),
            'called_at' => $calledAt ? local_time($calledAt, 'h:i A') : null,
            'connected' => self::connected($pager),
            'seen' => self::seenAt($pager) !== null,
            'picked_up' => self::pickedUpAt($pager) !== null,
            'done' => self::isDone($pager),
        ];
    }

    /**
     * The words of the pager on the staff screens (resources/js/pager-call.js); __N__ / __T__ are filled by the script.
     *
     * @return array<string,string>
     */
    public static function staffTexts(): array
    {
        return [
            'call' => __('Call the guest'), 'whatsapp' => __('Send on WhatsApp'), 'print' => __('Pager ticket'),
            'connected' => __('The guest has the page open'), 'away' => __('Page closed: call out the number'),
            'never' => __('QR not scanned yet'), 'pickedUp' => __('The guest picked it up'),
            'called' => __('Called :n× · :time', ['n' => '__N__', 'time' => '__T__']),
        ];
    }

    /** Closes the station tickets nobody closed within the expiry (read lazily by the station and the guest page). */
    public static function expireStale(?int $branchId = null): int
    {
        return PagerTicket::query()->whereIn('status', ['waiting', 'called'])->where('created_at', '<', now()->subHours(self::expireHours()))
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->update(['status' => 'expired']);
    }

    /** The next free number of the branch today (the station's "+1"). */
    public static function nextNumber(Branch $branch): int
    {
        return (int) PagerTicket::query()->where('branch_id', $branch->id)->whereDate('service_date', local_today())->max('number') + 1;
    }

    /**
     * Opens a station ticket for the number staff typed (the other POS's order number) or the next free one. A number
     * is used once per branch and day.
     *
     * @throws OrderException
     */
    public static function open(Branch $branch, ?int $number, ?User $by): PagerTicket
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $n = $number ?? self::nextNumber($branch);
            if ($number !== null && PagerTicket::query()->where('branch_id', $branch->id)->whereDate('service_date', local_today())->where('number', $n)->exists()) {
                throw new OrderException(__('Number :n is already in today’s queue.', ['n' => $n]));
            }
            try {
                return PagerTicket::query()->create([
                    'branch_id' => $branch->id, 'number' => $n, 'service_date' => local_today(),
                    'token' => bin2hex(random_bytes(12)), 'status' => 'waiting', 'created_by' => $by?->id,
                ]);
            } catch (QueryException $e) {
                if ($number !== null || ! str_contains(strtolower($e->getMessage()), 'unique')) {
                    throw $number !== null ? new OrderException(__('Number :n is already in today’s queue.', ['n' => $n])) : $e;
                }
                // two screens took the same next number at once: take the one after
            }
        }

        throw new OrderException(__('Could not open the ticket, try again.'));
    }

    /**
     * The pager figures of the reports for a range: how many orders and tickets were paged, the average wait from
     * the order to ready / the first call and from there to the pickup the guest confirmed, the calls, and the share
     * of guests who had the page open. Per day, and in total.
     *
     * @return array{total: array{paged:int, calls:int, connected:?int, wait:?int, pickup:?int},
     *               days: list<array{day:string, paged:int, calls:int, connected:?int, wait:?int, pickup:?int}>}
     */
    public static function report(CarbonImmutable $from, CarbonImmutable $to, ?int $branchId): array
    {
        $start = local_day_start($from);
        $end = local_day_end($to);
        $rows = [];

        $orders = Order::query()->whereBetween('created_at', [$start, $end])->where('status', '!=', 'cancelled')
            ->whereIn('type', self::ORDER_TYPES)->whereNull('dining_table_id')
            ->where(fn ($q) => $q->where('pager_calls', '>', 0)->orWhereNotNull('pager_seen_at'))
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->select(['id', 'created_at', 'ready_at', 'pager_calls', 'pager_called_at', 'pager_seen_at', 'pager_picked_up_at']);
        foreach ($orders->cursor() as $o) {
            $readyAt = $o->ready_at ?? $o->pager_called_at;
            $rows[] = [$o->created_at, $readyAt, $o->pager_picked_up_at, (int) $o->pager_calls, $o->pager_seen_at !== null];
        }
        $tickets = PagerTicket::query()->whereBetween('created_at', [$start, $end])
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->select(['id', 'created_at', 'first_called_at', 'picked_up_at', 'call_count', 'last_seen_at']);
        foreach ($tickets->cursor() as $t) {
            $rows[] = [$t->created_at, $t->first_called_at, $t->picked_up_at, (int) $t->call_count, $t->last_seen_at !== null];
        }

        $byDay = [];
        foreach ($rows as $row) {
            $byDay[local_time($row[0], 'Y-m-d')][] = $row;
        }
        ksort($byDay);
        $days = [];
        foreach ($byDay as $day => $list) {
            $days[] = ['day' => $day] + self::summarize($list);
        }

        return ['total' => self::summarize($rows), 'days' => $days];
    }

    /**
     * @param  list<array{0:Carbon, 1:?Carbon, 2:?Carbon, 3:int, 4:bool}>  $rows  [created, ready / first call, picked up, calls, page seen]
     * @return array{paged:int, calls:int, connected:?int, wait:?int, pickup:?int}
     */
    private static function summarize(array $rows): array
    {
        $minutes = fn (?Carbon $a, ?Carbon $b) => $a && $b && $b->gte($a) ? $a->diffInSeconds($b) / 60 : null;
        $avg = fn (array $values) => $values === [] ? null : (int) round(array_sum($values) / count($values));
        $waits = array_values(array_filter(array_map(fn ($r) => $minutes($r[0], $r[1]), $rows), fn ($v) => $v !== null));
        $pickups = array_values(array_filter(array_map(fn ($r) => $minutes($r[1], $r[2]), $rows), fn ($v) => $v !== null));
        $count = count($rows);

        return [
            'paged' => $count,
            'calls' => array_sum(array_column($rows, 3)),
            'connected' => $count ? (int) round(count(array_filter(array_column($rows, 4))) * 100 / $count) : null,
            'wait' => $avg($waits),
            'pickup' => $avg($pickups),
        ];
    }
}
