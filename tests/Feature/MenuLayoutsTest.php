<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\MenuItem;
use App\Models\Tenant;
use App\Services\Settings;
use App\Support\MenuThemes;
use Tests\TenantTestCase;

/** The item layout of the public menu apart from the theme colors: menu setting, per-category override and "auto". */
class MenuLayoutsTest extends TenantTestCase
{
    private const SETTINGS = ['brand_name' => 'X', 'currency' => 'ج.م', 'currency_en' => 'EGP', 'color_primary' => '#111111',
        'color_secondary' => '#222222', 'color_bg' => '#FFFFFF', 'font' => 'Cairo', 'default_locale' => 'ar'];

    /** Two categories without photos (drinks, mains) and one with a photo. */
    private function menu(Tenant $tenant): array
    {
        return $this->as($tenant, function () {
            $drinks = Category::query()->create(['name' => 'Drinks']);
            $mains = Category::query()->create(['name' => 'Mains']);
            $photos = Category::query()->create(['name' => 'Specials']);
            MenuItem::query()->create(['category_id' => $drinks->id, 'name' => 'Tea', 'description' => 'Mint tea', 'price' => 20]);
            MenuItem::query()->create(['category_id' => $mains->id, 'name' => 'Kofta', 'price' => 120]);
            MenuItem::query()->create(['category_id' => $photos->id, 'name' => 'Mixed grill', 'price' => 300, 'image' => 'tenants/x/items/grill.webp']);

            return [$drinks, $mains, $photos];
        });
    }

    /** @return array<int, string> category id => data-layout */
    private function layouts(string $slug): array
    {
        $html = $this->get($this->subUrl($slug))->assertOk()->getContent();
        preg_match_all('#<section id="cat-(\d+)".*?data-layout="([a-z]+)"#s', $html, $m);

        return array_map('strval', array_combine(array_map('intval', $m[1]), $m[2]));
    }

    public function test_auto_keeps_the_theme_layout_and_a_photo_less_category_reads_as_a_price_list_only_without_placeholders(): void
    {
        $a = $this->restaurant('cafe-a');
        [$drinks, $mains, $photos] = $this->menu($a);

        foreach (MenuThemes::keys() as $theme) {
            $this->as($a, fn () => Settings::put(['menu_theme' => $theme]));
            $this->assertSame(array_fill_keys([$drinks->id, $mains->id, $photos->id], MenuThemes::layout($theme)), $this->layouts('cafe-a'), $theme);
        }

        // placeholders off: the categories without a single photo become a price list, the one with photos stays
        $this->as($a, fn () => Settings::put(['menu_theme' => 'bistro', 'item_placeholder' => '0']));
        $this->assertSame([$drinks->id => 'text', $mains->id => 'text', $photos->id => 'bistro'], $this->layouts('cafe-a'));
    }

    public function test_the_menu_setting_and_the_category_override(): void
    {
        $a = $this->restaurant('cafe-a');
        [$drinks, $mains, $photos] = $this->menu($a);
        $this->as($a, function () use ($drinks) {
            Settings::put(['menu_theme' => 'bistro', 'menu_layout' => 'cards']);
            $drinks->update(['menu_layout' => 'text']);
        });

        $this->assertSame([$drinks->id => 'text', $mains->id => 'cards', $photos->id => 'cards'], $this->layouts('cafe-a'));

        // the price list never shows an image or a placeholder, every palette keeps its tokens
        $this->as($a, fn () => Settings::put(['menu_layout' => 'text']));
        $html = $this->get($this->subUrl('cafe-a'))->assertOk()->assertSee('Mint tea')->getContent();
        $this->assertStringNotContainsString('data-placeholder=', $html);
        $this->assertStringNotContainsString('<img src="/storage/tenants/x/items/grill.webp"', $html);

        // the override wins over any menu setting
        $this->as($a, fn () => Settings::put(['menu_layout' => 'grid']));
        $this->assertSame([$drinks->id => 'text', $mains->id => 'grid', $photos->id => 'grid'], $this->layouts('cafe-a'));
    }

    public function test_choosing_a_layout_needs_the_design_feature(): void
    {
        $b = $this->restaurant('cafe-b', 'basic');
        [$drinks, $mains] = $this->menu($b);
        $this->as($b, function () use ($drinks) {
            Settings::put(['menu_layout' => 'grid']);
            $drinks->update(['menu_layout' => 'cards']);
        });

        $layouts = $this->layouts('cafe-b');
        $this->assertSame('classic', $layouts[$drinks->id]);
        $this->assertSame('classic', $layouts[$mains->id]);

        $this->actingAs($this->owner($b))->post($this->dashUrl('/settings'), self::SETTINGS + ['menu_layout' => 'text'])->assertSessionHasNoErrors();
        $this->assertSame('grid', $this->as($b, fn () => Settings::get('menu_layout')));
    }

    public function test_the_owner_saves_the_layout_and_a_category_override(): void
    {
        $a = $this->restaurant('cafe-a');
        [$drinks] = $this->menu($a);
        $owner = $this->owner($a);

        $this->actingAs($owner)->get($this->dashUrl('/settings'))->assertOk()->assertSee('name="menu_layout" value="text"', false);
        $this->actingAs($owner)->post($this->dashUrl('/settings'), self::SETTINGS + ['menu_layout' => 'square'])->assertSessionHasErrors('menu_layout');
        $this->actingAs($owner)->post($this->dashUrl('/settings'), self::SETTINGS + ['menu_layout' => 'text'])->assertSessionHasNoErrors();
        $this->assertSame('text', $this->as($a, fn () => Settings::get('menu_layout')));
        // a form without the field keeps it
        $this->actingAs($owner)->post($this->dashUrl('/settings'), self::SETTINGS)->assertSessionHasNoErrors();
        $this->assertSame('text', $this->as($a, fn () => Settings::get('menu_layout')));

        $this->actingAs($owner)->get($this->dashUrl('/menu'))->assertOk()->assertSee('data-category-layout', false);
        $this->actingAs($owner)->post($this->dashUrl("/menu/categories/{$drinks->id}"), ['name' => 'Drinks', 'menu_layout' => 'grid', 'is_active' => '1'])->assertSessionHasNoErrors();
        $this->assertSame('grid', $this->as($a, fn () => $drinks->fresh()->menu_layout));
        $this->actingAs($owner)->post($this->dashUrl("/menu/categories/{$drinks->id}"), ['name' => 'Drinks', 'menu_layout' => '', 'is_active' => '1'])->assertSessionHasNoErrors();
        $this->assertNull($this->as($a, fn () => $drinks->fresh()->menu_layout));
        $this->actingAs($owner)->post($this->dashUrl("/menu/categories/{$drinks->id}"), ['name' => 'Drinks', 'menu_layout' => 'auto'])->assertSessionHasErrors('menu_layout');
    }
}
