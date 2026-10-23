<?php

namespace App\Services\Seo;

use App\Models\Tenant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The moving strip of restaurant logos on the egmenu.app home page. A place is in it only when EgWeb turned
 * showcase_on_site on for it from the admin (after the owner agreed), it is active and not deleted, and it has a
 * logo of its own: nothing is ever drawn in place of a missing logo. The strip shows only from MIN_LOGOS places up,
 * so the home page never carries a thin row. Cached; flushed when the admin changes a place, a tenant's status
 * changes or a logo is saved (App\Models\Tenant, App\Models\TenantSetting), and the TTL catches anything else.
 */
final class SiteShowcase
{
    public const MIN_LOGOS = 4;

    public const MAX_LOGOS = 30;

    public const TTL_SECONDS = 600;

    private const CACHE_KEY = 'site_showcase.logos';

    /**
     * The places of the strip, by the admin's order then by name; [] under MIN_LOGOS.
     *
     * @return list<array{slug:string, name:string, name_en:string, logo:string, url:string}>
     */
    public static function logos(): array
    {
        return Cache::remember(self::CACHE_KEY, self::TTL_SECONDS, function () {
            $logos = DB::table('tenant_settings')->where('key', 'logo')->where('value', '!=', '')->whereNotNull('value')->select('tenant_id');
            $tenants = Tenant::query()->where('showcase_on_site', true)->where('status', 'active')->whereIn('id', $logos)
                ->orderByRaw('showcase_sort is null')->orderBy('showcase_sort')->orderBy('name')
                ->limit(self::MAX_LOGOS)->get(['id', 'name']);
            if ($tenants->count() < self::MIN_LOGOS) {
                return [];
            }
            $settings = DB::table('tenant_settings')->whereIn('tenant_id', $tenants->pluck('id'))
                ->whereIn('key', ['brand_name', 'brand_name_en', 'logo'])->get()
                ->groupBy('tenant_id')->map(fn ($rows) => $rows->pluck('value', 'key')->map(fn ($v) => trim((string) $v))->all());

            $places = $tenants->map(function (Tenant $t) use ($settings) {
                $s = $settings[$t->id] ?? [];

                return ($s['logo'] ?? '') === '' ? null : [
                    'slug' => $t->id,
                    'name' => ($s['brand_name'] ?? '') !== '' ? $s['brand_name'] : (string) $t->name,
                    'name_en' => $s['brand_name_en'] ?? '',
                    'logo' => '/storage/'.ltrim($s['logo'], '/'),
                    'url' => Tenant::pathUrl($t->id),
                ];
            })->filter()->values()->all();

            return count($places) >= self::MIN_LOGOS ? $places : [];
        });
    }

    public static function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
