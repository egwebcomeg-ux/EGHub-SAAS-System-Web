<?php

namespace Tests\Feature;

use App\Billing\Billing;
use App\Models\Category;
use App\Models\KitchenStation;
use App\Services\Settings;
use App\Services\TenantProvisioner;
use App\Support\MenuThemes;
use Tests\TenantTestCase;

class BusinessTypeTest extends TenantTestCase
{
    public function test_a_restaurant_starts_with_restaurant_categories_kitchen_and_template(): void
    {
        $tenant = app(TenantProvisioner::class)->create([
            'slug' => 'grill-house', 'name' => 'Grill House', 'owner_name' => 'O', 'email' => 'o@grill.test',
            'password' => 'Secret-pass-123', 'business_type' => 'restaurant',
        ]);

        $this->as($tenant, function () {
            $this->assertSame('restaurant', Settings::get('business_type'));
            $this->assertSame('bistro', Settings::get('menu_theme'));
            $this->assertTrue(Category::query()->where('name', 'أطباق رئيسية')->exists());
            $this->assertSame(['المطبخ'], KitchenStation::query()->pluck('name')->all());
        });

        app(Billing::class)->setAddon($tenant, 'kitchen_pack', 1);
        $this->actingAs($this->owner($tenant))->get($this->dashUrl('/kitchen'))->assertOk()->assertSee('شاشة المطبخ');
    }

    public function test_a_cafe_gets_a_bar_and_can_switch_type_from_settings(): void
    {
        $cafe = $this->restaurant('cafe-a'); // cafe by default
        app(Billing::class)->setAddon($cafe, 'kitchen_pack', 1);
        $owner = $this->owner($cafe);
        $this->actingAs($owner)->get($this->dashUrl('/kitchen'))->assertSee('شاشة البار');
        $this->actingAs($owner)->get($this->dashUrl('/'))->assertSee('مقترح لـكافيه');

        $this->actingAs($owner)->post($this->dashUrl('/settings'), [
            'brand_name' => 'X', 'currency' => 'ج.م', 'currency_en' => 'EGP', 'color_primary' => '#111111', 'color_secondary' => '#222222',
            'color_bg' => '#FFFFFF', 'font' => 'Cairo', 'default_locale' => 'ar', 'business_type' => 'both',
        ])->assertSessionHasNoErrors();
        $this->actingAs($owner)->get($this->dashUrl('/kitchen'))->assertSee('شاشة المطبخ والبار');
    }

    public function test_every_template_renders_the_menu(): void
    {
        $a = $this->restaurant('cafe-a', 'pro');
        $this->item($a, 'Latte');
        foreach (MenuThemes::keys() as $theme) {
            $this->as($a, fn () => Settings::put(['menu_theme' => $theme]));
            $this->get($this->subUrl('cafe-a'))->assertOk()->assertSee('Latte');
        }
    }
}
