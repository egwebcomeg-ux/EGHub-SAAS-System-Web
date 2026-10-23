<?php

namespace App\Models;

use App\Models\Concerns\TenantModel;
use App\Services\Seo\SiteShowcase;

/** One key/value setting of a restaurant (colors, WhatsApp, service %, ...). */
class TenantSetting extends TenantModel
{
    public $timestamps = false;

    /** A new or removed logo changes the home page's logo strip. */
    protected static function booted(): void
    {
        $logoChanged = function (TenantSetting $setting): void {
            if ($setting->key === 'logo') {
                SiteShowcase::flush();
            }
        };
        static::saved($logoChanged);
        static::deleted($logoChanged);
    }
}
