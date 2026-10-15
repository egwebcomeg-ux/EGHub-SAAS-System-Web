<?php

namespace App\Models;

use App\Models\Concerns\TenantModel;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A timed discount: X% off a category or the whole menu on chosen days and hours (windows may cross midnight). */
class Offer extends TenantModel
{
    protected function casts(): array
    {
        return ['days' => 'array', 'starts_on' => 'date', 'ends_on' => 'date', 'is_active' => 'boolean'];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** Is the offer running at $at (restaurant local time) in $branchId? */
    public function runsAt(CarbonInterface $at, ?int $branchId): bool
    {
        if (! $this->is_active || ($this->branch_id && $this->branch_id !== $branchId)) {
            return false;
        }
        if (($this->starts_on && $at->lt($this->starts_on->startOfDay())) || ($this->ends_on && $at->gt($this->ends_on->endOfDay()))) {
            return false;
        }
        if (! $this->starts_at || ! $this->ends_at) {
            return $this->dayMatches($at);
        }
        $now = $at->format('H:i:s');
        $from = substr($this->starts_at, 0, 8);
        $to = substr($this->ends_at, 0, 8);
        if ($from <= $to) {
            return $this->dayMatches($at) && $now >= $from && $now < $to;
        }

        // overnight (e.g. 22:00 → 02:00): after midnight it still belongs to the day it started
        return $now >= $from ? $this->dayMatches($at) : ($now < $to && $this->dayMatches($at->copy()->subDay()));
    }

    private function dayMatches(CarbonInterface $at): bool
    {
        return $this->days === null || $this->days === [] || in_array((int) $at->dayOfWeek, array_map('intval', $this->days), true);
    }
}
