<?php

namespace App\Support;

use App\Models\Category;
use App\Models\MenuItem;
use App\Models\Offer;
use App\Models\Season;
use App\Services\Entitlements;
use Illuminate\Support\Collection;

/**
 * The thin strip under the menu header that shows one short line at a time: running timed offers (happy hour),
 * one combo with a saving, the cart goal, the owner's own lines (setting menu_ticker_lines, up to five, each may
 * open an item or jump to a category) and the season greeting. It replaces the separate goal and season strips;
 * the goal progress inside the cart stays.
 *
 * One moving element at the top of the menu: when the banner slideshow autoplays (two or more banners, setting
 * banner_autoplay on), the ticker stands still on its first line and the guest steps through the others with a
 * small "next" button; the ticker rotates by itself (a soft fade every few seconds) only when the banners do not
 * move. Under prefers-reduced-motion nothing moves on its own (resources/js/menu.js).
 */
final class MenuTicker
{
    public const MAX_OWNER_LINES = 5;

    public const MAX_LENGTH = 80;

    /** Seconds per line while the ticker rotates. */
    public const SECONDS = 4;

    /** Seconds per banner while the slideshow autoplays. */
    public const BANNER_SECONDS = 4.5;

    /**
     * Whether the banner strip plays as a slideshow: two or more banners and the owner did not switch it off.
     */
    public static function bannerAutoplay(int $banners, array $settings): bool
    {
        return $banners > 1 && ($settings['banner_autoplay'] ?? '1') !== '0';
    }

    /**
     * Whether the ticker rotates by itself. See the class comment: never while the slideshow autoplays.
     */
    public static function moves(int $lines, bool $bannerAutoplay): bool
    {
        return $lines > 1 && ! $bannerAutoplay;
    }

    /**
     * The lines of the ticker, in order: offers, a combo, the cart goal, the owner's lines, the season greeting.
     * Links point only at items and categories that are on the menu right now.
     *
     * @param  iterable<Offer>  $offers
     * @param  array{label:string}|null  $goal  the cart goal when the menu shows it
     * @param  Collection<int, MenuItem>  $available  items on the menu, by id
     * @param  Collection<int, Category>  $shownCategories  categories on the menu, by id
     * @return list<array{kind:string, icon:string, text:string, item:?int, category:?int}>
     */
    public static function lines(iterable $offers, ?array $goal, ?Season $season, array $settings, Collection $available, Collection $shownCategories, string $currency): array
    {
        $lines = [];
        $offersOn = Entitlements::current()->allows('offers');
        $link = static fn (?int $item, ?int $category) => [
            'item' => $item && $available->has($item) ? $item : null,
            'category' => $category && $shownCategories->has($category) ? $category : null,
        ];
        foreach ($offers as $offer) {
            $text = '-'.$offer->discount_percent.'% · '.tr($offer->title, $offer->title_en);
            if ($offer->ends_at) {
                $text .= ' · '.__('until :time', ['time' => local_time(local_at()->setTimeFromTimeString($offer->ends_at), 'h:i A')]);
            }
            $lines[] = ['kind' => 'offer', 'icon' => '🔥', 'text' => $text] + $link(null, $offer->category_id);
        }
        $combo = $offersOn ? $available->first(fn (MenuItem $i) => ! $i->is_sold_out && $i->relationLoaded('comboParts') && $i->comboParts->isNotEmpty()
            && $i->comboParts->sum(fn ($p) => $p->price * $p->pivot->quantity) - $i->price >= 1) : null;
        if ($combo) {
            $saving = $combo->comboParts->sum(fn ($p) => $p->price * $p->pivot->quantity) - $combo->price;
            $lines[] = ['kind' => 'combo', 'icon' => '🍱', 'text' => __(':name combo, save :amount', ['name' => $combo->displayName(), 'amount' => money($saving).' '.$currency])] + $link($combo->id, null);
        }
        if ($goal && $goal['label'] !== '') {
            $lines[] = ['kind' => 'goal', 'icon' => '🎁', 'text' => $goal['label'], 'item' => null, 'category' => null];
        }
        if ($offersOn) {
            foreach (self::ownerLines($settings) as $own) {
                $text = tr($own['text'], $own['text_en']);
                if ($text !== '') {
                    $lines[] = ['kind' => 'owner', 'icon' => '✨', 'text' => $text] + $link($own['item'], $own['category']);
                }
            }
        }
        if ($season && $season->greetingText() !== '') {
            $lines[] = ['kind' => 'season', 'icon' => $season->icon(), 'text' => $season->greetingText(), 'item' => null, 'category' => null];
        }

        return $lines;
    }

    /**
     * The owner's lines as saved by the offers page: text, English text and an optional link ("item:12",
     * "category:3" or "").
     *
     * @return list<array{text:string, text_en:string, link:string, item:?int, category:?int}>
     */
    public static function ownerLines(array $settings): array
    {
        $saved = json_decode((string) ($settings['menu_ticker_lines'] ?? ''), true);
        if (! is_array($saved)) {
            return [];
        }
        $lines = [];
        foreach (array_slice(array_values($saved), 0, self::MAX_OWNER_LINES) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $link = (string) ($row['link'] ?? '');
            [$type, $id] = array_pad(explode(':', $link, 2), 2, '');
            $lines[] = [
                'text' => mb_substr(trim((string) ($row['text'] ?? '')), 0, self::MAX_LENGTH),
                'text_en' => mb_substr(trim((string) ($row['text_en'] ?? '')), 0, self::MAX_LENGTH),
                'link' => $link,
                'item' => $type === 'item' && ctype_digit($id) ? (int) $id : null,
                'category' => $type === 'category' && ctype_digit($id) ? (int) $id : null,
            ];
        }

        return $lines;
    }
}
