<?php

namespace App\Models;

use Database\Factories\AnnouncementFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * «إعلانات للمطاعم»: a notice from EgWeb on the restaurant dashboards (layouts.dashboard), for every restaurant or
 * only the restaurants whose subscription is on one plan, from starts_at until ends_at (either may be open). Platform
 * data, never scoped by tenant: what a restaurant sees is decided by visibleTo() alone.
 */
class Announcement extends Model
{
    /** @use HasFactory<AnnouncementFactory> */
    use HasFactory;

    public const LEVELS = ['info', 'warning'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime'];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class, 'created_by');
    }

    /** Started (or no start) and not ended yet. */
    public function scopeLive(Builder $query): void
    {
        $query->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()));
    }

    /** For every restaurant, or for the plan $tenant's subscription is on (no subscription: the "all" ones only). */
    public function scopeForTenant(Builder $query, Tenant $tenant): void
    {
        $planId = Subscription::query()->where('tenant_id', $tenant->getTenantKey())->value('plan_id');
        $query->where(fn (Builder $q) => $q->whereNull('plan_id')->when($planId, fn (Builder $w) => $w->orWhere('plan_id', $planId)));
    }

    /**
     * What $user sees on their restaurant's dashboard now: live, aimed at their restaurant, not hidden by them.
     *
     * @return Collection<int, Announcement>
     */
    public static function visibleTo(User $user, Tenant $tenant): Collection
    {
        return static::query()->live()->forTenant($tenant)
            ->whereNotExists(fn (QueryBuilder $d) => $d->from('announcement_dismissals')
                ->whereColumn('announcement_dismissals.announcement_id', 'announcements.id')->where('announcement_dismissals.user_id', $user->id))
            ->orderByDesc('starts_at')->orderByDesc('id')->limit(3)->get();
    }

    /** $user hides it for themselves (once; again is a no-op). */
    public function dismissFor(User $user): void
    {
        DB::table('announcement_dismissals')->insertOrIgnore(['announcement_id' => $this->id, 'user_id' => $user->id, 'created_at' => now()]);
    }

    public function isLive(): bool
    {
        return ($this->starts_at === null || $this->starts_at->lte(now())) && ($this->ends_at === null || $this->ends_at->isFuture());
    }
}
