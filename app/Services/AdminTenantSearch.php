<?php

namespace App\Services;

use App\Models\Subscription;
use App\Models\Tenant;
use App\Support\BusinessType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The restaurants list of the admin (admin.<domain>/tenants): search and filters, all in SQL (subqueries, never a
 * query per row). The business type is the restaurant's `business_type` setting (tenant_settings), a missing or
 * unknown one counting as the default (cafe), as App\Support\BusinessType reads it. "Paid" is
 * Entitlements::isPaid(): an active subscription on a plan other than Free.
 */
final class AdminTenantSearch
{
    public const SORTS = ['newest', 'oldest', 'name', 'activity'];

    public const PERIODS = ['week', 'month', 'custom'];

    /**
     * The filters of the request, cleaned (anything unknown is dropped).
     *
     * @return array{q: string, status: string, type: string, plan: string, paid: string, created: string, from: string, to: string, hidden: string, sort: string}
     */
    public static function filters(Request $request): array
    {
        $pick = fn (string $key, array $allowed): string => in_array($value = (string) $request->query($key, ''), $allowed, true) ? $value : '';
        $date = fn (string $key): string => preg_match('/^\d{4}-\d{2}-\d{2}$/', $value = (string) $request->query($key, '')) ? $value : '';

        $from = $date('from');
        $to = $date('to');
        $created = $pick('created', self::PERIODS);

        return [
            'q' => mb_substr(trim((string) $request->query('q', '')), 0, 100),
            'status' => $pick('status', [...array_keys(Subscription::STATUSES), 'suspended']),
            'type' => $pick('type', array_keys(BusinessType::TYPES)),
            'plan' => preg_match('/^[a-z0-9_-]{1,40}$/', $plan = (string) $request->query('plan', '')) ? $plan : '',
            'paid' => $pick('paid', ['paid', 'unpaid']),
            // dates typed without picking "custom" still count
            'created' => $created === '' && ($from !== '' || $to !== '') ? 'custom' : $created,
            'from' => $from,
            'to' => $to,
            'hidden' => $pick('hidden', ['1', '0']),
            'sort' => $pick('sort', self::SORTS) ?: 'newest',
        ];
    }

    /** True when anything but the default sort is set (the reset link shows). */
    public static function isFiltered(array $filters): bool
    {
        return collect($filters)->except('sort')->filter(fn ($v) => $v !== '')->isNotEmpty() || $filters['sort'] !== 'newest';
    }

    /**
     * @param  array<string, string>  $f
     * @return Builder<Tenant>
     */
    public function query(array $f, bool $withType = true): Builder
    {
        $query = Tenant::query()->select('tenants.*');
        $q = $f['q'];
        if ($q !== '') {
            $like = "%$q%";
            $phone = normalize_phone($q);
            $query->where(fn (Builder $w) => $w->where('tenants.id', 'like', $like)->orWhere('tenants.name', 'like', $like)
                // any account of the restaurant (the owner first of all) by email or mobile
                ->orWhereExists(fn (QueryBuilder $u) => $u->from('users')->whereColumn('users.tenant_id', 'tenants.id')
                    ->where(fn (QueryBuilder $m) => $m->where('users.email', 'like', $like)->when($phone, fn ($m) => $m->orWhere('users.phone', $phone))
                        ->orWhere('users.phone', 'like', $like)))
                ->orWhereExists(fn (QueryBuilder $d) => $d->from('domains')->whereColumn('domains.tenant_id', 'tenants.id')->where('domains.domain', 'like', $like)));
        }
        if ($f['status'] === 'suspended') {
            $query->where('tenants.status', 'suspended');
        } elseif ($f['status'] !== '') {
            $query->whereHas('subscription', fn ($s) => $s->where('status', $f['status']));
        }
        if ($withType && $f['type'] !== '') {
            $this->whereType($query, $f['type']);
        }
        if ($f['plan'] !== '') {
            $query->whereHas('subscription.plan', fn ($p) => $p->where('code', $f['plan']));
        }
        if ($f['paid'] !== '') {
            $paid = fn ($s) => $s->where('status', 'active')->whereHas('plan', fn ($p) => $p->where('code', '!=', Entitlements::FALLBACK_PLAN));
            $f['paid'] === 'paid' ? $query->whereHas('subscription', $paid) : $query->whereDoesntHave('subscription', $paid);
        }
        [$from, $to] = $this->period($f);
        $query->when($from, fn ($w) => $w->where('tenants.created_at', '>=', $from))->when($to, fn ($w) => $w->where('tenants.created_at', '<', $to));
        if ($f['hidden'] !== '') {
            $query->where('tenants.hidden_from_directory', $f['hidden'] === '1');
        }

        return $query;
    }

    /**
     * The page: the latest order of each listed place comes in the same query (orders has a tenant_id + created_at
     * index), which also sorts "last activity".
     *
     * @param  array<string, string>  $f
     * @return Builder<Tenant>
     */
    public function listing(array $f): Builder
    {
        $lastOrder = DB::table('orders')->selectRaw('max(created_at)')->whereColumn('orders.tenant_id', 'tenants.id');
        $query = $this->query($f)->addSelect(['last_order_at' => $lastOrder])->with('subscription.plan');

        return match ($f['sort']) {
            'oldest' => $query->orderBy('tenants.created_at')->orderBy('tenants.id'),
            'name' => $query->orderBy('tenants.name')->orderBy('tenants.id'),
            'activity' => $query->orderByRaw('last_order_at is null')->orderByDesc('last_order_at')->orderByDesc('tenants.created_at'),
            default => $query->orderByDesc('tenants.created_at')->orderByDesc('tenants.id'),
        };
    }

    /**
     * How many places of each business type match the other filters (the chips above the table): two queries.
     *
     * @param  array<string, string>  $f
     * @return array<string, int>
     */
    public function typeCounts(array $f): array
    {
        $base = $this->query($f, false);
        $total = (clone $base)->toBase()->getCountForPagination();
        $others = array_values(array_diff(array_keys(BusinessType::TYPES), [BusinessType::DEFAULT]));
        $counted = DB::table('tenant_settings')->where('key', 'business_type')->whereIn('value', $others)
            ->whereIn('tenant_id', (clone $base)->select('tenants.id'))
            ->groupBy('value')->selectRaw('value, count(*) as n')->pluck('n', 'value');

        $counts = [];
        foreach (array_keys(BusinessType::TYPES) as $type) {
            $counts[$type] = $type === BusinessType::DEFAULT ? 0 : (int) ($counted[$type] ?? 0);
        }
        $counts[BusinessType::DEFAULT] = max(0, $total - array_sum($counts));

        return $counts;
    }

    /**
     * The business type of each listed place, one query for the page.
     *
     * @param  iterable<string>  $tenantIds
     * @return array<string, string>
     */
    public static function typesOf(iterable $tenantIds): array
    {
        $ids = collect($tenantIds)->all();
        $stored = DB::table('tenant_settings')->where('key', 'business_type')->whereIn('tenant_id', $ids)->pluck('value', 'tenant_id');
        $types = [];
        foreach ($ids as $id) {
            $type = (string) ($stored[$id] ?? '');
            $types[$id] = isset(BusinessType::TYPES[$type]) ? $type : BusinessType::DEFAULT;
        }

        return $types;
    }

    /** @param Builder<Tenant> $query */
    private function whereType(Builder $query, string $type): void
    {
        $setting = fn (QueryBuilder $s) => $s->from('tenant_settings')->whereColumn('tenant_settings.tenant_id', 'tenants.id')
            ->where('tenant_settings.key', 'business_type');
        if ($type === BusinessType::DEFAULT) {
            // no setting, or one that is not another known type
            $others = array_values(array_diff(array_keys(BusinessType::TYPES), [BusinessType::DEFAULT]));
            $query->whereNotExists(fn (QueryBuilder $s) => $setting($s)->whereIn('tenant_settings.value', $others));

            return;
        }
        $query->whereExists(fn (QueryBuilder $s) => $setting($s)->where('tenant_settings.value', $type));
    }

    /**
     * @param  array<string, string>  $f
     * @return array{0: ?Carbon, 1: ?Carbon}
     */
    private function period(array $f): array
    {
        $now = CarbonImmutable::now();

        return match ($f['created']) {
            // the Egyptian week starts on Saturday
            'week' => [Carbon::instance($now->startOfWeek(Carbon::SATURDAY)), null],
            'month' => [Carbon::instance($now->startOfMonth()), null],
            'custom' => [
                $f['from'] !== '' ? Carbon::parse($f['from'])->startOfDay() : null,
                $f['to'] !== '' ? Carbon::parse($f['to'])->addDay()->startOfDay() : null,
            ],
            default => [null, null],
        };
    }
}
