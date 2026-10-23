<?php

namespace App\Services\Seo;

use App\Http\Controllers\Menu\MenuController;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Feedback;
use App\Models\MenuItem;
use App\Services\Entitlements;
use App\Services\Menu\MenuCatalog;
use App\Services\Settings;
use App\Support\BusinessType;
use App\Support\Cities;
use App\Support\Cuisines;
use App\Support\OpeningHours;
use Illuminate\Support\Collection;

/**
 * What search engines and link previews learn about the current restaurant: page title and description, the
 * profile facts (city, cuisines, branches, rating, ordering badges) and the schema.org JSON-LD for the profile
 * page (Restaurant / CafeOrCoffeeShop) and the menu (Menu → MenuSection → MenuItem). Runs in tenant context.
 */
final class RestaurantProfile
{
    /** @var array<string, string> */
    public readonly array $settings;

    public function __construct()
    {
        $this->settings = Settings::all();
    }

    public function name(): string
    {
        return tr($this->settings['brand_name'], $this->settings['brand_name_en']) ?: (string) tenant()->name;
    }

    public function city(): string
    {
        return Cities::name($this->settings['city']);
    }

    /** "المعادي، القاهرة" / "Maadi, Cairo" or "" */
    public function place(): string
    {
        $sep = app()->getLocale() === 'ar' ? '، ' : ', ';

        return implode($sep, array_filter([trim($this->settings['area']), $this->city()]));
    }

    /** @return list<string> */
    public function cuisines(): array
    {
        return array_map(fn ($k) => Cuisines::name($k), Cuisines::parse($this->settings['cuisine']));
    }

    public function typeLabel(): string
    {
        return BusinessType::label($this->settings['business_type']);
    }

    /** "منيو روستو في المعادي، القاهرة | EgMenu" */
    public function title(): string
    {
        $place = $this->place();

        return $place !== ''
            ? __('Menu of :name in :place | EgMenu', ['name' => $this->name(), 'place' => $place])
            : __('Menu of :name | EgMenu', ['name' => $this->name()]);
    }

    public function description(): string
    {
        $own = trim(tr($this->settings['short_description'], $this->settings['short_description_en']))
            ?: trim(tr($this->settings['tagline'], $this->settings['tagline_en']));
        if ($own !== '') {
            return $own;
        }
        $place = $this->place();

        return $place !== ''
            ? __(':name, :type in :place: the menu with prices, opening hours and location.', ['name' => $this->name(), 'type' => $this->typeLabel(), 'place' => $place])
            : __(':name, :type: the menu with prices, opening hours and location.', ['name' => $this->name(), 'type' => $this->typeLabel()]);
    }

    /** Absolute URL of an uploaded image, on the canonical host. */
    public function imageUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }
        $origin = (string) preg_replace('#^(https?://[^/]+).*$#', '$1', tenant()->url());

        return $origin.'/storage/'.ltrim($path, '/');
    }

    /** Preview image: the cover, else the logo, else the EgMenu card. */
    public function shareImage(): string
    {
        return $this->imageUrl($this->settings['cover'] ?: $this->settings['logo']) ?? versioned_asset('og-image.png');
    }

    /** @return array{avg: float, count: int}|null average guest rating, only with the feedback feature */
    public function rating(): ?array
    {
        if (! Entitlements::current()->allows('feedback')) {
            return null;
        }
        $count = Feedback::query()->count();

        return $count > 0 ? ['avg' => round((float) Feedback::query()->avg('rating'), 1), 'count' => $count] : null;
    }

    /** @return Collection<int, Branch> */
    public function branches(): Collection
    {
        return Branch::query()->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get();
    }

    /** @return array{table: bool, takeaway: bool, delivery: bool} what guests can order here */
    public function ordering(Branch $branch): array
    {
        $online = MenuController::onlineOptions($branch, $this->settings);

        return [
            'table' => Entitlements::current()->allows('qr_ordering') && $this->settings['ordering_enabled'] === '1',
            'takeaway' => (bool) ($online['takeaway'] ?? false),
            'delivery' => (bool) ($online['delivery'] ?? false),
        ];
    }

    /** Up to 8 items to show off: the featured ones, else the first items of the menu. */
    public function featuredItems(Collection $categories): Collection
    {
        $items = $categories->flatMap(fn (Category $c) => $c->items);
        $featured = $items->where('is_featured', true);

        return ($featured->isNotEmpty() ? $featured : $items)->take(8)->values();
    }

    public function whatsapp(Branch $branch): string
    {
        return (string) ($branch->whatsapp ?: $this->settings['whatsapp']);
    }

    public function mapUrl(Branch $branch): ?string
    {
        if ($branch->maps_url) {
            return $branch->maps_url;
        }

        return $branch->lat && $branch->lng ? 'https://www.google.com/maps/search/?api=1&query='.$branch->lat.','.$branch->lng : null;
    }

    /** @return array<string, mixed> schema.org Restaurant / CafeOrCoffeeShop for the profile page */
    public function placeSchema(Collection $branches): array
    {
        $main = $branches->first();
        $schema = [
            '@context' => 'https://schema.org',
            '@type' => match ($this->settings['business_type']) {
                'cafe' => 'CafeOrCoffeeShop',
                'pastry' => 'Bakery',
                default => 'Restaurant',
            },
            '@id' => tenant()->url('about').'#place',
            'name' => $this->name(),
            'url' => tenant()->url('about'),
            'description' => $this->description(),
            'hasMenu' => tenant()->url(),
            'image' => $this->shareImage(),
            'logo' => $this->imageUrl($this->settings['logo']),
            'telephone' => ($main?->phone ?: $this->settings['phone']) ?: null,
            'servesCuisine' => Cuisines::schemaNames($this->settings['cuisine']) ?: null,
            'currenciesAccepted' => 'EGP',
            'address' => $this->address($main),
            'geo' => $main?->lat && $main?->lng ? ['@type' => 'GeoCoordinates', 'latitude' => $main->lat, 'longitude' => $main->lng] : null,
            'openingHours' => OpeningHours::toSchema($main?->working_hours),
            'sameAs' => array_values(array_filter([$this->settings['instagram'], $this->settings['facebook'], $this->settings['tiktok']])) ?: null,
        ];
        if ($rating = $this->rating()) {
            $schema['aggregateRating'] = ['@type' => 'AggregateRating', 'ratingValue' => $rating['avg'], 'reviewCount' => $rating['count'], 'bestRating' => 5, 'worstRating' => 1];
        }
        if ($branches->count() > 1) {
            $schema['department'] = $branches->skip(1)->map(fn (Branch $b) => array_filter([
                '@type' => $schema['@type'], 'name' => $this->name().' – '.tr($b->name, $b->name_en),
                'address' => $this->address($b), 'telephone' => $b->phone ?: null, 'openingHours' => OpeningHours::toSchema($b->working_hours),
            ]))->values()->all();
        }

        return array_filter($schema, fn ($v) => $v !== null);
    }

    /**
     * @param  Collection<int, Category>  $categories  as MenuCatalog returns them
     * @return array<string, mixed> schema.org Menu with its sections and items (prices in EGP)
     */
    public function menuSchema(Collection $categories): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'Menu',
            '@id' => tenant()->url().'#menu',
            'name' => __('Menu of :name', ['name' => $this->name()]),
            'url' => tenant()->url(),
            'inLanguage' => app()->getLocale(),
            'hasMenuSection' => $categories->map(fn (Category $c) => [
                '@type' => 'MenuSection',
                'name' => tx($c, 'name'),
                'hasMenuItem' => $c->items->map(fn (MenuItem $i) => array_filter([
                    '@type' => 'MenuItem',
                    'name' => $i->displayName(),
                    'description' => trim(tx($i, 'description')) ?: null,
                    'image' => $this->imageUrl($i->image),
                    'offers' => ['@type' => 'Offer', 'price' => number_format((float) $i->price, 2, '.', ''), 'priceCurrency' => 'EGP'],
                ]))->values()->all(),
            ])->values()->all(),
        ];
    }

    /** The menu of the main branch (or the one asked for), for the profile page and the JSON-LD. */
    public static function catalog(?Branch $branch = null): Collection
    {
        return MenuCatalog::forBranch($branch)->categories();
    }

    /** JSON for a <script type="application/ld+json"> block. */
    public static function json(array $data): string
    {
        return (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
    }

    /** @return array<string, string>|null */
    private function address(?Branch $branch): ?array
    {
        $street = trim((string) $branch?->address);
        $area = trim($this->settings['area']);
        $city = Cities::exists($this->settings['city']) ? Cities::ALL[$this->settings['city']][1] : '';
        if ($street === '' && $area === '' && $city === '') {
            return null;
        }

        return array_filter([
            '@type' => 'PostalAddress', 'streetAddress' => $street, 'addressLocality' => $area ?: $city,
            'addressRegion' => $city, 'addressCountry' => 'EG',
        ], fn ($v) => $v !== '');
    }
}
