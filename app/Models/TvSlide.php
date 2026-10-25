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
 *   ready — order numbers ready for pickup (orders and pager tickets), only with the pager or customer display;
 *   promo — a ready design (App\Support\ScreenDesigns `template`) drawn by the player with the brand's logo and colours
 *           and an item (item_id: live price and photo, skipped while sold out) or free text (`options`), with an
 *           optional countdown to options.ends_at, after which it stops showing.
 * A slide belongs to a screen (tv_screen_id) or to a group's shared playlist (tv_group_id), and plays in the main or
 * the side area of a split screen (`zone`). Each slide may have its own days and hours (HasScreenHours).
 */
class TvSlide extends TenantModel
{
    use HasScreenHours;

    public const TYPES = ['menu', 'image', 'video', 'offer', 'ready', 'promo'];

    public const ZONES = ['main', 'side'];

    /** promo backgrounds: solid only (the restaurant's dark colour or navy, white, or its main colour) */
    public const BACKGROUNDS = ['dark', 'light', 'brand'];

    public const LAYOUTS = ['list', 'grid', 'featured'];

    /** The ready-orders slide needs one of these plan features. */
    public const READY_FEATURE = 'pager|customer_display';

    protected function casts(): array
    {
        return ['category_ids' => 'array', 'days' => 'array', 'show_images' => 'boolean', 'is_active' => 'boolean',
            'duration' => 'integer', 'columns' => 'integer', 'sort_order' => 'integer', 'options' => 'array'];
    }

    public function screen(): BelongsTo
    {
        return $this->belongsTo(TvScreen::class, 'tv_screen_id');
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(TvGroup::class, 'tv_group_id');
    }

    /** One promo option (title, subtitle, price, was, bg, badge, ends_at). */
    public function option(string $key, mixed $default = null): mixed
    {
        return $this->options[$key] ?? $default;
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
            'promo' => __('Ready design'),
        ][$type] ?? $type;
    }
}
