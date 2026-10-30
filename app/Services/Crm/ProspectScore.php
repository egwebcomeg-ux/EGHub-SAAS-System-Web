<?php

namespace App\Services\Crm;

use App\Models\Prospect;

/**
 * «ترشيح السيستم»: how worth a call or a visit a place is, 0-100. Kind of place (restaurants and cafes first), a phone
 * to call, how close it is to the office in Tanta (cheaper, faster visits) and how many other places sit around it
 * (one trip, several visits). Recomputed after every import and whenever a place's data changes.
 */
final class ProspectScore
{
    /** The office in Tanta. */
    public const OFFICE = [30.7865, 31.0004];

    public const CATEGORY_POINTS = ['restaurant' => 30, 'cafe' => 28, 'sweets' => 24, 'bakery' => 20, 'other' => 10];

    /** Neighbours within this many metres count for the cluster points. */
    public const NEIGHBOUR_METRES = 1000;

    public function score(Prospect $p, int $neighbours = 0): int
    {
        $points = self::CATEGORY_POINTS[$p->category] ?? 10;
        if ($p->phone) {
            $points += 25;
        }
        if ($p->hasLocation()) {
            $km = Prospect::distance(self::OFFICE[0], self::OFFICE[1], $p->lat, $p->lng) / 1000;
            $points += match (true) {
                $km <= 5 => 25,
                $km <= 25 => 15,
                $km <= 60 => 5,
                default => 0,
            };
        }
        if ($p->branches !== null && $p->branches > 1) {
            $points += 5;
        }

        return max(0, min(100, $points + min(4, $neighbours) * 5));
    }

    /** Rescores the open places (all, or the given ids) with their neighbour counts. @param list<int>|null $ids */
    public function rescore(?array $ids = null): int
    {
        $pins = Prospect::query()->whereNotNull('lat')->whereNotNull('lng')->get(['id', 'lat', 'lng']);
        $query = Prospect::query()->open();
        if ($ids !== null) {
            $query->whereIn('id', $ids);
        }
        $count = 0;
        $query->chunkById(200, function ($chunk) use ($pins, &$count) {
            foreach ($chunk as $p) {
                $neighbours = 0;
                if ($p->hasLocation()) {
                    foreach ($pins as $pin) {
                        // a cheap box first, the real distance only for the close ones
                        if ($pin->id !== $p->id && abs($pin->lat - $p->lat) < 0.01 && abs($pin->lng - $p->lng) < 0.012
                            && Prospect::distance($p->lat, $p->lng, $pin->lat, $pin->lng) <= self::NEIGHBOUR_METRES) {
                            $neighbours++;
                        }
                    }
                }
                $score = $this->score($p, $neighbours);
                if ($score !== $p->score) {
                    $p->update(['score' => $score]);
                }
                $count++;
            }
        });

        return $count;
    }
}
