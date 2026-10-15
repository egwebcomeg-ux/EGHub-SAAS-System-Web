<?php

namespace Tests\Feature;

use App\Models\AddonPrice;
use App\Models\Plan;
use App\Support\SiteModules;
use Tests\TenantTestCase;

/** The marketing site: module pages, the features index and the pricing page, with prices from the database. */
class MarketingSiteTest extends TenantTestCase
{
    public function test_every_module_page_renders_in_arabic_and_english(): void
    {
        foreach (array_keys(SiteModules::all()) as $slug) {
            $this->get($this->menuUrl("features/$slug"))->assertOk()->assertSee('data-scene', false);
            $this->get($this->menuUrl("features/$slug?lang=en"))->assertOk()->assertSee('lang="en"', false);
        }
    }

    public function test_features_index_lists_every_module(): void
    {
        $page = $this->get($this->menuUrl('features'))->assertOk();
        foreach (array_keys(SiteModules::all()) as $slug) {
            $page->assertSee(route('features.show', $slug), false);
        }
    }

    public function test_the_newer_modules_have_pages_and_every_screenshot_exists(): void
    {
        $modules = SiteModules::all();
        foreach (['kiosk', 'reservations', 'whatsapp', 'campaigns', 'wallet', 'languages', 'advanced-reports', 'featured-listing'] as $slug) {
            $this->assertArrayHasKey($slug, $modules);
        }
        foreach ($modules as $slug => $m) {
            $shots = array_merge([$m['shot']], $m['phone'] ? [$m['phone']] : [], array_column($m['gallery'] ?? [], 0));
            foreach ($shots as $shot) {
                $this->assertFileExists(public_path("img/site/$shot.webp"), "$slug: $shot");
                $this->assertFileExists(public_path("img/site/$shot-sm.webp"), "$slug: $shot-sm");
            }
            $this->assertTrue(view()->exists("landing.scenes.$slug"), "$slug has no scene");
            $this->assertContains($m['group'], array_keys(SiteModules::groups()));
        }
    }

    public function test_addon_module_pages_show_database_prices_and_credit_packs_as_paid_once(): void
    {
        AddonPrice::query()->where('key', 'kiosk_pack')->update(['monthly_price' => 163]);
        $this->get($this->menuUrl('features/kiosk'))->assertOk()->assertSee('+163');

        AddonPrice::query()->where('key', 'wa_500')->update(['monthly_price' => 157]);
        $this->get($this->menuUrl('features/whatsapp'))->assertOk()
            ->assertSee('157')->assertSee('تدفع مرة واحدة')->assertSee('رصيد رسائل الواتساب');
    }

    public function test_pricing_groups_the_addons_with_credit_packs_paid_once(): void
    {
        $this->get($this->menuUrl('pricing'))->assertOk()
            ->assertSeeInOrder(['id="addons-operations"', 'id="addons-growth"', 'id="addons-credits"'], false)
            ->assertSee('تدفع مرة واحدة')
            ->assertSee(route('features.show', 'kiosk'), false);
    }

    public function test_unknown_module_is_404(): void
    {
        $this->get($this->menuUrl('features/no-such-module'))->assertNotFound();
    }

    public function test_module_prices_come_from_the_database(): void
    {
        AddonPrice::query()->where('key', 'pos_pack')->update(['monthly_price' => 237]);
        $this->get($this->menuUrl('features/pos'))->assertOk()->assertSee('+237');

        // a plan feature: the page lists the plans that include it, with their database prices
        Plan::query()->where('code', 'pro')->update(['monthly_price' => 412]);
        $this->get($this->menuUrl('features/loyalty?lang=en'))->assertOk()->assertSee('412')->assertSee('In Pro');
    }

    public function test_pricing_page_puts_the_free_plan_first_and_compares_all_plans(): void
    {
        $this->get($this->menuUrl('pricing'))->assertOk()
            ->assertSeeInOrder(['مجاني', 'مبتدئ', 'أساسية', 'احترافية', 'بيزنس'])
            ->assertSee('ابدأ مجاناً — من غير بطاقة ولا مدة')
            ->assertSee(['99', '199', '399', '799']);
    }
}
