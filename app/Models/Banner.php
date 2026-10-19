<?php

namespace App\Models;

use App\Models\Concerns\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An announcement on the public menu (feature offers): a picture with an optional title, an optional link to a menu
 * item, a category or an address, and optional dates. Most banners play in the strip under the menu header (at most
 * MAX_ACTIVE live at a time); a banner marked is_popup instead opens over the menu when it is opened (one switched on
 * at a time, once per visit unless popup_frequency says otherwise, and only after the first-open sheet is closed).
 */
class Banner extends TenantModel
{
    public const MAX_ACTIVE = 5;

    /** How often a guest sees the popup banner: once per visit (default), once a day, only once, every time. */
    public const POPUP_FREQUENCIES = ['session', 'day', 'once', 'always'];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date', 'is_active' => 'boolean', 'is_popup' => 'boolean'];
    }

    /** The picture to show: its own, else (a popup copied from an item popup) the item's photo. */
    public function picture(): ?string
    {
        return (string) $this->image !== '' ? $this->image : $this->item?->image;
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
