<?php

namespace App\Models;

use App\Models\Concerns\TenantModel;
use Illuminate\Support\Carbon;

/** Daily menu visits and table scans of one restaurant (counters only, no personal data). */
class MenuVisit extends TenantModel
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['day' => 'date'];
    }

    /** Adds one to today's counter ("views" or "scans"). */
    public static function bump(string $column): void
    {
        // the day's row is made at most once whoever opens the menu first after midnight: two guests at the same
        // second both pass the insert (one is ignored on the unique key) and both count
        $day = Carbon::parse(local_today())->toDateString();
        static::query()->toBase()->insertOrIgnore(['tenant_id' => tenant()->getTenantKey(), 'day' => $day]);
        static::query()->whereDate('day', $day)->increment($column);
    }
}
