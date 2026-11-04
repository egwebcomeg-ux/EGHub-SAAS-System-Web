<?php

namespace App\Models;

use App\Models\Concerns\TenantModel;
use Illuminate\Support\Carbon;

/** Daily count of item sheets opened on the public menu, per item (counters only, no personal data). */
class MenuItemView extends TenantModel
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['day' => 'date'];
    }

    /** Adds one to today's counter of the item. */
    public static function bump(int $itemId): void
    {
        // the day's row is made at most once (the unique key ignores the second guest's insert), then both count
        $day = Carbon::parse(local_today())->toDateString();
        static::query()->toBase()->insertOrIgnore(['tenant_id' => tenant()->getTenantKey(), 'menu_item_id' => $itemId, 'day' => $day]);
        static::query()->where('menu_item_id', $itemId)->whereDate('day', $day)->increment('views');
    }
}
