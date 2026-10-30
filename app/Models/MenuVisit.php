<?php

namespace App\Models;

use App\Models\Concerns\TenantModel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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
        $row = static::query()->firstOrCreate(['day' => Carbon::parse(local_today())]);
        static::query()->whereKey($row->id)->update([$column => DB::raw($column.' + 1')]);
    }
}
