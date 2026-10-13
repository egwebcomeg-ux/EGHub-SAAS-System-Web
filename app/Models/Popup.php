<?php

namespace App\Models;

use App\Models\Concerns\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A scheduled popup on the public menu: a picture with a link, or a menu item the guest can add. */
class Popup extends TenantModel
{
    public const FREQUENCIES = ['always', 'session', 'day', 'once'];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date', 'is_active' => 'boolean'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class, 'menu_item_id');
    }

    public function isLive(?int $branchId): bool
    {
        $today = now()->startOfDay();
        return $this->is_active && (!$this->branch_id || $this->branch_id === $branchId)
            && (!$this->starts_on || $this->starts_on->lte($today)) && (!$this->ends_on || $this->ends_on->gte($today));
    }
}
