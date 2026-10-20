<?php

namespace App\Models;

use App\Models\Concerns\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Cash put into or taken out of the drawer during a shift, with the reason. */
class CashMovement extends TenantModel
{
    public const UPDATED_AT = null;

    /** category of a cash in / out: key => [arabic, english] */
    public const CATEGORIES = [
        'expense' => ['مصروف', 'Expense'],
        'float' => ['فكة / تغذية الدرج', 'Float top-up'],
        'other' => ['غيره', 'Other'],
    ];

    public function categoryLabel(): ?string
    {
        $l = self::CATEGORIES[$this->category] ?? null;

        return $l ? (app()->getLocale() === 'en' ? $l[1] : $l[0]) : null;
    }

    protected function casts(): array
    {
        return ['amount' => 'float', 'created_at' => 'datetime'];
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }
}
