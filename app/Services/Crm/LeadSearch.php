<?php

namespace App\Services\Crm;

use App\Models\Lead;
use App\Models\PlatformAdmin;
use App\Services\AdminTenantSearch;
use App\Support\BusinessType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The leads list of the sales follow-up (admin.<domain>/crm): filters, search and sort in SQL (subqueries, never a
 * query per row), today's call queue and the counters of the admin home card and the sidebar badge. Leads of a
 * restaurant that was deleted (soft) are left out.
 */
final class LeadSearch
{
    public const SORTS = ['newest', 'oldest', 'follow_up', 'name', 'trial'];

    /** "Trial ending soon" = within this many days. */
    public const TRIAL_SOON_DAYS = 3;

    /**
     * @return array{q: string, stage: string, mine: string, due: string, recent: string, type: string, plan: string, trial: string, sort: string}
     */
    public static function filters(Request $request): array
    {
        $pick = fn (string $key, array $allowed): string => in_array($value = (string) $request->query($key, ''), $allowed, true) ? $value : '';

        return [
            'q' => mb_substr(trim((string) $request->query('q', '')), 0, 100),
            'stage' => $pick('stage', ['open', ...array_keys(Lead::STAGES)]),
            'mine' => $pick('mine', ['1']),
            'due' => $pick('due', ['today', 'overdue', 'due']),
            'recent' => $pick('recent', ['1']),
            'type' => $pick('type', array_keys(BusinessType::TYPES)),
            'plan' => preg_match('/^[a-z0-9_-]{1,40}$/', $plan = (string) $request->query('plan', '')) ? $plan : '',
            'trial' => $pick('trial', ['ending']),
            'sort' => $pick('sort', self::SORTS) ?: 'newest',
        ];
    }

    /** @param array<string, string> $filters */
    public static function isFiltered(array $filters): bool
    {
        return collect($filters)->except('sort')->filter(fn ($v) => $v !== '')->isNotEmpty() || $filters['sort'] !== 'newest';
    }

    /**
     * @param  array<string, string>  $f
     * @return Builder<Lead>
     */
    public function listing(array $f, PlatformAdmin $admin): Builder
    {
        $query = $this->base();
        if ($f['q'] !== '') {
            $like = "%{$f['q']}%";
            $phone = normalize_phone($f['q']);
            $query->where(fn (Builder $w) => $w->where('tenants.id', 'like', $like)->orWhere('tenants.name', 'like', $like)
                ->orWhereExists(fn (QueryBuilder $u) => $u->from('users')->whereColumn('users.tenant_id', 'tenants.id')
                    ->where(fn (QueryBuilder $m) => $m->where('users.email', 'like', $like)->orWhere('users.name', 'like', $like)
                        ->orWhere('users.phone', 'like', $like)->when($phone, fn ($m) => $m->orWhere('users.phone', $phone)))));
        }
        if ($f['stage'] === 'open') {
            $query->open();
        } elseif ($f['stage'] !== '') {
            $query->where('crm_leads.stage', $f['stage']);
        }
        if ($f['mine'] === '1') {
            $query->where('crm_leads.assigned_admin_id', $admin->id);
        }
        match ($f['due']) {
            'today' => $query->dueToday(),
            'overdue' => $query->overdue(),
            'due' => $query->open()->where('crm_leads.next_follow_up_at', '<', Lead::todayStart()->addDay()),
            default => null,
        };
        if ($f['recent'] === '1') {
            $query->where('tenants.created_at', '>=', now()->subDays(7));
        }
        if ($f['type'] !== '') {
            app(AdminTenantSearch::class)->whereType($query, $f['type']);
        }
        if ($f['plan'] !== '') {
            $query->whereExists(fn (QueryBuilder $s) => $s->from('subscriptions')->join('plans', 'plans.id', '=', 'subscriptions.plan_id')
                ->whereColumn('subscriptions.tenant_id', 'tenants.id')->where('plans.code', $f['plan']));
        }
        if ($f['trial'] === 'ending') {
            $query->whereExists(fn (QueryBuilder $s) => $s->from('subscriptions')->whereColumn('subscriptions.tenant_id', 'tenants.id')
                ->where('subscriptions.status', 'trialing')->whereBetween('subscriptions.trial_ends_at', [now(), now()->addDays(self::TRIAL_SOON_DAYS)]));
        }

        return match ($f['sort']) {
            'oldest' => $query->orderBy('tenants.created_at')->orderBy('crm_leads.id'),
            'name' => $query->orderBy('tenants.name')->orderBy('crm_leads.id'),
            'follow_up' => $query->orderByRaw('crm_leads.next_follow_up_at is null')->orderBy('crm_leads.next_follow_up_at')->orderByDesc('tenants.created_at'),
            'trial' => $query->orderByRaw('trial_ends_at is null')->orderBy('trial_ends_at')->orderByDesc('tenants.created_at'),
            default => $query->orderByDesc('tenants.created_at')->orderByDesc('crm_leads.id'),
        };
    }

    /**
     * Who to call today: follow-ups due today or overdue (oldest first), then new leads nobody contacted yet — the
     * admin's own and the unassigned ones.
     *
     * @return Collection<int, Lead>
     */
    public function queue(PlatformAdmin $admin, int $limit = 12): Collection
    {
        $mineOrFree = fn (Builder $q) => $q->where(fn (Builder $w) => $w->whereNull('crm_leads.assigned_admin_id')->orWhere('crm_leads.assigned_admin_id', $admin->id));
        $due = $mineOrFree($this->base()->open()->where('crm_leads.next_follow_up_at', '<', Lead::todayStart()->addDay()))
            ->orderBy('crm_leads.next_follow_up_at')->limit($limit)->get();
        $fresh = $due->count() < $limit
            ? $mineOrFree($this->base()->uncontacted()->whereNull('crm_leads.next_follow_up_at'))->orderBy('tenants.created_at')->limit($limit - $due->count())->get()
            : collect();

        return $due->concat($fresh)->values();
    }

    /** @return array{uncontacted: int, today: int, overdue: int} */
    public function counts(): array
    {
        return [
            'uncontacted' => $this->base()->uncontacted()->count(),
            'today' => $this->base()->dueToday()->count(),
            'overdue' => $this->base()->overdue()->count(),
        ];
    }

    /**
     * Leads of live restaurants, with the first account's name and mobile (the owner, made first) and the trial end.
     *
     * @return Builder<Lead>
     */
    private function base(): Builder
    {
        $firstUser = fn (string $column) => DB::table('users')->select($column)->whereColumn('users.tenant_id', 'tenants.id')->orderBy('users.id')->limit(1);

        return Lead::query()->select('crm_leads.*')
            ->join('tenants', 'tenants.id', '=', 'crm_leads.tenant_id')->whereNull('tenants.deleted_at')
            ->addSelect([
                'owner_name' => $firstUser('name'),
                'owner_phone' => $firstUser('phone'),
                'trial_ends_at' => DB::table('subscriptions')->select('trial_ends_at')->whereColumn('subscriptions.tenant_id', 'tenants.id')
                    ->where('subscriptions.status', 'trialing')->limit(1),
            ])
            ->with(['tenant.subscription.plan', 'assignee']);
    }
}
