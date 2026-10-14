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
