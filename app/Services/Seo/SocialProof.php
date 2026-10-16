<?php

namespace App\Services\Seo;

use App\Models\Tenant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Social proof on the home page, computed from the database and cached for ten minutes: the places that use
 * EgMenu (active tenants listed in the directory, see Directory::query()) and the platform's real counters.
 * Both are shown only above a floor so the home page never carries a thin strip or made-up numbers.
 */
final class SocialProof
{
    public const TTL_SECONDS = 600;

    /** The logo strip needs at least this many listed places, and shows at most MAX_PLACES. */
    public const MIN_PLACES = 6;

    public const MAX_PLACES = 12;

    /** The counters show only when every one of them is at least this. */
    public const MIN_RESTAURANTS = 10;

    public const MIN_VIEWS = 1000;

    public const MIN_ORDERS = 200;

    /**
     * Listed active places with their brand (name, English name, logo), most recent first; [] under MIN_PLACES.
     *
     * @return list<array{slug:string, name:string, name_en:string, logo:?string}>
     */
    public static function places(): array
    {
        return Cache::remember('social_proof.places', self::TTL_SECONDS, function () {
            $tenants = Directory::query()->where('status', 'active')->orderByDesc('created_at')->limit(self::MAX_PLACES)->get(['id', 'name']);
            if ($tenants->count() < self::MIN_PLACES) {
                return [];
            }
            $settings = DB::table('tenant_settings')->whereIn('tenant_id', $tenants->pluck('id'))
                ->whereIn('key', ['brand_name', 'brand_name_en', 'logo'])->get()
                ->groupBy('tenant_id')->map(fn ($rows) => $rows->pluck('value', 'key')->map(fn ($v) => trim((string) $v))->all());

            return $tenants->map(function (Tenant $t) use ($settings) {
                $s = $settings[$t->id] ?? [];

                return [
                    'slug' => $t->id,
                    'name' => ($s['brand_name'] ?? '') !== '' ? $s['brand_name'] : (string) $t->name,
                    'name_en' => $s['brand_name_en'] ?? '',
                    'logo' => ($s['logo'] ?? '') !== '' ? '/storage/'.$s['logo'] : null,
                ];
            })->values()->all();
        });
    }

    /**
     * Live restaurants, menu views and orders placed; null until every counter passes its floor.
     *
     * @return ?array{restaurants:int, views:int, orders:int}
     */
    public static function counters(): ?array
    {
        // "not enough yet" is cached as false: a null would be taken for a cache miss and recomputed on every visit
        $cached = Cache::remember('social_proof.counters', self::TTL_SECONDS, function () {
            $counters = [
                'restaurants' => Tenant::query()->where('status', 'active')->count(),
                'views' => (int) DB::table('menu_visits')->sum('views'),
                'orders' => DB::table('orders')->where('status', '!=', 'cancelled')->count(),
            ];
            $enough = $counters['restaurants'] >= self::MIN_RESTAURANTS && $counters['views'] >= self::MIN_VIEWS && $counters['orders'] >= self::MIN_ORDERS;

            return $enough ? $counters : false;
        });

        return $cached ?: null;
    }

    /**
     * Compact, never inflated: 37 → "37", 248 → "240+", 1430 → "1.4K+", 25800 → "25K+", 2300000 → "2.3M+".
     * Rounding is always downwards so the number on the page is one we really passed.
     */
    public static function compact(int $n): string
    {
        if ($n < 100) {
            return (string) $n;
        }
        if ($n < 1000) {
            return (intdiv($n, 10) * 10).'+';
        }
        [$unit, $divisor] = $n >= 1_000_000 ? ['M', 1_000_000] : ['K', 1000];
        $value = floor($n / $divisor * 10) / 10;
        $text = $value >= 10 ? (string) (int) floor($value) : rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');

        return $text.$unit.'+';
    }

    public static function flush(): void
    {
        Cache::forget('social_proof.places');
        Cache::forget('social_proof.counters');
    }
}
