<?php

namespace App\Services\Seo;

use App\Models\AddonPrice;
use App\Models\Tenant;
use App\Services\Entitlements;
use App\Services\Settings;
use App\Support\BusinessType;
use App\Support\Cities;
use App\Support\Cuisines;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The public restaurant directory on the main domain (eghub.app/restaurants) and the sitemap. Runs outside any
 * tenant context and only reads what a restaurant already shows on its public menu: settings rows, counts of
 * active items and the average rating. A place is listed when it did not opt out (listed_in_directory), EgWeb did not
 * hide it (tenants.hidden_from_directory: demos and tests), it is not suspended or deleted, and it has at least
 * MIN_ITEMS active items, so empty sign-ups never show up.
 */
final class Directory
{
    public const MIN_ITEMS = 3;

    public const PER_PAGE = 24;

    /** Places with the featured_listing add-on shown first on a page; more than this take turns day by day. */
    public const FEATURED_PER_PAGE = 5;

    /** Settings read for the cards. */
    private const CARD_KEYS = ['brand_name', 'brand_name_en', 'logo', 'city', 'area', 'cuisine', 'business_type',
        'ordering_enabled', 'online_delivery', 'online_takeaway', 'short_description', 'short_description_en'];

    /** @return Builder<Tenant> listed places, optionally in one city / serving one cuisine / matching a search */
    public static function query(?string $city = null, ?string $cuisine = null, ?string $search = null): Builder
    {
        $query = Tenant::query()
            ->where(fn ($q) => $q->whereNull('status')->orWhere('status', '!=', 'suspended'))
            ->where('hidden_from_directory', false)
            ->whereNotIn('id', self::settingIds('listed_in_directory', fn ($q) => $q->where('value', '0')))
            ->whereIn('id', DB::table('menu_items')->select('tenant_id')->where('is_active', true)
                ->groupBy('tenant_id')->havingRaw('count(*) >= ?', [self::MIN_ITEMS]));

        if ($city !== null) {
            $query->whereIn('id', self::settingIds('city', fn ($q) => $q->where('value', $city)));
        }
        if ($cuisine !== null) {
            $query->whereIn('id', self::settingIds('cuisine', fn ($q) => $q->where(fn ($w) => $w->where('value', $cuisine)
                ->orWhere('value', 'like', $cuisine.',%')->orWhere('value', 'like', '%,'.$cuisine)
                ->orWhere('value', 'like', '%,'.$cuisine.',%'))));
        }
        $search = trim(str_replace(['%', '_', '\\'], ' ', (string) $search));
        if ($search !== '') {
            $like = '%'.mb_substr($search, 0, 60).'%';
            $query->where(fn ($q) => $q->where('name', 'like', $like)->orWhereIn('id', DB::table('tenant_settings')->select('tenant_id')
                ->whereIn('key', ['brand_name', 'brand_name_en', 'area'])->where('value', 'like', $like)));
        }

        return $query;
    }

    /**
     * Listed places of this page whose plan or add-ons include featured_listing: at most FEATURED_PER_PAGE, in an
     * order that changes every day, so each paying place gets its turn on top. Nothing else changes their rank.
     *
     * @return list<string> tenant ids
     */
    public static function featuredIds(?string $city = null, ?string $cuisine = null, ?string $search = null): array
    {
        $packs = AddonPrice::query()->where('type', 'feature')->get(['key', 'features'])
            ->filter(fn (AddonPrice $a) => in_array('featured_listing', (array) $a->features, true))->pluck('key')->all();
        $subscriptions = DB::table('subscriptions')->select('tenant_id')->where(fn ($q) => $q
            ->whereIn('id', DB::table('subscription_addons')->select('subscription_id')->whereIn('key', $packs ?: ['']))
            ->orWhereIn('plan_id', DB::table('plan_features')->select('plan_id')->where('key', 'featured_listing')->where('value', 1)));

        $day = today()->toDateString();

        return self::query($city, $cuisine, $search)->whereIn('id', $subscriptions)->pluck('id')
            ->sortBy(fn (string $id) => crc32($day.'|'.$id))->take(self::FEATURED_PER_PAGE)->values()->all();
    }

    /**
     * Number of listed places per city slug, most first.
     *
     * @return array<string, int>
     */
    public static function cityCounts(): array
    {
        return DB::table('tenant_settings')->where('key', 'city')
            ->whereIn('tenant_id', self::query()->select('id'))
            ->groupBy('value')->selectRaw('value, count(*) as n')->orderByDesc('n')->pluck('n', 'value')
            ->filter(fn ($n, $slug) => Cities::exists((string) $slug))->map(fn ($n) => (int) $n)->all();
    }

    /**
     * Cuisines offered by listed places of a city (or anywhere), with their count.
     *
     * @return array<string, int>
     */
    public static function cuisineCounts(?string $city = null): array
    {
        $counts = [];
        $rows = DB::table('tenant_settings')->where('key', 'cuisine')->whereIn('tenant_id', self::query($city)->select('id'))->pluck('value');
        foreach ($rows as $stored) {
            foreach (Cuisines::parse((string) $stored) as $key) {
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            }
        }
        arsort($counts);

        return $counts;
    }

    /**
     * Card data of listed places (one settings query, one ratings query, one zones query for the whole page).
     *
     * @param  iterable<Tenant>  $tenants
     * @param  list<string>  $featured  ids shown with the "Featured" badge (featuredIds())
     * @return list<array<string, mixed>>
     */
    public static function cards(iterable $tenants, array $featured = []): array
    {
        $tenants = collect($tenants);
        $ids = $tenants->pluck('id')->all();
        $settings = DB::table('tenant_settings')->whereIn('tenant_id', $ids)->whereIn('key', self::CARD_KEYS)->get()
            ->groupBy('tenant_id')->map(fn (Collection $rows) => $rows->pluck('value', 'key')->map(fn ($v) => (string) $v)->all());
        $ratings = DB::table('feedback')->whereIn('tenant_id', $ids)->groupBy('tenant_id')
            ->selectRaw('tenant_id, avg(rating) as avg, count(*) as n')->get()->keyBy('tenant_id');
        $zones = DB::table('delivery_zones')->whereIn('tenant_id', $ids)->where('is_active', true)->distinct()->pluck('tenant_id')->flip();

        return $tenants->map(function (Tenant $t) use ($settings, $ratings, $zones, $featured) {
            $s = array_merge(Settings::DEFAULTS, $settings[$t->id] ?? []);
            $rights = Entitlements::for($t);
            $ordering = $s['ordering_enabled'] === '1';
            $delivery = $ordering && $rights->allows('online_ordering') && $s['online_delivery'] === '1' && isset($zones[$t->id]);
            $rating = $rights->allows('feedback') && isset($ratings[$t->id])
                ? ['avg' => round((float) $ratings[$t->id]->avg, 1), 'count' => (int) $ratings[$t->id]->n] : null;

            return [
                'slug' => $t->id,
                'name' => tr($s['brand_name'], $s['brand_name_en']) ?: (string) $t->name,
                'logo' => $s['logo'] !== '' ? '/storage/'.$s['logo'] : null,
                'city' => Cities::name($s['city']),
                'area' => trim($s['area']),
                'cuisines' => array_map(fn ($k) => Cuisines::name($k), Cuisines::parse($s['cuisine'])),
                'type' => BusinessType::label(isset(BusinessType::TYPES[$s['business_type']]) ? $s['business_type'] : BusinessType::DEFAULT),
                'description' => tr($s['short_description'], $s['short_description_en']),
                'rating' => $rating,
                'table' => $ordering && $rights->allows('qr_ordering'),
                'delivery' => $delivery,
                // takeaway or delivery straight from the menu link
                'online' => $ordering && $rights->allows('online_ordering') && ($s['online_takeaway'] === '1' || $delivery),
                'featured' => in_array($t->id, $featured, true),
                'url' => $t->url('about'),
                'menu_url' => $t->url(),
            ];
        })->values()->all();
    }

    /**
     * Last change of each place's public menu (items or settings), for the sitemap's lastmod.
     *
     * @param  list<string>  $ids
     * @return array<string, string>
     */
    public static function lastModified(array $ids): array
    {
        return DB::table('menu_items')->whereIn('tenant_id', $ids)->groupBy('tenant_id')
            ->selectRaw('tenant_id, max(updated_at) as at')->pluck('at', 'tenant_id')->map(fn ($v) => (string) $v)->all();
    }

    /** tenant_id of settings rows with $key, narrowed by $where. */
    private static function settingIds(string $key, \Closure $where): QueryBuilder
    {
        $q = DB::table('tenant_settings')->select('tenant_id')->where('key', $key);
        $where($q);

        return $q;
    }
}
