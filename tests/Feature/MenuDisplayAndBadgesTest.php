<?php

namespace Tests\Feature;

use App\Models\MenuItem;
use App\Services\Settings;
use App\Support\ItemBadges;
use Tests\TenantTestCase;

/** The professional item page (shareable /item/{id}) and the badge sets with their optional filter chips. */
class MenuDisplayAndBadgesTest extends TenantTestCase
{
    private const SETTINGS = ['brand_name' => 'Cafe A', 'currency' => 'ج.م', 'currency_en' => 'EGP', 'color_primary' => '#111111',
        'color_secondary' => '#222222', 'color_bg' => '#FFFFFF', 'font' => 'Cairo', 'default_locale' => 'ar'];

    public function test_the_item_link_opens_the_item_with_its_own_preview_and_the_display_mode(): void
    {
        $a = $this->restaurant('cafe-a');
        $latte = $this->item($a, 'Latte', 60);
        $this->as($a, fn () => $latte->update(['description' => 'Double shot', 'image' => 'tenants/cafe-a/items/latte.webp']));

        // simple (default): the bottom sheet, the link still opens the item
        $this->get($this->subUrl('cafe-a'))->assertOk()->assertSee('"display":"simple"', false)->assertDontSee('data-item-page', false)->assertSee('"openItem":null', false);
        $this->get($this->subUrl('cafe-a', 'item/'.$latte->id))->assertOk()
            ->assertSee('<title>Latte | Restaurant cafe-a</title>', false)
            ->assertSee('<meta property="og:type" content="product">', false)
            ->assertSee('items/latte.webp" ', false)->assertSee('content="Double shot"', false)
            ->assertSee('"openItem":'.$latte->id, false);

        $this->as($a, fn () => Settings::put(['menu_display' => 'pro']));
        $this->get($this->subUrl('cafe-a'))->assertOk()->assertSee('"display":"pro"', false)
            ->assertSee('data-item-page', false)->assertSee('data-sheet-share', false)->assertSee('data-sheet-back', false);

        // an item of another restaurant or off the menu goes back to the menu
        $b = $this->restaurant('cafe-b');
        $foreign = $this->item($b, 'Foreign', 10);
        $this->get($this->subUrl('cafe-a', 'item/'.$foreign->id))->assertRedirect();
        $this->as($a, fn () => $latte->update(['is_active' => false]));
        $this->get($this->subUrl('cafe-a', 'item/'.$latte->id))->assertRedirect();
    }

    public function test_the_professional_display_follows_the_plan_and_the_setting_is_validated(): void
    {
        $a = $this->restaurant('cafe-a');
        $this->item($a);
        $owner = $this->owner($a);
        $this->actingAs($owner)->post($this->dashUrl('/settings'), self::SETTINGS + ['menu_display' => 'fancy'])->assertSessionHasErrors('menu_display');
        $this->actingAs($owner)->post($this->dashUrl('/settings'), self::SETTINGS + ['menu_display' => 'pro'])->assertSessionHasNoErrors();
        $this->assertSame('pro', $this->as($a, fn () => Settings::get('menu_display')));

        $basic = $this->restaurant('cafe-basic', 'basic');
        $this->item($basic);
        $this->as($basic, fn () => Settings::put(['menu_display' => 'pro']));
        $this->get($this->subUrl('cafe-basic'))->assertOk()->assertSee('"display":"simple"', false)->assertDontSee('data-item-page', false);
    }

    public function test_badges_by_business_type_and_the_item_form(): void
    {
        [$cafeMain] = ItemBadges::forBusiness('cafe');
        [$restaurantMain, $restaurantMore] = ItemBadges::forBusiness('restaurant');
        $this->assertSame('barista_pick', $cafeMain[0]);
        $this->assertContains('iced', $cafeMain);
        $this->assertContains('chef_pick', $restaurantMain);
        $this->assertContains('decaf', $restaurantMore);
        // every badge lives in exactly one group and the stored keys stay valid
        $this->assertEqualsCanonicalizing(array_keys(MenuItem::TAGS), [...$restaurantMain, ...$restaurantMore]);
        foreach (['vegetarian', 'vegan', 'spicy', 'new', 'bestseller', 'gluten_free', 'nuts', 'sugar_free'] as $old) {
            $this->assertArrayHasKey($old, MenuItem::TAGS);
        }

        $a = $this->restaurant('cafe-a');
        $latte = $this->item($a, 'Latte', 60);
        $owner = $this->owner($a);
        $this->actingAs($owner)->get($this->dashUrl('/menu/items/'.$latte->id))->assertOk()
            ->assertSee('value="barista_pick"', false)->assertSee('data-badges-more', false);
        $this->actingAs($owner)->post($this->dashUrl('/menu/items/'.$latte->id), [
            'category_id' => $latte->category_id, 'name' => 'Latte', 'price' => 60, 'is_active' => '1', 'tags' => ['healthy', 'iced', 'barista_pick'],
        ])->assertSessionHasNoErrors();
        $this->assertSame(['healthy', 'iced', 'barista_pick'], $this->as($a, fn () => $latte->fresh()->badges));

        // a pill with icon and word on the menu, in every layout, and in the sheet data
        foreach (['auto', 'list', 'grid', 'cards', 'text'] as $layout) {
            $this->as($a, fn () => Settings::put(['menu_layout' => $layout]));
            $res = $this->get($this->subUrl('cafe-a'))->assertOk();
            $this->assertStringContainsString('data-item-badges', $res->getContent(), $layout);
            $res->assertSee('🥗 هيلثي')
                ->assertSee('"badges":[{"key":"healthy"', false);
        }
    }

    public function test_badge_filter_chips_are_off_by_default_and_show_only_badges_on_the_menu(): void
    {
        $a = $this->restaurant('cafe-a');
        $latte = $this->item($a, 'Latte', 60);
        $this->as($a, fn () => $latte->update(['badges' => ['healthy'], 'spicy_level' => 2]));

        $this->get($this->subUrl('cafe-a'))->assertOk()->assertDontSee('data-badge-chips', false)
            ->assertSee('data-badges="healthy spicy"', false);

        $owner = $this->owner($a);
        $this->actingAs($owner)->post($this->dashUrl('/settings'), self::SETTINGS + ['badge_filters_sent' => '1', 'menu_badge_filters' => ['healthy', 'vegan', 'iced', 'decaf', 'new']])
            ->assertSessionHasErrors('menu_badge_filters');
        $this->actingAs($owner)->post($this->dashUrl('/settings'), self::SETTINGS + ['badge_filters_sent' => '1', 'menu_badge_filters' => ['healthy', 'vegan']])
            ->assertSessionHasNoErrors();

        $this->get($this->subUrl('cafe-a'))->assertOk()->assertSee('data-badge-chips', false)
            ->assertSee('data-badge-filter="healthy"', false)->assertDontSee('data-badge-filter="vegan"', false);

        // nothing ticked: off again
        $this->actingAs($owner)->post($this->dashUrl('/settings'), self::SETTINGS + ['badge_filters_sent' => '1'])->assertSessionHasNoErrors();
        $this->get($this->subUrl('cafe-a'))->assertOk()->assertDontSee('data-badge-chips', false);
    }
}
