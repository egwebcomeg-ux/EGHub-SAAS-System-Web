<?php

namespace App\Models;

use App\Models\Concerns\HasScreenHours;
use App\Models\Concerns\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One slide of a TV screen's playlist, shown for `duration` seconds in `sort_order`:
 *   menu  — chosen categories (null = all) as a list, a grid of photos or one featured item; live prices, sold-out
 *           items left out; with side_media_id the menu shares the screen with a picture or video (split);
 *   image / video — from the restaurant's screen library (TvMedia), videos muted and looped (duration 0 = to the end);
 *   offer — a running offer (offer_id) or every running offer; skipped while none runs;
 *   ready — order numbers ready for pickup (orders and pager tickets), only with the pager or customer display.
 * Each slide may have its own days and hours (HasScreenHours).
 */
class TvSlide extends TenantModel
{
    use HasScreenHours;

    public const TYPES = ['menu', 'image', 'video', 'offer', 'ready'];

    public const LAYOUTS = ['list', 'grid', 'featured'];

    /** The ready-orders slide needs one of these plan features. */
    public const READY_FEATURE = 'pager|customer_display';

    protected function casts(): array
    {
        return ['category_ids' => 'array', 'days' => 'array', 'show_images' => 'boolean', 'is_active' => 'boolean',
            'duration' => 'integer', 'columns' => 'integer', 'sort_order' => 'integer'];
    }

    public function screen(): BelongsTo
    {
        return $this->belongsTo(TvScreen::class, 'tv_screen_id');
    }

    public function media(): BelongsTo
    {
        return $this->belongsTo(TvMedia::class, 'media_id');
    }

    public function sideMedia(): BelongsTo
    {
        return $this->belongsTo(TvMedia::class, 'side_media_id');
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class, 'item_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public static function typeLabel(string $type): string
    {
        return [
            'menu' => __('Menu'), 'image' => __('Picture'), 'video' => __('Video'), 'offer' => __('Offer'), 'ready' => __('Ready orders'),
        ][$type] ?? $type;
    }
}
