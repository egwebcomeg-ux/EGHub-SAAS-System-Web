<?php

namespace App\Services\Screens;

use App\Models\Branch;
use App\Models\Category;
use App\Models\MenuItem;
use App\Models\Offer;
use App\Models\Order;
use App\Models\PagerTicket;
use App\Models\TvScreen;
use App\Models\TvSlide;
use App\Services\Entitlements;
use App\Services\Menu\MenuCatalog;
use App\Services\Settings;
use App\Support\MenuLocales;
use Illuminate\Support\Collection;

/**
 * What a TV screen plays, as the JSON the player (resources/views/screen/player.blade.php) reads: the screen's look,
 * the brand, and its slides in order with everything each one needs, so the device can keep looping it offline.
 * Built inside the screen's restaurant. Menu prices come from MenuCatalog (branch prices, running offers, dayparts);
 * sold-out items are left out. Every slide carries its hours and whether it shows right now (`live`): the player
 * re-checks the hours on its own clock (corrected by the server time) while it is offline.
 *
 * version() is a short fingerprint of the same payload: the player polls it and swaps the slides in place when it
 * changes (a price, a sold-out item, an offer starting, a slide edited, the hour a breakfast slide ends).
 */
final class ScreenPlaylist
{
    /** How often the player asks for the fingerprint, in seconds. */
    public const POLL_SECONDS = 45;

    /** Ready numbers stay on screen this long after the order turned ready. */
    public const READY_MINUTES = 30;

    private ?MenuCatalog $catalog = null;

    /** @var Collection<int, Category>|null */
    private ?Collection $categories = null;

    public function __construct(public readonly TvScreen $screen) {}

    /** @return array<string, mixed> */
    public function payload(): array
    {
        $content = $this->content();

        return ['v' => $this->hash($content), 'now' => (int) floor(microtime(true) * 1000), 'tz' => now()->utcOffset(),
            'poll' => self::POLL_SECONDS, 'reload' => $this->screen->reload_requested_at?->timestamp] + $content;
    }

    public function version(): string
    {
        return $this->hash($this->content());
    }

    /**
     * Order numbers ready for pickup (and still being prepared) in the screen's branch: numbers only, no names,
     * no items. Orders that turned ready in the last READY_MINUTES, and pager tickets called today.
     *
     * @return array{ready: list<int|string>, preparing: list<int|string>}
     */
    public function readyNumbers(?int $branchId): array
    {
        if (! Entitlements::current()->allows(TvSlide::READY_FEATURE)) {
            return ['ready' => [], 'preparing' => []];
        }
        $branch = $this->branchFor($branchId);
        $orders = Order::query()->where('branch_id', $branch->id)
            ->where(fn ($q) => $q->whereIn('status', ['new', 'preparing'])
                ->orWhere(fn ($q) => $q->where('status', 'ready')->where('ready_at', '>=', now()->subMinutes(self::READY_MINUTES))))
            ->where('created_at', '>=', now()->subHours(12))
            ->orderBy('id')->get(['number', 'status']);
        $called = PagerTicket::query()->where('branch_id', $branch->id)->where('status', 'called')
            ->whereDate('service_date', today())->orderBy('called_at')->pluck('number');

        return [
            'ready' => $orders->where('status', 'ready')->pluck('number')->merge($called)->unique()->values()->all(),
            'preparing' => $orders->whereIn('status', ['new', 'preparing'])->pluck('number')->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function content(): array
    {
        $previousLocale = app()->getLocale();
        $previousExtra = MenuLocales::current();
        $language = $this->language();
        app()->setLocale($language === 'en' ? 'en' : 'ar');
        if (app()->bound('request')) {
            MenuLocales::use(null);
        }
        try {
            $settings = Settings::all();
            $color = fn (string $key, string $default) => is_hex_color($settings[$key] ?? null) ? $settings[$key] : $default;
            $slides = $this->slides();
            $now = now();

            return [
                'screen' => [
                    'id' => $this->screen->id,
                    'name' => $this->screen->name,
                    'orientation' => $this->screen->orientation ?: 'landscape',
                    'theme' => $this->screen->theme ?: 'dark',
                    'lang' => $language,
                    'dir' => $language === 'en' ? 'ltr' : 'rtl',
                    'ticker' => (string) $this->screen->ticker_text,
                    'hours' => $this->screen->hoursForPlayer(),
                    'on' => $this->screen->isOnAt($now),
                ],
                'brand' => [
                    'name' => tr($settings['brand_name'] ?? '', $settings['brand_name_en'] ?? '') ?: (string) tenant()->name,
                    'logo' => ($settings['logo'] ?? '') ? '/storage/'.$settings['logo'] : null,
                    'primary' => $color('color_primary', '#F26522'),
                    'secondary' => $color('color_secondary', '#0A2540'),
                    'currency' => tr($settings['currency'] ?? '', $settings['currency_en'] ?? ''),
                ],
                'labels' => [
                    'sold_out' => __('Sold out'), 'ready' => __('Ready for pickup'), 'preparing' => __('Preparing'),
                    'off' => __(':percent% off', ['percent' => '%s']), 'until' => __('Until :time', ['time' => '%s']),
                    'offline' => __('Offline'), 'none_ready' => __('Your number shows here when your order is ready'),
                ],
                'ready_allowed' => Entitlements::current()->allows(TvSlide::READY_FEATURE),
                'slides' => $slides,
                'media' => collect($slides)->flatMap(fn (array $s) => array_filter([$s['url'] ?? null, $s['side']['url'] ?? null, $s['item']['image'] ?? null]))
                    ->merge(collect($slides)->flatMap(fn (array $s) => collect($s['categories'] ?? [])->flatMap(fn ($c) => array_column($c['items'], 'image'))))
                    ->merge(collect($slides)->flatMap(fn (array $s) => array_column($s['items'] ?? [], 'image')))
                    ->push(($settings['logo'] ?? '') ? '/storage/'.$settings['logo'] : null)
                    ->filter()->unique()->values()->all(),
            ];
        } finally {
            app()->setLocale($previousLocale);
            if (app()->bound('request')) {
                MenuLocales::use($previousExtra);
            }
        }
    }

    /** @return list<array<string, mixed>> */
    private function slides(): array
    {
        $slides = $this->screen->slides()->where('is_active', true)->with(['media', 'sideMedia', 'offer.category', 'item'])->get();
        if ($slides->isEmpty()) {
            // a screen made before playlists: its menu, as it always showed
            $menu = $this->menuSlide(new TvSlide([
                'type' => 'menu', 'duration' => max(5, (int) $this->screen->rotate_seconds), 'layout' => 'list',
                'columns' => $this->screen->columns ?: 3, 'show_images' => $this->screen->show_images, 'category_ids' => $this->screen->category_ids,
            ]), $this->screen->layout === 'rotate' ? max(1, (int) $this->screen->columns) : 0);

            return $menu ? [$menu + ['hours' => null, 'live' => true]] : [];
        }
        $now = now();
        $out = [];
        foreach ($slides as $slide) {
            $data = match ($slide->type) {
                'menu' => $this->menuSlide($slide),
                'image', 'video' => $this->mediaSlide($slide),
                'offer' => $this->offerSlide($slide),
                'ready' => $this->readySlide($slide),
                default => null,
            };
            if ($data === null) {
                continue;
            }
            $data['hours'] = $slide->hoursForPlayer();
            $data['live'] = ($data['live'] ?? true) && $slide->isOnAt($now);
            $out[] = $data;
        }

        return $out;
    }

    /** @return array<string, mixed>|null */
    private function menuSlide(TvSlide $slide, int $perPage = 0): ?array
    {
        $categories = $this->categories();
        if ($slide->category_ids) {
            $order = array_flip(array_map('intval', $slide->category_ids));
            $categories = $categories->filter(fn (Category $c) => isset($order[$c->id]))
                ->sortBy(fn (Category $c) => $order[$c->id])->values();
        }
        // one featured item is shown with its photo whatever the photo switch says
        $showImages = (bool) $slide->show_images || $slide->layout === 'featured';
        $rows = $categories->map(fn (Category $c) => [
            'id' => $c->id,
            'name' => $this->text($c, 'name'),
            'alt' => $this->alt($c, 'name'),
            'items' => $c->items->reject(fn (MenuItem $i) => $i->is_sold_out)
                ->map(fn (MenuItem $i) => $this->itemRow($i, $showImages))->values()->all(),
        ])->filter(fn (array $c) => $c['items'] !== [])->values()->all();

        $base = ['id' => $slide->id ?? 0, 'type' => 'menu', 'duration' => max(5, (int) $slide->duration), 'layout' => $slide->layout ?: 'list',
            'columns' => max(1, min(4, (int) ($slide->columns ?: 3))), 'show_images' => $showImages, 'per_page' => $perPage,
            'side' => $slide->sideMedia ? ['kind' => $slide->sideMedia->kind, 'url' => $slide->sideMedia->url()] : null];

        if ($slide->layout === 'featured') {
            $items = collect($rows)->flatMap(fn (array $c) => $c['items']);
            $featured = ($slide->item_id ? $items->firstWhere('id', $slide->item_id) : null)
                ?? $items->first(fn (array $i) => $i['image'] !== null) ?? $items->first();
            if (! $featured) {
                return null;
            }

            return $base + ['item' => $featured, 'categories' => []];
        }

        return $rows === [] ? null : $base + ['categories' => $rows];
    }

    /** @return array<string, mixed>|null */
    private function mediaSlide(TvSlide $slide): ?array
    {
        $media = $slide->media;
        if (! $media || $media->kind !== $slide->type) {
            return null;
        }

        return ['id' => $slide->id, 'type' => $slide->type, 'duration' => (int) $slide->duration, 'url' => $media->url()];
    }

    /** @return array<string, mixed>|null */
    private function offerSlide(TvSlide $slide): ?array
    {
        if (! Entitlements::current()->allows('offers')) {
            return null;
        }
        $branch = $this->branchFor(null);
        $offers = $slide->offer_id
            ? collect([$slide->offer])->filter()
            : Offer::query()->with('category')->where('is_active', true)->orderBy('id')->get();
        $offers = $offers->filter(fn (Offer $o) => $o->is_active && (! $o->branch_id || $o->branch_id === $branch->id))->values();
        if ($offers->isEmpty()) {
            return null;
        }
        $now = now();
        $running = $offers->filter(fn (Offer $o) => $o->runsAt($now, $branch->id));
        $categoryIds = $running->pluck('category_id');
        $items = collect($this->categories())
            ->filter(fn (Category $c) => $running->isNotEmpty() && ($categoryIds->contains(null) || $categoryIds->contains($c->id)))
            ->flatMap(fn (Category $c) => $c->items)->reject(fn (MenuItem $i) => $i->is_sold_out)
            ->filter(fn (MenuItem $i) => $i->getAttribute('offer_percent'))
            ->sortByDesc(fn (MenuItem $i) => $i->image ? 1 : 0)->take(6)
            ->map(fn (MenuItem $i) => $this->itemRow($i, true))->values()->all();

        return [
            'id' => $slide->id, 'type' => 'offer', 'duration' => max(5, (int) $slide->duration), 'live' => $running->isNotEmpty(),
            'offers' => $offers->map(fn (Offer $o) => [
                'id' => $o->id,
                'title' => tr($o->title, $o->title_en),
                'percent' => (int) $o->discount_percent,
                'category' => $o->category ? $this->text($o->category, 'name') : '',
                'days' => $o->days ? array_values(array_map('intval', $o->days)) : null,
                'from' => $o->starts_at ? substr((string) $o->starts_at, 0, 5) : null,
                'to' => $o->ends_at ? substr((string) $o->ends_at, 0, 5) : null,
                'starts_on' => $o->starts_on?->toDateString(),
                'ends_on' => $o->ends_on?->toDateString(),
            ])->all(),
            'items' => $items,
        ];
    }

    /** @return array<string, mixed>|null */
    private function readySlide(TvSlide $slide): ?array
    {
        if (! Entitlements::current()->allows(TvSlide::READY_FEATURE)) {
            return null;
        }

        return ['id' => $slide->id, 'type' => 'ready', 'duration' => max(5, (int) $slide->duration),
            'branch' => $slide->branch_id ?? $this->screen->branch_id];
    }

    /** @return array<string, mixed> */
    private function itemRow(MenuItem $item, bool $withImage): array
    {
        $was = $item->getAttribute('offer_from');

        return [
            'id' => $item->id,
            'name' => $this->text($item, 'name'),
            'alt' => $this->alt($item, 'name'),
            'desc' => mb_substr($this->text($item, 'description'), 0, 140),
            'price' => money($item->price),
            'was' => $was ? money($was) : null,
            'image' => $withImage && $item->image ? '/storage/'.$item->image : null,
        ];
    }

    /** @return Collection<int, Category> */
    private function categories(): Collection
    {
        if ($this->categories === null) {
            $this->catalog = MenuCatalog::forBranch($this->branchFor(null));
            $this->categories = collect($this->catalog->categories());
        }

        return $this->categories;
    }

    private function branchFor(?int $branchId): Branch
    {
        $id = $branchId ?? $this->screen->branch_id;
        $branch = $id ? Branch::query()->where('is_active', true)->find($id) : null;

        return $branch ?? MenuCatalog::defaultBranch();
    }

    /** ar, en or both (Arabic first, English under it); '' follows the restaurant's default menu language. */
    private function language(): string
    {
        $lang = (string) $this->screen->language;
        if (in_array($lang, ['ar', 'en', 'both'], true)) {
            return $lang;
        }

        return Settings::get('default_locale', 'ar') === 'en' ? 'en' : 'ar';
    }

    private function text(object $model, string $field): string
    {
        $ar = (string) ($model->{$field} ?? '');
        $en = (string) ($model->{$field.'_en'} ?? '');

        return $this->language() === 'en' && $en !== '' ? $en : $ar;
    }

    /** The English line under the Arabic one on a "both" screen. */
    private function alt(object $model, string $field): string
    {
        $en = (string) ($model->{$field.'_en'} ?? '');

        return $this->language() === 'both' && $en !== '' && $en !== (string) $model->{$field} ? $en : '';
    }

    /** @param array<string, mixed> $content */
    private function hash(array $content): string
    {
        return substr(md5(json_encode([$content, $this->screen->updated_at?->timestamp], JSON_UNESCAPED_UNICODE)), 0, 16);
    }
}
