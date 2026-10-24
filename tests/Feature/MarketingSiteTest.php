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
        foreach (['kiosk', 'reservations', 'whatsapp', 'campaigns', 'wallet', 'languages', 'reports', 'featured-listing'] as $slug) {
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
        // the extra branch is still sold as an add-on: its page shows the database price
        AddonPrice::query()->where('key', 'branches')->update(['monthly_price' => 613]);
        $this->get($this->menuUrl('features/multi-branch'))->assertOk()->assertSee('+613');
        // an operations module is in Enterprise only: no price, a contact line
        $this->get($this->menuUrl('features/kiosk'))->assertOk()->assertDontSee('+'.money(AddonPrice::query()->where('key', 'kiosk_pack')->value('monthly_price')));

        AddonPrice::query()->where('key', 'wa_500')->update(['monthly_price' => 157]);
        $this->get($this->menuUrl('features/whatsapp'))->assertOk()
            ->assertSee('157')->assertSee('تدفع مرة واحدة')->assertSee('رصيد رسائل الواتساب');
    }

    public function test_pricing_groups_the_addons_with_credit_packs_paid_once(): void
    {
        $this->get($this->menuUrl('pricing'))->assertOk()
            ->assertSeeInOrder(['id="addons-growth"', 'id="addons-credits"'], false)
            ->assertDontSee('id="addons-operations"', false)
            ->assertSee('تدفع مرة واحدة')
            ->assertSee(route('features.show', 'multi-branch'), false);
    }

    public function test_unknown_module_is_404(): void
    {
        $this->get($this->menuUrl('features/no-such-module'))->assertNotFound();
    }

    public function test_module_prices_come_from_the_database(): void
    {
        // a plan feature: the page lists the public plans that include it, with their database prices
        Plan::query()->where('code', 'full')->update(['monthly_price' => 1312]);
        $this->get($this->menuUrl('features/loyalty?lang=en'))->assertOk()->assertSee('1,312')->assertSee('In Complete');
        // an operations module: Enterprise, by agreement, never a price
        $this->get($this->menuUrl('features/pos?lang=en'))->assertOk()->assertSee('In Enterprise, contact us');
    }

    public function test_pricing_calculator_uses_the_plan_and_online_pack_prices_from_the_database(): void
    {
        Plan::query()->where('code', 'full')->update(['monthly_price' => 1312]);

        $this->get($this->menuUrl('pricing'))->assertOk()
            ->assertSeeInOrder(['id="compare"', 'data-savings-calculator', 'id="addons"'], false)
            ->assertSee('"code":"full","monthly":1312,"yearly":'.json_encode(Plan::query()->where('code', 'full')->first()->priceFor(12)).',')
            // the online-ordering pack is off sale (Complete includes online ordering), and Enterprise has no price to work with
            ->assertSee('data-online-pack=""', false)
            ->assertDontSee('<option value="enterprise"', false)
            ->assertSee('<option value="full" selected', false)
            ->assertSee('محسوبة من الأرقام اللي كتبتها بس');
    }

    public function test_pricing_calculator_marks_which_plans_include_online_ordering(): void
    {
        $page = $this->get($this->menuUrl('pricing?lang=en'))->assertOk()->assertSee('Savings calculator');

        $this->assertMatchesRegularExpression('/data-plans="[^"]*&quot;code&quot;:&quot;free&quot;,&quot;monthly&quot;:0,&quot;yearly&quot;:0,&quot;online&quot;:false/', $page->getContent());
        $this->assertMatchesRegularExpression('/data-plans="[^"]*&quot;code&quot;:&quot;full&quot;,&quot;monthly&quot;:1250,&quot;yearly&quot;:12000,&quot;online&quot;:true/', $page->getContent());
    }

    public function test_home_shows_the_live_demo_in_arabic_and_english(): void
    {
        $this->get($this->menuUrl())->assertOk()
            ->assertSee('data-live-demo', false)
            ->assertSee('خلصان النهارده')
            ->assertSee(route('pricing').'#calculator', false);
        $this->get($this->menuUrl('?lang=en'))->assertOk()
            ->assertSee('data-live-demo', false)
            ->assertSee('Sold out today')
            ->assertSee('Iced Spanish latte');
    }

    public function test_pricing_page_puts_the_free_plan_first_and_compares_all_plans(): void
    {
        $this->get($this->menuUrl('pricing'))->assertOk()
            ->assertSeeInOrder(['data-plan="free"', 'data-plan="full"', 'data-plan="enterprise"'], false)
            ->assertSeeInOrder(['مجانية', 'الكاملة', 'Enterprise'])
            ->assertSee(['1,250', '12,000'])
            ->assertDontSee(['احترافية', 'بيزنس']);
    }
}
