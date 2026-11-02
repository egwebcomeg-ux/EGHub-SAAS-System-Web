<?php

namespace App\Services\Crm;

use App\Models\Appointment;
use App\Models\Lead;
use App\Models\LeadEvent;
use App\Models\PlatformAdmin;
use App\Models\Prospect;
use App\Models\ProspectEvent;
use App\Models\SalesCall;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * «تقارير المبيعات»: what the team did in a period, from what is recorded and nothing estimated. With $admin, only that
 * person's work; a lead or place counts when it was assigned to them, an action when they did it.
 */
final class SalesReport
{
    private const STAGE_EVENTS = ['call', 'whatsapp', 'stage'];

    public function __construct(private TeamStats $stats) {}

    /**
     * @return array{
     *     leads: array<string, int>, places: array<string, int>, calls: array<string, int>,
     *     people: Collection<int, array<string, mixed>>, lost: list<array{label: string, count: int}>
     * }
     */
    public function run(Carbon $from, Carbon $to, ?PlatformAdmin $admin = null): array
    {
        return [
            'leads' => $this->leads($from, $to, $admin),
            'places' => $this->places($from, $to, $admin),
            'calls' => $this->calls($from, $to, $admin),
            'people' => $this->people($from, $to, $admin),
            'lost' => $this->lostReasons($from, $to, $admin),
        ];
    }

    /** @return array<string, int> */
    private function leads(Carbon $from, Carbon $to, ?PlatformAdmin $admin): array
    {
        $events = fn () => $this->between(LeadEvent::query(), 'created_at', $from, $to)->when($admin, fn ($query) => $query->where('admin_id', $admin->id));
        $leads = fn () => Lead::query()->when($admin, fn ($query) => $query->where('assigned_admin_id', $admin->id));

        return [
            'new' => $this->between($leads(), 'created_at', $from, $to)->count(),
            'contacted' => $events()->where('type', 'call')->where('outcome', 'answered')->distinct()->count('lead_id'),
            'interested' => $events()->whereIn('type', self::STAGE_EVENTS)->where('meta->to', 'interested')->distinct()->count('lead_id'),
            'won' => $this->between($leads(), 'won_at', $from, $to)->count(),
            'lost' => $events()->whereIn('type', self::STAGE_EVENTS)->where('meta->to', 'lost')->distinct()->count('lead_id'),
            'overdue' => $leads()->whereIn('stage', Lead::OPEN_STAGES)->where('next_follow_up_at', '<', local_day_start())->count(),
        ];
    }

    /** @return array<string, int> */
    private function places(Carbon $from, Carbon $to, ?PlatformAdmin $admin): array
    {
        $events = fn () => $this->between(ProspectEvent::query(), 'created_at', $from, $to)->when($admin, fn ($query) => $query->where('admin_id', $admin->id));
        $mine = fn () => Prospect::query()->when($admin, fn ($query) => $query->where(fn ($who) => $who->where('caller_id', $admin->id)->orWhere('field_id', $admin->id)));

        return [
            'added' => $this->between(Prospect::query()->when($admin, fn ($query) => $query->where('created_by', $admin->id)), 'created_at', $from, $to)->count(),
            'contacted' => $events()->whereIn('type', ['call', 'whatsapp'])->distinct()->count('prospect_id'),
            'offers' => $events()->where('type', 'whatsapp')->where('meta->offer', true)->count(),
            'appointments' => $this->between(Appointment::query()->when($admin, fn ($query) => $query->where('set_by', $admin->id)), 'created_at', $from, $to)->count(),
            'visits' => $this->between(Appointment::query()->where('status', 'done')->when($admin, fn ($query) => $query->where('field_id', $admin->id)), 'reported_at', $from, $to)->count(),
            'trials' => $this->between($mine(), 'linked_at', $from, $to)->count(),
            'won' => $this->between($mine(), 'won_at', $from, $to)->count(),
        ];
    }

    /** @return array<string, int> */
    private function calls(Carbon $from, Carbon $to, ?PlatformAdmin $admin): array
    {
        $calls = fn () => $this->between(SalesCall::query(), 'started_at', $from, $to)->when($admin, fn ($query) => $query->where('admin_id', $admin->id));
        $talked = $calls()->where('duration_seconds', '>', 0)->count();
        $seconds = (int) $calls()->sum('duration_seconds');

        return [
            'total' => $calls()->count(),
            'outgoing' => $calls()->where('direction', 'outgoing')->count(),
            'incoming' => $calls()->where('direction', 'incoming')->count(),
            'talked' => $talked,
            'seconds' => $seconds,
            'average' => $talked > 0 ? (int) round($seconds / $talked) : 0,
            'missed' => $calls()->whereIn('direction', ['missed', 'rejected'])->count(),
            'not_returned' => $calls()->missedNotReturned()->count(),
            'unsummarized' => $calls()->where('duration_seconds', '>', 0)->whereNull('summarized_at')->count(),
        ];
    }

    /** @return Collection<int, array<string, mixed>> one row per person who works sales or made a call in the period */
    private function people(Carbon $from, Carbon $to, ?PlatformAdmin $admin): Collection
    {
        $callers = $this->between(SalesCall::query(), 'started_at', $from, $to)->whereNotNull('admin_id')->distinct()->pluck('admin_id');
        $people = $admin ? collect([$admin]) : $this->stats->members()
            ->merge(PlatformAdmin::query()->whereIn('id', $callers)->get())->unique('id')->values();

        return $people->map(function (PlatformAdmin $person) use ($from, $to) {
            $counts = $this->stats->counts($person, $from, $to);
            $calls = $this->calls($from, $to, $person);

            return [
                'admin' => $person,
                'calls' => $calls['total'],
                'seconds' => $calls['seconds'],
                'not_returned' => $calls['not_returned'],
                'logged' => $counts['calls'],
                'appointments' => $counts['appointments'],
                'visits' => $counts['visits'],
                'won' => $counts['paid'] + $this->between(Lead::query()->where('assigned_admin_id', $person->id), 'won_at', $from, $to)->count(),
                'overdue' => Lead::query()->where('assigned_admin_id', $person->id)->whereIn('stage', Lead::OPEN_STAGES)->where('next_follow_up_at', '<', local_day_start())->count()
                    + Prospect::query()->where('caller_id', $person->id)->open()->where('next_follow_up_at', '<', local_day_start())->count(),
            ];
        });
    }

    /** @return list<array{label: string, count: int}> why they said no, most common first */
    private function lostReasons(Carbon $from, Carbon $to, ?PlatformAdmin $admin): array
    {
        $reasons = [];
        $count = function (?string $reason) use (&$reasons) {
            $key = $reason ?: 'unknown';
            $reasons[$key] = ($reasons[$key] ?? 0) + 1;
        };
        $this->between(LeadEvent::query(), 'created_at', $from, $to)->whereIn('type', self::STAGE_EVENTS)->where('meta->to', 'lost')
            ->when($admin, fn ($query) => $query->where('admin_id', $admin->id))->distinct()->pluck('lead_id')
            ->pipe(fn ($ids) => Lead::query()->whereIn('id', $ids)->pluck('lost_reason'))->each(fn ($reason) => $count($reason));
        $this->between(ProspectEvent::query(), 'created_at', $from, $to)->whereIn('type', self::STAGE_EVENTS)->where('meta->to', 'lost')
            ->when($admin, fn ($query) => $query->where('admin_id', $admin->id))->distinct()->pluck('prospect_id')
            ->pipe(fn ($ids) => Prospect::query()->whereIn('id', $ids)->pluck('lost_reason'))->each(fn ($reason) => $count($reason));
        arsort($reasons);
        $rows = [];
        foreach ($reasons as $key => $total) {
            $rows[] = ['label' => isset(Lead::LOST_REASONS[$key]) ? Lead::pick(Lead::LOST_REASONS[$key]) : __('Not stated'), 'count' => $total];
        }

        return $rows;
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function between(Builder $query, string $column, Carbon $from, Carbon $to): Builder
    {
        return $query->where($column, '>=', $from)->where($column, '<', $to);
    }
}
