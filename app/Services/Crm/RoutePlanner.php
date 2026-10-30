<?php

namespace App\Services\Crm;

use App\Models\Appointment;
use App\Models\PlatformAdmin;
use App\Models\Prospect;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The field rep's day: his booked visits in time order, then nearby places worth a walk-in visit (his own open places
 * with the best «ترشيح السيستم»), chained nearest-first from where his last booked visit is (or the office).
 */
final class RoutePlanner
{
    /** A full day of visits. */
    public const STOPS = 6;

    /**
     * @return array{appointments: Collection<int, Appointment>, suggestions: list<array{prospect: Prospect, metres: int}>}
     */
    public function day(PlatformAdmin $field, Carbon $dayStart): array
    {
        $appointments = Appointment::query()->with(['prospect', 'setter'])->where('field_id', $field->id)
            ->where('scheduled_at', '>=', $dayStart)->where('scheduled_at', '<', $dayStart->copy()->addDay())
            ->whereIn('status', ['scheduled', 'done'])->orderBy('scheduled_at')->get();

        $from = ProspectScore::OFFICE;
        foreach ($appointments as $a) {
            if ($a->prospect?->hasLocation()) {
                $from = [$a->prospect->lat, $a->prospect->lng];
            }
        }
        $booked = $appointments->pluck('prospect_id')->all();
        $pool = Prospect::query()->where('field_id', $field->id)->whereIn('stage', ['new', 'contacted'])
            ->whereNotNull('lat')->whereNotNull('lng')->whereNotIn('id', $booked)
            ->orderByDesc('score')->limit(40)->get();

        $suggestions = [];
        $want = max(2, self::STOPS - $appointments->count());
        while (count($suggestions) < $want && $pool->isNotEmpty()) {
            $next = $pool->sortBy(fn (Prospect $p) => Prospect::distance($from[0], $from[1], $p->lat, $p->lng))->first();
            $suggestions[] = ['prospect' => $next, 'metres' => (int) round(Prospect::distance($from[0], $from[1], $next->lat, $next->lng))];
            $from = [$next->lat, $next->lng];
            $pool = $pool->reject(fn (Prospect $p) => $p->id === $next->id);
        }

        return ['appointments' => $appointments, 'suggestions' => $suggestions];
    }
}
