<?php

namespace Tests\Feature;

use App\Models\AddonPrice;
use App\Models\PlatformAdmin;
use Tests\TenantTestCase;

/** "الباقات والإضافات": plan cards + comparison, the add-ons list, one add-on per edit page, the on-sale switch. */
class AdminPlansPagesTest extends TenantTestCase
{
    private function admin(): PlatformAdmin
    {
        return PlatformAdmin::query()->create(['name' => 'Admin', 'email' => 'admin@egweb.test', 'password' => 'Secret-pass-123']);
    }

    public function test_plans_tab_shows_one_card_per_plan_and_the_comparison(): void
    {
        $this->restaurant('cafe-a', 'pro');

        $this->actingAs($this->admin(), 'admin')->get($this->adminUrl('/plans'))->assertOk()
            ->assertSee('data-plan="pro"', false)->assertSee('data-plan="basic"', false)
            ->assertSee(__('Compare all features'))->assertSee('data-compare-group', false)
            ->assertSee(__(':count restaurants', ['count' => 1]))
            ->assertDontSee('name="addons[', false); // the add-on wall is gone from this page
    }

    public function test_addons_tab_lists_them_and_each_is_saved_on_its_own_page(): void
    {
        $admin = $this->admin();
        $pack = AddonPrice::query()->where('key', 'pos_pack')->sole();

        $this->actingAs($admin, 'admin')->get($this->adminUrl('/addons'))->assertOk()
            ->assertSee($pack->name)->assertSee('data-addon-row', false)->assertSee('data-addon-search', false);
        $this->actingAs($admin, 'admin')->get($this->adminUrl("/addons/{$pack->id}/edit"))->assertOk()
            ->assertSee('name="addons['.$pack->id.'][features][]"', false)->assertSee('data-feature-filter', false);

        $this->actingAs($admin, 'admin')->post($this->adminUrl('/addons'), ['return' => 'addons', 'addons' => [$pack->id => [
            'name' => 'باقة الكاشير', 'name_en' => 'POS', 'monthly_price' => 222, 'description' => '', 'min_plan' => '',
            'is_active' => 1, 'per_branch' => 1, 'features' => ['pos', 'shifts'],
        ]]])->assertRedirect(route('admin.addons'));

        $pack->refresh();
        $this->assertSame([222.0, ['pos', 'shifts'], true], [$pack->monthly_price, $pack->features, $pack->per_branch]);
        // the other add-ons were not touched
        $this->assertSame(149.0, AddonPrice::query()->where('key', 'kitchen_pack')->value('monthly_price') + 0.0);
    }

    public function test_on_sale_switch_flips_only_that_add_on(): void
    {
        // feature packs are off sale since the Complete / Enterprise line-up; the extra branch is still sold
        $pack = AddonPrice::query()->where('key', 'branches')->sole();
        $features = $pack->features;
        $this->assertTrue($pack->is_active);

        $this->actingAs($this->admin(), 'admin')->post($this->adminUrl("/addons/{$pack->id}/toggle"))->assertSessionHas('ok');

        $pack->refresh();
        $this->assertFalse($pack->is_active);
        $this->assertSame($features, $pack->features);
    }

    public function test_plan_edit_page_groups_the_features(): void
    {
        $this->actingAs($this->admin(), 'admin')->get($this->adminUrl('/plans/1'))->assertOk()
            ->assertSee('data-feature-picker', false)->assertSee('data-feature-group', false)->assertSee(__('Save plan'));
    }
}
