<?php

namespace App\Models;

use App\Models\Concerns\TenantModel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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
        $row = static::query()->firstOrCreate(['menu_item_id' => $itemId, 'day' => Carbon::parse(local_today())]);
        static::query()->whereKey($row->id)->update(['views' => DB::raw('views + 1')]);
    }
}
