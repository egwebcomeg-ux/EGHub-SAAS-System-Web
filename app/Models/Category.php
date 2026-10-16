<?php

namespace App\Models;

use App\Models\Concerns\TenantModel;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Menu section. An optional daypart (visible_from–visible_until, may cross midnight, on chosen weekdays) hides it
 * from guests outside its hours: breakfast 07:00–12:00, late-night 22:00–03:00.
 */
class Category extends TenantModel
{
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'visible_days' => 'array',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(MenuItem::class)->orderBy('sort_order');
    }

    /** Has a daypart window or chosen weekdays (shown with a clock in the dashboard). */
    public function hasSchedule(): bool
    {
        return ($this->visible_from && $this->visible_until) || ($this->visible_days !== null && $this->visible_days !== []);
    }

    /** Is the category on the menu at $at (restaurant local time)? Same overnight rule as timed offers. */
    public function isVisibleAt(CarbonInterface $at): bool
    {
        if (! $this->visible_from || ! $this->visible_until) {
            return $this->dayMatches($at);
        }
        $now = $at->format('H:i:s');
        $from = substr((string) $this->visible_from, 0, 8);
        $to = substr((string) $this->visible_until, 0, 8);
        if ($from <= $to) {
            return $this->dayMatches($at) && $now >= $from && $now < $to;
        }

        // overnight (22:00 → 03:00): after midnight the window still belongs to the day it started on
        return $now >= $from ? $this->dayMatches($at) : ($now < $to && $this->dayMatches($at->copy()->subDay()));
    }

    /** "07:00–12:00" for the dashboard badge ("" when the category has no window). */
    public function windowLabel(): string
    {
        return $this->visible_from && $this->visible_until
            ? substr((string) $this->visible_from, 0, 5).'–'.substr((string) $this->visible_until, 0, 5) : '';
    }

    private function dayMatches(CarbonInterface $at): bool
    {
        return $this->visible_days === null || $this->visible_days === [] || in_array((int) $at->dayOfWeek, array_map('intval', $this->visible_days), true);
    }
}
