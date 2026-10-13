<?php

namespace App\Models;

use App\Models\Concerns\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** "Call the waiter" or "the bill, please" from a table; open until a staff member marks it handled. */
class TableCall extends TenantModel
{
    public const KINDS = [
        'waiter' => ['نداء الويتر', 'Waiter call'],
        'bill' => ['طلب الحساب', 'Bill request'],
    ];

    protected function casts(): array
    {
        return ['handled_at' => 'datetime'];
    }

    public function table(): BelongsTo
    {
        return $this->belongsTo(DiningTable::class, 'dining_table_id');
    }

    public function kindLabel(): string
    {
        $l = self::KINDS[$this->kind] ?? [$this->kind, $this->kind];

        return app()->getLocale() === 'en' ? $l[1] : $l[0];
    }
}
