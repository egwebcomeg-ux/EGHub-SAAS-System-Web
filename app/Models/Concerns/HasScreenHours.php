<?php

namespace App\Models\Concerns;

use Carbon\CarbonInterface;

/**
 * Days and hours a TV screen or one of its slides shows (restaurant time): `days` [0..6] Sunday=0 (null or empty = every
 * day), `starts_at` / `ends_at` (both null = all day). A window may cross midnight (22:00 → 02:00): after midnight it
 * still belongs to the day it started, like App\Models\Offer. The player checks the same rules on the device, so a
 * breakfast slide still switches at 12:00 while the network is down.
 */
trait HasScreenHours
{
    public function isOnAt(CarbonInterface $at): bool
    {
        $at = local_at($at);
        $from = $this->starts_at ? substr((string) $this->starts_at, 0, 5) : null;
        $to = $this->ends_at ? substr((string) $this->ends_at, 0, 5) : null;
        if (! $from || ! $to || $from === $to) {
            return $this->dayMatches($at);
        }
        $now = $at->format('H:i');
        if ($from < $to) {
            return $this->dayMatches($at) && $now >= $from && $now < $to;
        }

        return $now >= $from ? $this->dayMatches($at) : ($now < $to && $this->dayMatches($at->copy()->subDay()));
    }

    public function hasHours(): bool
    {
        return ($this->days !== null && $this->days !== []) || ($this->starts_at && $this->ends_at);
    }

    /**
     * The rules as the player reads them.
     *
     * @return array{days: list<int>|null, from: string|null, to: string|null}|null
     */
    public function hoursForPlayer(): ?array
    {
        if (! $this->hasHours()) {
            return null;
        }

        return [
            'days' => $this->days ? array_values(array_map('intval', $this->days)) : null,
            'from' => $this->starts_at ? substr((string) $this->starts_at, 0, 5) : null,
            'to' => $this->ends_at ? substr((string) $this->ends_at, 0, 5) : null,
        ];
    }

    private function dayMatches(CarbonInterface $at): bool
    {
        return $this->days === null || $this->days === [] || in_array((int) $at->dayOfWeek, array_map('intval', $this->days), true);
    }
}
