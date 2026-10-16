<?php

namespace App\Models;

use App\Models\Concerns\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A picture in the swipeable strip under the public menu header (feature offers). Optional title and link to a
 * menu item, a category or an address; optional dates. At most MAX_ACTIVE are live at a time.
 */
class Banner extends TenantModel
{
    public const MAX_ACTIVE = 5;

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date', 'is_active' => 'boolean'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class, 'menu_item_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** On, and inside its dates (if any). */
    public function isLive(): bool
    {
        $today = now()->startOfDay();

        return $this->is_active && (! $this->starts_on || $this->starts_on->lte($today)) && (! $this->ends_on || $this->ends_on->gte($today));
    }

    /** item | category | url | none */
    public function linkType(): string
    {
        return match (true) {
            $this->menu_item_id !== null => 'item',
            $this->category_id !== null => 'category',
            (string) $this->link_url !== '' => 'url',
            default => 'none',
        };
    }
}
