<?php

namespace App\Models;

use App\Models\Concerns\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A menu uploaded as a photo/PDF/spreadsheet; parsed rows are reviewed before they become items. */
class MenuImport extends TenantModel
{
    protected function casts(): array
    {
        return [
            'result' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
