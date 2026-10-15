<?php

namespace App\Models;

use App\Models\Concerns\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A menu board on a TV: which categories, how they are laid out, and the secret link the TV opens. */
class TvScreen extends TenantModel
{
    public const LAYOUTS = ['all', 'rotate'];

    public const THEMES = ['dark', 'light'];

    protected function casts(): array
    {
        return ['category_ids' => 'array', 'show_images' => 'boolean', 'is_active' => 'boolean'];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function url(): string
    {
        return tenant()->url('tv/'.$this->token);
    }
}
