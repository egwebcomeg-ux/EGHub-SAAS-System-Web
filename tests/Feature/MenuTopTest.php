<?php

namespace Tests\Feature;

use App\Models\Banner;
use App\Models\Category;
use App\Models\Offer;
use App\Models\Season;
use App\Models\Tenant;
use App\Services\Settings;
use App\Support\MenuTicker;
use Tests\TenantTestCase;

/** The top of the public menu: the banner slideshow, the ticker and the "only one moving element" rule. */
class MenuTopTest extends TenantTestCase
{
    private function banners(Tenant $tenant, int $count): void
    {
        $this->as($tenant, function () use ($count) {
            for ($i = 1; $i <= $count; $i++) {
                Banner::query()->create(['image' => "tenants/x/banners/$i.webp", 'title' => "Banner $i"]);
            }
        });
    }

    /** A goal and a season: two ticker lines. */
    private function tickerLines(Tenant $tenant): void
    {
        $croissant = $this->item($tenant, 'Croissant', 35);
        $this->as($tenant, function () use ($croissant) {
            Settings::put(['cart_goal_enabled' => '1', 'cart_goal_amount' => '150', 'cart_goal_reward' => 'item', 'cart_goal_item' => (string) $croissant->id]);
            Season::query()->create(['name' => 'R', 'preset' => 'ramadan', 'starts_on' => local_today(), 'ends_on' => local_today(), 'greeting' => 'رمضان كريم', 'accent' => '#0F6B4F']);
        });
    }

    public function test_the_slideshow_autoplays_and_the_ticker_then_stands_still(): void
    {
        $a = $this->restaurant('cafe-a');
        $this->item($a);
        $this->banners($a, 3);
        $this->tickerLines($a);

        $html = $this->get($this->subUrl('cafe-a'))->assertOk()
            ->assertSee('data-banner-autoplay="4.5"', false)
            ->assertSee('data-ticker-mode="static"', false)
            ->assertSee('data-ticker-line="goal"', false)->assertSee('data-ticker-line="season"', false)
            ->getContent();
        $this->assertSame(3, substr_count($html, 'data-banner-dot='));
        $this->assertSame(3, substr_count($html, 'data-banner-slide'));
        // standing still: the "next line" button is there from the start
        $this->assertMatchesRegularExpression('#class="m-ticker-next grid[^"]*"\s+data-ticker-next#', $html);
        $this->assertStringNotContainsString('data-goal-strip', $html);
    }

    public function test_without_autoplay_or_with_one_banner_the_ticker_rotates(): void
    {
        $a = $this->restaurant('cafe-a');
        $this->item($a);
        $this->banners($a, 2);
        $this->tickerLines($a);
        $this->as($a, fn () => Settings::put(['banner_autoplay' => '0']));

        $this->get($this->subUrl('cafe-a'))->assertOk()
            ->assertDontSee('data-banner-autoplay=', false)->assertSee('data-banner-dots', false)
            ->assertSee('data-ticker-mode="rotate"', false)->assertSee('data-ticker-seconds="4"', false);

        $this->as($a, function () {
            Settings::put(['banner_autoplay' => '1']);
            Banner::query()->latest('id')->first()->delete();
        });
        $this->get($this->subUrl('cafe-a'))->assertOk()
            ->assertSee('data-banners', false)->assertDontSee('data-banner-autoplay=', false)->assertDontSee('data-banner-dots', false)
            ->assertSee('data-ticker-mode="rotate"', false);

        $this->assertTrue(MenuTicker::moves(2, false));
        $this->assertFalse(MenuTicker::moves(2, true));
        $this->assertFalse(MenuTicker::moves(1, false));
        $this->assertFalse(MenuTicker::bannerAutoplay(1, []));
        $this->assertTrue(MenuTicker::bannerAutoplay(2, []));
    }

    public function test_ticker_lines_from_offers_goal_owner_lines_and_season_with_safe_links(): void
    {
        $a = $this->restaurant('cafe-a');
        $latte = $this->item($a, 'Latte', 60);
        $this->tickerLines($a);
        $hidden = $this->as($a, fn () => Category::query()->create(['name' => 'Hidden', 'is_active' => false]));
        $this->as($a, function () use ($latte, $hidden) {
            Offer::query()->create(['title' => 'Happy hour', 'discount_percent' => 25, 'category_id' => $latte->category_id]);
            Settings::put(['menu_ticker_lines' => json_encode([
                ['text' => 'توصيل ببلاش', 'text_en' => 'Free delivery', 'link' => 'item:'.$latte->id],
                ['text' => 'جرب القهوة', 'text_en' => '', 'link' => 'category:'.$latte->category_id],
                ['text' => 'قسم مخفي', 'text_en' => '', 'link' => 'category:'.$hidden->id],
            ])]);
        });

        $html = $this->get($this->subUrl('cafe-a'))->assertOk()
            ->assertSee('-25% · Happy hour')->assertSee('توصيل ببلاش')->assertSee('رمضان كريم')->getContent();
        preg_match_all('#data-ticker-line="([a-z]+)"#', $html, $m);
        $this->assertSame(['offer', 'goal', 'owner', 'owner', 'owner', 'season'], $m[1]);
        $this->assertMatchesRegularExpression('#data-item="'.$latte->id.'" data-ticker-line="owner"#', $html);
        $this->assertMatchesRegularExpression('#href="\#cat-'.$latte->category_id.'" class="m-ticker-line is-on" data-ticker-line="offer"#', $html);
        // a link to a category that is not on the menu is dropped, the line stays
        $this->assertMatchesRegularExpression('#<span class="m-ticker-line" data-ticker-line="owner" aria-hidden="true" tabindex="-1">\s*<span aria-hidden="true">✨</span>\s*<span class="truncate">قسم مخفي#u', $html);
        // only the first line is announced; the others wait their turn
        $this->assertSame(1, substr_count($html, 'm-ticker-line is-on'));
    }

    public function test_the_owner_writes_ticker_lines_and_the_autoplay_switch(): void
    {
        $a = $this->restaurant('cafe-a');
        $latte = $this->item($a, 'Latte', 60);
        $b = $this->restaurant('cafe-b');
        $foreign = $this->item($b, 'Foreign', 10);
        $owner = $this->owner($a);

        $this->actingAs($owner)->get($this->dashUrl('/marketing'))->assertOk()->assertSee('data-ticker-form', false)->assertSee('name="banner_autoplay"', false);
        $this->actingAs($owner)->post($this->dashUrl('/marketing/ticker'), ['lines' => [['text' => 'X', 'link' => 'item:'.$foreign->id]]])
            ->assertSessionHasErrors('lines.0.link');
        $this->actingAs($owner)->post($this->dashUrl('/marketing/ticker'), ['lines' => array_fill(0, 6, ['text' => 'X'])])->assertSessionHasErrors('lines');
        $this->actingAs($owner)->post($this->dashUrl('/marketing/ticker'), ['lines' => [
            ['text' => 'سطر أول', 'text_en' => 'First', 'link' => 'item:'.$latte->id],
            ['text' => '', 'text_en' => '', 'link' => ''],
            ['text' => 'سطر تاني', 'link' => ''],
        ]])->assertSessionHasNoErrors();

        $lines = $this->as($a, fn () => MenuTicker::ownerLines(Settings::all()));
        $this->assertCount(2, $lines);
        $this->assertSame($latte->id, $lines[0]['item']);
        $this->get($this->subUrl('cafe-a'))->assertOk()->assertSee('سطر أول')->assertSee('سطر تاني');
        $this->get($this->subUrl('cafe-b'))->assertOk()->assertDontSee('سطر أول');

        $this->actingAs($owner)->post($this->dashUrl('/marketing/banners/autoplay'), [])->assertSessionHasNoErrors();
        $this->assertSame('0', $this->as($a, fn () => Settings::get('banner_autoplay')));

        // the feature gates the page and the lines on the menu
        $basic = $this->restaurant('cafe-basic', 'basic');
        $this->item($basic);
        $this->as($basic, fn () => Settings::put(['menu_ticker_lines' => json_encode([['text' => 'Gated line', 'text_en' => '', 'link' => '']])]));
        $this->get($this->subUrl('cafe-basic'))->assertOk()->assertDontSee('Gated line')->assertDontSee('data-ticker', false);
        $this->actingAs($this->owner($basic))->post($this->dashUrl('/marketing/ticker'), ['lines' => []])->assertForbidden();
    }
}
