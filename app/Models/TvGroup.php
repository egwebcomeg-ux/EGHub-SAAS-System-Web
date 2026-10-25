<?php

namespace App\Models;

use App\Models\Concerns\TenantModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A group of TV screens that share one playlist (e.g. «شاشات الكاشير» in every branch): the owner edits the group's
 * slides once and every screen in the group plays them, each with its own branch prices, look and hours. A screen of
 * the group may keep its own playlist instead (TvScreen::own_playlist). Restaurant-wide: staff tied to one branch do
 * not manage groups.
 */
class TvGroup extends TenantModel
{
    public function screens(): HasMany
    {
        return $this->hasMany(TvScreen::class)->orderBy('id');
    }

    public function slides(): HasMany
    {
        return $this->hasMany(TvSlide::class)->orderBy('sort_order')->orderBy('id');
    }
}
