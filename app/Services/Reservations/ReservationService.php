<?php

namespace App\Services\Reservations;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Reservation;
use App\Models\User;
use App\Services\Entitlements;
use App\Services\Notify\ReservationNotifier;
use App\Services\Push\StaffPush;
use App\Services\Settings;
use App\Support\OpeningHours;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Table reservations. A day is cut into slots (every reservation_slot_minutes within the opening hours); a booking
 * holds party_size seats from its start for reservation_duration_minutes, and at no moment may the bookings of a
 * branch hold more seats than it offers. Guests must book lead_hours ahead and at most horizon_days ahead; staff
 * booking by hand skip those two rules but never the seats.
 */
final class ReservationService
{
    public const DEFAULT_SEATS = 20;

    /** Upcoming bookings one mobile number may hold from the menu link (so one person can't fill the seats). */
    public const MAX_PER_PHONE = 3;

    public function __construct(private ReservationNotifier $notifier) {}

    /** Reservations are on for guests: the plan / add-on allows them and the restaurant switched them on. */
    public static function enabled(): bool
    {
        return tenant() !== null && Entitlements::current()->allows('reservations') && Settings::get('reservations_enabled') === '1';
    }

    /** @return array{slot:int, duration:int, max_party:int, lead_hours:int, horizon_days:int, auto_confirm:bool} */
    public static function config(): array
    {
        $s = Settings::all();
        $clamp = fn (string $key, int $min, int $max) => max($min, min($max, (int) $s[$key]));

        return [
            'slot' => $clamp('reservation_slot_minutes', 15, 120),
            'duration' => $clamp('reservation_duration_minutes', 30, 360),
            'max_party' => $clamp('reservation_max_party', 1, 100),
            'lead_hours' => $clamp('reservation_lead_hours', 0, 72),
            'horizon_days' => $clamp('reservation_horizon_days', 1, 180),
            'auto_confirm' => $s['reservation_auto_confirm'] === '1',
        ];
    }

    /** @return array<int,int> branch id => seats */
    public static function seatsMap(): array
    {
        $map = json_decode(Settings::get('reservation_seats'), true);

        return is_array($map) ? array_map('intval', $map) : [];
    }

    public static function seats(Branch $branch): int
    {
        return max(0, self::seatsMap()[$branch->id] ?? self::DEFAULT_SEATS);
    }

    /**
     * Custom hours per weekday (Sunday = 0) from the settings, or null when the restaurant uses its branches'
     * working hours.
     *
     * @return array<int, array{open:string, close:string}>|null
     */
    public static function customHours(): ?array
    {
        $hours = json_decode(Settings::get('reservation_hours'), true);

        return is_array($hours) && $hours !== [] ? $hours : null;
    }

    /**
     * When the branch takes guests on $day (its date in restaurant time): [open, close] as UTC instants (close may be
     * after midnight), or null when closed.
     *
     * @return array{0:Carbon, 1:Carbon}|null
     */
    public function hours(Branch $branch, CarbonInterface $day): ?array
    {
        $date = Carbon::parse(local_today($day), local_timezone())->startOfDay();
        $valid = fn ($t) => is_string($t) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t);
        if ($custom = self::customHours()) {
            $h = $custom[$date->dayOfWeek] ?? $custom[(string) $date->dayOfWeek] ?? null;
            [$open, $close] = [$h['open'] ?? null, $h['close'] ?? null];
            if (! $valid($open) || ! $valid($close)) {
                return null; // closed that day
            }
        } elseif (preg_match('/(\d{2}:\d{2})-(\d{2}:\d{2})$/', (string) OpeningHours::toSchema($branch->working_hours), $m)) {
            [$open, $close] = [$m[1], $m[2]];
        } else {
            [$open, $close] = ['12:00', '23:00']; // working hours not set or not readable
        }
        $from = $date->copy()->setTimeFromTimeString($open);
        $to = $date->copy()->setTimeFromTimeString($close);
        if ($to->lte($from)) {
            $to->addDay();
        }

        return [$from->utc(), $to->utc()];
    }

    /**
     * The slots of $day with the seats still free in each. Guests only get slots inside the lead time / horizon;
     * `fits` says whether $party still fits.
     *
     * @return list<array{time:string, at:Carbon, left:int, fits:bool}>
     */
    public function slots(Branch $branch, CarbonInterface $day, int $party = 1, bool $staff = false): array
    {
        $hours = $this->hours($branch, $day);
        if (! $hours) {
            return [];
        }
        [$from, $to] = $hours;
        $c = self::config();
        $earliest = $staff ? now()->subMinutes($c['slot']) : now()->addHours($c['lead_hours']);
        $latest = $staff ? null : local_day_end(local_at()->addDays($c['horizon_days']));
        $seats = self::seats($branch);
        $bookings = $this->holding($branch, $from, $to->copy()->addMinutes($c['duration']));

        $out = [];
        for ($t = $from->copy(); $t->copy()->addMinutes($c['slot'])->lte($to); $t->addMinutes($c['slot'])) {
            if ($t->lt($earliest) || ($latest && $t->gt($latest))) {
                continue;
            }
            $left = max(0, $seats - $this->peak($bookings, $t, $t->copy()->addMinutes($c['duration'])));
            $out[] = ['time' => local_time($t, 'H:i'), 'at' => $t->copy(), 'left' => $left, 'fits' => $left >= $party];
        }

        return $out;
    }

    /** Seats still free for a booking starting at $start (the busiest moment of its stay counts). */
    public function seatsLeft(Branch $branch, CarbonInterface $start, ?int $ignore = null): int
    {
        $end = Carbon::parse($start)->addMinutes(self::config()['duration']);
        $bookings = $this->holding($branch, $start, $end)->when($ignore, fn ($c) => $c->where('id', '!=', $ignore));

        return self::seats($branch) - $this->peak($bookings, Carbon::parse($start), $end);
    }

    /**
     * Books a table. $by = the staff member booking by hand (lead time / horizon don't apply, status confirmed);
     * null = a guest from the menu link (pending, or confirmed when the restaurant auto-confirms).
     *
     * @param  array{date:string, time:string, party_size:int|string, name:string, phone:string, notes?:string|null}  $data
     */
    public function book(array $data, Branch $branch, ?User $by = null): Reservation
    {
        $staff = $by !== null;
        if (! $staff && ! self::enabled()) {
            throw new ReservationException(__('Reservations are not available right now.'));
        }
        $c = self::config();
        $party = (int) $data['party_size'];
        if ($party < 1 || $party > $c['max_party']) {
            throw new ReservationException(__('For more than :n guests, please call the restaurant.', ['n' => $c['max_party']]));
        }
        $phone = normalize_phone((string) $data['phone']);
        if (! $phone) {
            throw new ReservationException(__('Enter a valid Egyptian mobile number.'));
        }
        $name = mb_substr(trim((string) $data['name']), 0, 100);
        if ($name === '') {
            throw new ReservationException(__('Write your name.'));
        }
        try {
            $at = local_input($data['date'].' '.$data['time'], 'Y-m-d H:i')->startOfMinute();
        } catch (\Throwable) {
            throw new ReservationException(__('Choose a time.'));
        }
        if (! $staff && $at->lt(now()->addHours($c['lead_hours']))) {
            throw new ReservationException(__('Please book at least :n hours ahead.', ['n' => $c['lead_hours']]));
        }
        if (! $staff && $at->gt(local_day_end(local_at()->addDays($c['horizon_days'])))) {
            throw new ReservationException(__('You can book up to :n days ahead.', ['n' => $c['horizon_days']]));
        }
        // the slot must be one the restaurant offers that day (or, after midnight, the day before)
        $offered = collect([$at, $at->copy()->subDay()])->contains(fn ($day) => collect($this->slots($branch, $day, $party, $staff))
            ->contains(fn ($s) => $s['at']->eq($at)));
        if (! $offered) {
            throw new ReservationException(__('The restaurant does not take bookings at this time.'));
        }

        $reservation = DB::transaction(function () use ($data, $branch, $by, $staff, $c, $party, $phone, $name, $at) {
            // one booking at a time per branch, so two guests can never take the same last seats
            Branch::query()->whereKey($branch->id)->lockForUpdate()->first();
            if (! $staff && Reservation::query()->where('phone', $phone)->whereIn('status', ['pending', 'confirmed'])
                ->where('starts_at', '>', now())->count() >= self::MAX_PER_PHONE) {
                throw new ReservationException(__('You already have :n upcoming bookings. Call the restaurant to book more.', ['n' => self::MAX_PER_PHONE]));
            }
            if ($this->seatsLeft($branch, $at) < $party) {
                throw new ReservationException(__('Sorry, there are not enough seats left at this time. Please choose another time.'));
            }
            $customer = Entitlements::current()->allows('customers') ? $this->customer($phone, $name) : null;
            $status = $staff || $c['auto_confirm'] ? 'confirmed' : 'pending';

            return Reservation::query()->create([
                'branch_id' => $branch->id, 'customer_id' => $customer?->id, 'name' => $name, 'phone' => $phone,
                'party_size' => $party, 'starts_at' => $at, 'ends_at' => $at->copy()->addMinutes($c['duration']),
                'status' => $status, 'confirmed_at' => $status === 'confirmed' ? now() : null,
                'notes' => mb_substr(trim((string) ($data['notes'] ?? '')), 0, 500) ?: null,
                'source' => $staff ? 'staff' : 'guest', 'token' => random_token(12), 'created_by' => $by?->id,
            ]);
        });
        $this->notifier->created($reservation);
        if ($by === null) {
            app(StaffPush::class)->reservationCreated($reservation); // a guest's booking: the staff's phones (EgHub app)
        }

        return $reservation;
    }

    /** Staff moves a booking (confirm, seat, cancel, no-show); every move stamps its time. */
    public function transition(Reservation $reservation, string $to): Reservation
    {
        if (! $reservation->canMoveTo($to)) {
            throw new ReservationException(__('This reservation can no longer be moved to ":status".', ['status' => Reservation::statusLabelOf($to)]));
        }
        $from = $reservation->status;
        $reservation->status = $to;
        if ($column = Reservation::STAMPS[$to] ?? null) {
            $reservation->{$column} = now();
        }
        $reservation->save();
        if ($to === 'seated' && $reservation->customer_id) {
            $reservation->customer()->update(['last_visit_at' => now()]);
        }
        $this->notifier->statusChanged($reservation, $from);

        return $reservation;
    }

    /** The guest cancels from their reservation page (only while it is still ahead). */
    public function cancelByGuest(Reservation $reservation): Reservation
    {
        if (! $reservation->guestCanCancel()) {
            throw new ReservationException(__('This reservation can no longer be cancelled.'));
        }

        return $this->transition($reservation, 'cancelled');
    }

    /** Bookings of the branch that hold seats at some point between $from and $to. */
    private function holding(Branch $branch, CarbonInterface $from, CarbonInterface $to): Collection
    {
        return Reservation::query()->where('branch_id', $branch->id)->whereIn('status', Reservation::ACTIVE)
            ->where('starts_at', '<', $to)->where('ends_at', '>', $from)->get(['id', 'starts_at', 'ends_at', 'party_size']);
    }

    /** The most seats held at once between $start and $end (occupancy only rises when a booking starts). */
    private function peak(Collection $bookings, CarbonInterface $start, CarbonInterface $end): int
    {
        $overlapping = $bookings->filter(fn (Reservation $b) => $b->starts_at->lt($end) && $b->ends_at->gt($start));
        $moments = $overlapping->map(fn (Reservation $b) => $b->starts_at->gt($start) ? $b->starts_at : $start)->push($start);
        $peak = 0;
        foreach ($moments as $moment) {
            $held = $overlapping->filter(fn (Reservation $b) => $b->starts_at->lte($moment) && $b->ends_at->gt($moment))->sum('party_size');
            $peak = max($peak, (int) $held);
        }

        return $peak;
    }

    private function customer(string $phone, string $name): Customer
    {
        $customer = Customer::query()->firstOrCreate(['phone' => $phone], ['name' => $name, 'source' => 'reservation']);
        if ($customer->name === '' && $name !== '') {
            $customer->update(['name' => $name]);
        }

        return $customer;
    }
}
