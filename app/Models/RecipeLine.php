<?php

namespace App\Models;

use App\Models\Concerns\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** "One latte uses 18 g coffee"; with an option, the line applies only when that option is chosen. */
class RecipeLine extends TenantModel
{
    protected function casts(): array
    {
        return ['quantity' => 'float'];
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function option(): BelongsTo
    {
        return $this->belongsTo(ItemOption::class, 'item_option_id');
    }
}
