<?php

namespace App\Services;

use App\Billing\Lifecycle;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionChange;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * «الاشتراكات والإيرادات» of the admin (billing.manage), all from the rows in the database: subscriptions by plan and
 * status, the MRR, the restaurants that started paying or stopped paying in a month, the paid invoices per month and
 * the subscriptions ending in the next 14 days.
 *
 * MRR: every active subscription on a paid plan, what its current period was invoiced (period_value) spread over the
 * cycle; one set active by hand without an invoice counts at its plan's price for that cycle. Trials, past due,
 * canceled and the Free plan count 0.
 */
final class AdminSubscriptionReport
{
    public const EXPIRING_DAYS = 14;

    public const REVENUE_MONTHS = 12;

    /**
     * The filters of the request, cleaned: the month of the movement (Y-m, this month by default), a plan code and a
     * subscription status for the list and the export.
     *
     * @return array{month: string, plan: string, status: string}
     */
    public static function filters(Request $request): array
    {
        $month = (string) $request->query('month', '');
        $status = (string) $request->query('status', '');
        $plan = (string) $request->query('plan', '');

        return [
            'month' => preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) ? $month : local_at()->format('Y-m'),
            'plan' => preg_match('/^[a-z0-9_-]{1,40}$/', $plan) ? $plan : '',
            'status' => array_key_exists($status, Subscription::STATUSES) ? $status : '',
        ];
    }

    /** What one subscription brings in a month (0 when it is not paying). */
    public static function monthlyValue(Subscription $subscription): float
    {
        if ($subscription->status !== 'active' || ! $subscription->plan || $subscription->plan->code === Lifecycle::FREE_PLAN) {
            return 0.0;
        }
        $months = max(1, (int) $subscription->cycle_months);
        $value = (float) $subscription->period_value > 0 ? (float) $subscription->period_value : $subscription->plan->priceFor($months);

        return round($value / $months, 2);
    }

    public function mrr(): float
    {
        return round($this->activeSubscriptions()->sum(fn (Subscription $s) => self::monthlyValue($s)), 2);
    }

    /**
     * One row per plan that has a subscription: how many in each status and the plan's share of the MRR.
     *
     * @return list<array{plan: Plan, counts: array<string, int>, total: int, mrr: float}>
     */
    public function byPlan(): array
    {
        $counts = Subscription::query()->groupBy('plan_id', 'status')->selectRaw('plan_id, status, count(*) as n')->get();
        $mrr = $this->activeSubscriptions()->groupBy('plan_id')->map(fn (Collection $subs) => $subs->sum(fn (Subscription $s) => self::monthlyValue($s)));
        $plans = Plan::query()->whereIn('id', $counts->pluck('plan_id')->unique())->orderBy('sort_order')->orderBy('id')->get();

        return $plans->map(function (Plan $plan) use ($counts, $mrr) {
            $row = array_fill_keys(array_keys(Subscription::STATUSES), 0);
            foreach ($counts->where('plan_id', $plan->id) as $count) {
                $row[$count->status] = (int) $count->n;
            }

            return ['plan' => $plan, 'counts' => $row, 'total' => array_sum($row), 'mrr' => round((float) ($mrr[$plan->id] ?? 0), 2)];
        })->values()->all();
    }

    /**
     * The headline numbers: paying (active on a paid plan), on trial, past due, on the Free plan, canceled.
     *
     * @return array{paying: int, trialing: int, past_due: int, free: int, canceled: int}
     */
    public function totals(): array
    {
        $free = Plan::query()->where('code', Lifecycle::FREE_PLAN)->value('id');
        $active = Subscription::query()->where('status', 'active');

        return [
            'paying' => (clone $active)->when($free, fn ($q) => $q->where('plan_id', '!=', $free))->count(),
            'trialing' => Subscription::query()->where('status', 'trialing')->count(),
            'past_due' => Subscription::query()->where('status', 'past_due')->count(),
            'free' => $free ? (clone $active)->where('plan_id', $free)->count() : 0,
            'canceled' => Subscription::query()->where('status', 'canceled')->count(),
        ];
    }

    /**
     * The month's movement. New: restaurants whose first paid plan invoice was paid that month. Churned: restaurants
     * that paid a plan invoice before and are not paying now, their paid access having ended that month (past due:
     * the end of the period; moved to Free: the day of the move; canceled: the day it was canceled). Sign-ups:
     * restaurants created that month.
     *
     * A month the subscription history fully covers (it began on or before the month's first moment) is read from
     * the history instead: see movementFromHistory(). `source` says which one was used.
     *
     * @return array{from: Carbon, new: Collection<int, Tenant>, churned: Collection<int, Tenant>, signups: int, source: string}
     */
    public function movement(string $month): array
    {
        $from = local_day_start($month.'-01');
        $to = local_day_start(Carbon::parse($month.'-01')->addMonthNoOverflow()->toDateString());
        $signups = Tenant::query()->where('created_at', '>=', $from)->where('created_at', '<', $to)->count();

        $historySince = $this->historySince();
        if ($historySince !== null && $historySince->lte($from)) {
            [$newIds, $churnedIds] = $this->movementFromHistory($from, $to);

            return [
                'from' => $from,
                'new' => Tenant::query()->whereIn('id', $newIds)->orderBy('name')->get(['id', 'name']),
                'churned' => Tenant::query()->whereIn('id', $churnedIds)->orderBy('name')->get(['id', 'name']),
                'signups' => $signups,
                'source' => 'history',
            ];
        }

        $firstPaid = $this->paidPlanInvoices()->groupBy('tenant_id')->selectRaw('tenant_id, min(paid_at) as first_paid_at');
        $newIds = DB::query()->fromSub($firstPaid, 'f')->where('first_paid_at', '>=', $from)->where('first_paid_at', '<', $to)->pluck('tenant_id');

        $free = Plan::query()->where('code', Lifecycle::FREE_PLAN)->value('id');
        $churnedIds = Subscription::query()->whereIn('tenant_id', $this->paidPlanInvoices()->select('tenant_id'))
            ->where(fn (Builder $q) => $q
                ->where(fn (Builder $w) => $w->where('status', 'past_due')->where('current_period_end', '>=', $from)->where('current_period_end', '<', $to))
                ->orWhere(fn (Builder $w) => $w->where('status', 'canceled')->where('updated_at', '>=', $from)->where('updated_at', '<', $to))
                ->when($free, fn (Builder $q) => $q->orWhere(fn (Builder $w) => $w->where('status', 'active')->where('plan_id', $free)
                    ->where('current_period_start', '>=', $from)->where('current_period_start', '<', $to))))
            ->pluck('tenant_id');

        return [
            'from' => $from,
            'new' => Tenant::query()->whereIn('id', $newIds)->orderBy('name')->get(['id', 'name']),
            'churned' => Tenant::query()->whereIn('id', $churnedIds)->orderBy('name')->get(['id', 'name']),
            'signups' => $signups,
            'source' => 'invoices',
        ];
    }

    /** When the subscription history began (its first row), null while it is empty. */
    public function historySince(): ?Carbon
    {
        $first = SubscriptionChange::query()->min('changed_at');

        return $first ? Carbon::parse($first) : null;
    }

    /**
     * The month from the recorded changes, per restaurant: its state before its first change of the month against
     * its state after its last one. Paying = active on a plan other than Free. New: not paying before, paying after.
     * Churned: paying before, not paying after (past due, moved to Free, canceled). Back and forth within the month
     * counts as neither.
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    private function movementFromHistory(Carbon $from, Carbon $to): array
    {
        $free = Plan::query()->where('code', Lifecycle::FREE_PLAN)->value('id');
        $paying = fn (?string $status, ?int $planId) => $status === 'active' && $planId !== null && $planId !== $free;
        $new = [];
        $churned = [];
        SubscriptionChange::query()->where('changed_at', '>=', $from)->where('changed_at', '<', $to)
            ->orderBy('changed_at')->orderBy('id')->get(['id', 'tenant_id', 'from_status', 'to_status', 'from_plan_id', 'to_plan_id', 'changed_at'])
            ->groupBy('tenant_id')->each(function (Collection $changes, string $tenantId) use ($paying, &$new, &$churned) {
                $first = $changes->first();
                $last = $changes->last();
                $before = $paying($first->from_status, $first->from_plan_id === null ? null : (int) $first->from_plan_id);
                $after = $paying($last->to_status, $last->to_plan_id === null ? null : (int) $last->to_plan_id);
                if (! $before && $after) {
                    $new[] = $tenantId;
                } elseif ($before && ! $after) {
                    $churned[] = $tenantId;
                }
            });

        return [$new, $churned];
    }

    /**
     * Paid invoices (plans, add-ons and credits) per month of the last 12 months, restaurant time, oldest first.
     *
     * @return list<array{month: string, value: float, count: int}>
     */
    public function revenueByMonth(): array
    {
        $first = local_at()->startOfMonth()->subMonthsNoOverflow(self::REVENUE_MONTHS - 1);
        $buckets = [];
        for ($i = 0; $i < self::REVENUE_MONTHS; $i++) {
            $buckets[$first->copy()->addMonthsNoOverflow($i)->format('Y-m')] = ['value' => 0.0, 'count' => 0];
        }
        Invoice::query()->where('status', 'paid')->where('paid_at', '>=', $first->copy()->utc())
            ->select(['paid_at', 'total'])->lazyById(500, 'id')->each(function (Invoice $invoice) use (&$buckets) {
                $key = local_at($invoice->paid_at)->format('Y-m');
                if (isset($buckets[$key])) {
                    $buckets[$key]['value'] += (float) $invoice->total;
                    $buckets[$key]['count']++;
                }
            });

        return collect($buckets)->map(fn (array $b, string $month) => ['month' => $month, 'value' => round($b['value'], 2), 'count' => $b['count']])->values()->all();
    }

    /**
     * Trials and paid periods ending in the next 14 days, soonest first.
     *
     * @return Collection<int, Subscription>
     */
    public function expiring(string $plan = ''): Collection
    {
        $until = now()->addDays(self::EXPIRING_DAYS);

        return Subscription::query()->with(['tenant:id,name', 'plan'])
            ->where(fn (Builder $q) => $q
                ->where(fn (Builder $w) => $w->where('status', 'trialing')->whereBetween('trial_ends_at', [now(), $until]))
                ->orWhere(fn (Builder $w) => $w->where('status', 'active')->whereBetween('current_period_end', [now(), $until])))
            ->when($plan !== '', fn (Builder $q) => $q->whereHas('plan', fn (Builder $p) => $p->where('code', $plan)))
            ->get()->sortBy(fn (Subscription $s) => $s->endsAt()?->getTimestamp())->values();
    }

    /**
     * Every subscription with the plan and status filters: the list of the page and the CSV export.
     *
     * @param  array{month: string, plan: string, status: string}  $filters
     * @return Builder<Subscription>
     */
    public function listing(array $filters): Builder
    {
        return Subscription::query()->with(['tenant:id,name', 'plan'])
            ->when($filters['plan'] !== '', fn (Builder $q) => $q->whereHas('plan', fn (Builder $p) => $p->where('code', $filters['plan'])))
            ->when($filters['status'] !== '', fn (Builder $q) => $q->where('status', $filters['status']))
            ->orderBy('tenant_id');
    }

    /** @return Collection<int, Subscription> */
    private function activeSubscriptions(): Collection
    {
        return Subscription::query()->with('plan')->where('status', 'active')
            ->get(['id', 'plan_id', 'status', 'cycle_months', 'period_value']);
    }

    /** @return Builder<Invoice> plan invoices that were paid for money (not a 0 total) */
    private function paidPlanInvoices(): Builder
    {
        return Invoice::query()->where('status', 'paid')->where('kind', 'subscription')->whereNotNull('plan_id')
            ->whereNotNull('paid_at')->where('total', '>', 0);
    }
}
