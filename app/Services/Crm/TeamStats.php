<?php

namespace App\Services\Crm;

use App\Models\Appointment;
use App\Models\Invoice;
use App\Models\LeadEvent;
use App\Models\PlatformAdmin;
use App\Models\Prospect;
use App\Models\ProspectEvent;
use App\Support\SalesPlan;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The numbers of the sales team: what each person did in a period (calls, booked visits, visits, new places, trials,
 * paid subscriptions), against the month's targets, and the month's pay estimate under SalesPlan (base, commission,
 * per visited appointment, target bonus, field allowances, phone line). Read-only; nothing here is paid automatically.
 */
final class TeamStats
{
    /** Invoice kinds that count as a subscription for the commission. */
    public const SUBSCRIPTION_KINDS = ['subscription', 'upgrade'];

    /** The people of the team: active admins on a sales seat. @return Collection<int, PlatformAdmin> */
    public function members(): Collection
    {
        return PlatformAdmin::query()->active()->whereIn('role', array_keys(SalesPlan::SEATS))->orderBy('role')->orderBy('name')->get();
    }

    /**
     * @return array{calls: int, appointments: int, visits: int, visited_appointments: int, places: int, trials: int, paid: int}
     */
    public function counts(PlatformAdmin $admin, Carbon $from, Carbon $to): array
    {
        $between = fn ($q, string $column) => $q->where($column, '>=', $from)->where($column, '<', $to);

        return [
            'calls' => $between(ProspectEvent::query()->where('admin_id', $admin->id)->whereIn('type', ['call', 'whatsapp']), 'created_at')->count()
                + $between(LeadEvent::query()->where('admin_id', $admin->id)->whereIn('type', ['call', 'whatsapp']), 'created_at')->count(),
            'appointments' => $between(Appointment::query()->where('set_by', $admin->id), 'created_at')->count(),
            'visits' => $between(Appointment::query()->where('field_id', $admin->id)->where('status', 'done'), 'reported_at')->count(),
            'visited_appointments' => $between(Appointment::query()->where('set_by', $admin->id)->where('status', 'done'), 'reported_at')->count(),
            'places' => $between(Prospect::query()->where('created_by', $admin->id)->where('source', 'field'), 'created_at')->count(),
            'trials' => $between(Prospect::query()->where(fn ($q) => $q->where('caller_id', $admin->id)->orWhere('field_id', $admin->id)), 'linked_at')->count(),
            'paid' => $between(Prospect::query()->where('closer_id', $admin->id), 'won_at')->count(),
        ];
    }

    /** The UTC bounds of a month (YYYY-MM) in local time. @return array{0: Carbon, 1: Carbon} */
    public static function monthBounds(string $month): array
    {
        $start = Carbon::createFromFormat('Y-m-d H:i:s', "$month-01 00:00:00", local_timezone());

        return [$start->copy()->utc(), $start->copy()->addMonth()->utc()];
    }

    /**
     * One person's month: counts, today's calls, targets and progress (% per metric that has a target), pay estimate.
     *
     * @return array{admin: PlatformAdmin, seat: ?string, month: array<string, int>, today: array<string, int>, targets: array<string, int>, progress: array<string, int>, pay: array{lines: list<array{0: string, 1: float}>, total: float}}
     */
    public function card(PlatformAdmin $admin, string $month): array
    {
        [$from, $to] = self::monthBounds($month);
        $counts = $this->counts($admin, $from, $to);
        $today = $this->counts($admin, local_day_start(), local_day_start(local_at()->addDay()->toDateString()));
        $targets = SalesPlan::targets($admin, $month);
        $seat = SalesPlan::seat($admin);
        $done = [
            'calls_per_day' => $today['calls'], 'appointments' => $counts['appointments'], 'visits' => $counts['visits'],
            'places' => $counts['places'], 'trials' => $counts['trials'], 'paid' => $counts['paid'],
        ];
        $progress = [];
        foreach ($targets as $metric => $target) {
            if ($target > 0) {
                $progress[$metric] = (int) floor(($done[$metric] ?? 0) * 100 / $target);
            }
        }

        return [
            'admin' => $admin, 'seat' => $seat, 'month' => $counts, 'today' => $today, 'targets' => $targets, 'done' => $done,
            'progress' => $progress, 'pay' => $this->pay($admin, $seat, $month, $counts, $targets),
        ];
    }

    /**
     * The month's pay estimate.
     *
     * @param  array<string, int>  $counts
     * @param  array<string, int>  $targets
     * @return array{lines: list<array{0: string, 1: float}>, total: float}
     */
    public function pay(PlatformAdmin $admin, ?string $seat, string $month, array $counts, array $targets): array
    {
        if ($seat === null) {
            return ['lines' => [], 'total' => 0.0];
        }
        $plan = SalesPlan::all();
        $lines = [[__('Base pay'), (float) $plan['base'][$seat]]];

        $commission = $this->commission($admin, $month);
        if ($commission > 0) {
            $lines[] = [__('Commission on paid subscriptions'), $commission];
        }
        if ($seat === 'caller' && $counts['visited_appointments'] > 0) {
            $lines[] = [__(':n booked visits that happened × :fee', ['n' => $counts['visited_appointments'], 'fee' => money($plan['per_visited_appointment'])]),
                $counts['visited_appointments'] * (float) $plan['per_visited_appointment']];
        }
        // the caller's bonus counts booked visits that happened, the field rep's paid subscriptions
        $percent = $seat === 'caller'
            ? ($targets['appointments'] > 0 ? $counts['visited_appointments'] * 100 / $targets['appointments'] : 0)
            : ($targets['paid'] > 0 ? $counts['paid'] * 100 / $targets['paid'] : 0);
        if (($bonus = SalesPlan::bonus($seat, $percent)) > 0) {
            $lines[] = [__('Target bonus (:p%)', ['p' => (int) floor($percent)]), $bonus];
        }
        if ($seat === 'field') {
            foreach ($this->visitDays($admin, $month) as $zone => $days) {
                $lines[] = [__('Visit days, :zone: :n × :fee', ['zone' => SalesPlan::zoneLabel($zone), 'n' => $days, 'fee' => money($plan['allowance'][$zone])]),
                    $days * (float) $plan['allowance'][$zone]];
            }
        }
        $lines[] = [__('Phone line'), (float) $plan['phone'][$seat]];

        return ['lines' => $lines, 'total' => round(array_sum(array_column($lines, 1)), 2)];
    }

    /**
     * Commission of $admin on the subscription invoices paid in $month by the restaurants he closed or assisted: the
     * first invoice (closer and assist shares), the 2nd and 3rd (the closer's retention share).
     */
    public function commission(PlatformAdmin $admin, string $month): float
    {
        [$from, $to] = self::monthBounds($month);
        $prospects = Prospect::query()->whereNotNull('tenant_id')
            ->where(fn ($q) => $q->where('closer_id', $admin->id)->orWhere('assist_id', $admin->id))->get(['tenant_id', 'closer_id', 'assist_id']);
        if ($prospects->isEmpty()) {
            return 0.0;
        }
        $total = 0.0;
        $invoices = Invoice::query()->whereIn('tenant_id', $prospects->pluck('tenant_id'))->where('status', 'paid')->where('total', '>', 0)
            ->whereIn('kind', self::SUBSCRIPTION_KINDS)->orderBy('paid_at')->orderBy('id')->get(['id', 'tenant_id', 'total', 'paid_at'])->groupBy('tenant_id');
        foreach ($prospects as $p) {
            foreach ($invoices->get($p->tenant_id, collect())->values() as $i => $invoice) {
                if ($i > 2 || $invoice->paid_at < $from || $invoice->paid_at >= $to) {
                    continue;
                }
                if ($i === 0) {
                    $total += $p->closer_id === $admin->id ? SalesPlan::closerCommission((float) $invoice->total) : 0;
                    $total += $p->assist_id === $admin->id ? SalesPlan::assistCommission((float) $invoice->total) : 0;
                } elseif ($p->closer_id === $admin->id) {
                    $total += SalesPlan::retentionCommission((float) $invoice->total);
                }
            }
        }

        return round($total, 2);
    }

    /**
     * Days of the month the field rep made visits, by allowance zone (a day counts once, in its furthest zone).
     *
     * @return array<string, int>
     */
    public function visitDays(PlatformAdmin $admin, string $month): array
    {
        [$from, $to] = self::monthBounds($month);
        $rank = ['tanta' => 0, 'gharbia' => 1, 'outside' => 2];
        $days = [];
        Appointment::query()->with('prospect:id,city,governorate')->where('field_id', $admin->id)->where('status', 'done')
            ->where('reported_at', '>=', $from)->where('reported_at', '<', $to)->get()
            ->each(function (Appointment $a) use (&$days, $rank) {
                $day = local_at($a->checked_in_at ?? $a->reported_at)->toDateString();
                $zone = SalesPlan::zone((string) $a->prospect?->city, (string) $a->prospect?->governorate);
                if (! isset($days[$day]) || $rank[$zone] > $rank[$days[$day]]) {
                    $days[$day] = $zone;
                }
            });

        return array_filter(array_map(fn ($z) => count(array_keys($days, $z, true)), ['tanta' => 'tanta', 'gharbia' => 'gharbia', 'outside' => 'outside']));
    }

    /**
     * The team's month as a funnel: calls → visits booked → visits → sign-ups → paid.
     *
     * @return array<string, int>
     */
    public function funnel(string $month): array
    {
        [$from, $to] = self::monthBounds($month);
        $between = fn ($q, string $column) => $q->where($column, '>=', $from)->where($column, '<', $to);

        return [
            'calls' => $between(ProspectEvent::query()->whereIn('type', ['call', 'whatsapp']), 'created_at')->count(),
            'appointments' => $between(Appointment::query(), 'created_at')->count(),
            'visits' => $between(Appointment::query()->where('status', 'done'), 'reported_at')->count(),
            'trials' => $between(Prospect::query(), 'linked_at')->count(),
            'paid' => $between(Prospect::query(), 'won_at')->count(),
        ];
    }
}
