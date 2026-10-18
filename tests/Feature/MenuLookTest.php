<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\DeliveryZone;
use App\Models\MenuItem;
use App\Services\Settings;
use App\Support\ItemPlaceholder;
use App\Support\MenuThemes;
use Tests\TenantTestCase;

/** Photo placeholders, the logo tile, the order-type chip and the delivery area step of the public menu. */
class MenuLookTest extends TenantTestCase
{
    public function test_items_without_a_photo_get_a_themed_placeholder_on_every_layout(): void
    {
        $a = $this->restaurant('cafe-a');
        $this->as($a, function () {
            $drinks = Category::query()->create(['name' => 'مشروبات ساخنة']);
            $desserts = Category::query()->create(['name' => 'Desserts']);
            MenuItem::query()->create(['category_id' => $drinks->id, 'name' => 'Tea', 'price' => 20]);
            MenuItem::query()->create(['category_id' => $desserts->id, 'name' => 'Cake', 'price' => 50]);
        });

        foreach (MenuThemes::keys() as $theme) {
            $this->as($a, fn () => Settings::put(['menu_theme' => $theme]));
            $this->get($this->subUrl('cafe-a'))->assertOk()
                ->assertSee('data-placeholder="cup"', false)->assertSee('data-placeholder="dessert"', false);
        }

        $this->as($a, fn () => Settings::put(['item_placeholder' => '0', 'menu_theme' => 'bistro']));
        $this->get($this->subUrl('cafe-a'))->assertOk()->assertDontSee('data-placeholder=', false);

        $this->assertSame('pizza', ItemPlaceholder::icon('بيتزا إيطالي', 'restaurant'));
        $this->assertSame('plate', ItemPlaceholder::icon('Main dishes', 'restaurant'));
        $this->assertSame('cup', ItemPlaceholder::icon('Specials', 'cafe'));
    }

    public function test_the_logo_tile_follows_the_theme_and_the_setting(): void
    {
        $a = $this->restaurant('cafe-a');
        $this->item($a);
        $this->as($a, fn () => Settings::put(['logo' => 'tenants/cafe-a/brand/logo.webp', 'menu_theme' => 'bistro']));
        $this->get($this->subUrl('cafe-a'))->assertOk()->assertSee('data-logo-tile="none"', false)->assertSee('m-intro-logo--bare', false);

        $this->as($a, fn () => Settings::put(['menu_theme' => 'classic']));
        $this->get($this->subUrl('cafe-a'))->assertOk()->assertSee('data-logo-tile="white"', false);

        $this->as($a, fn () => Settings::put(['logo_background' => 'none']));
        $this->get($this->subUrl('cafe-a'))->assertOk()->assertSee('data-logo-tile="none"', false);

        $this->actingAs($this->owner($a))->get($this->dashUrl('/settings'))->assertOk()->assertSee('name="logo_background"', false);
    }

    public function test_the_order_type_chip_and_the_delivery_area_step(): void
    {
        $a = $this->restaurant('cafe-a');
        $this->item($a);
        $this->as($a, function () {
            Settings::put(['online_delivery' => '1', 'whatsapp' => '01012345678']);
            $branch = Branch::query()->first();
            $maadi = DeliveryZone::query()->create(['branch_id' => $branch->id, 'name' => 'Maadi', 'fee' => 25, 'min_order' => 100, 'estimated_minutes' => 35]);
            DeliveryZone::query()->create(['branch_id' => $branch->id, 'parent_id' => $maadi->id, 'name' => 'Road 9']);
            DeliveryZone::query()->create(['branch_id' => $branch->id, 'name' => 'Zamalek', 'fee' => 40]);
        });

        $res = $this->get($this->subUrl('cafe-a'))->assertOk()
            ->assertSee('data-way-chip', false)->assertSee('data-zone-sheet', false)
            ->assertSee('data-zone-open=', false)->assertSee('Road 9')
            ->assertSee('data-zone-whatsapp', false)->assertSee('wa.me/201012345678', false)
            ->assertDontSee('data-zone-detect', false); // manual areas: no "detect my area"
        $this->assertStringContainsString('توصيل 25', $res->getContent());

        // drawn areas in map mode: the guest can detect the area
        $this->as($a, function () {
            Settings::put(['delivery_zone_mode' => 'map']);
            DeliveryZone::query()->where('name', 'Zamalek')->update(['polygon' => json_encode([[30.05, 31.21], [30.07, 31.21], [30.07, 31.23], [30.05, 31.23]])]);
        });
        $this->get($this->subUrl('cafe-a'))->assertOk()->assertSee('data-zone-detect', false)
            ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(self)');
    }
}
