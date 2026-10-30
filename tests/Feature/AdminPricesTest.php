<?php

namespace Tests\Feature;

use App\Billing\CustomPlan;
use App\Billing\PricingRules;
use App\Models\AddonPrice;
use App\Models\Branch;
use App\Models\Plan;
use App\Models\PlatformAdmin;
use Database\Seeders\PlanSeeder;
use Tests\TenantTestCase;

/** admin › Plans & add-ons › Prices: every plan and module price and the pricing rules, saved together. */
class AdminPricesTest extends TenantTestCase
{
    private function admin(string $role = 'owner'): PlatformAdmin
    {
        return PlatformAdmin::query()->create(['name' => 'Admin', 'email' => "$role@egweb.test", 'password' => 'Secret-pass-123', 'role' => $role]);
    }

    /** @return array<string, mixed> the form as the page sends it, with $changes on top */
    private function form(array $plans = [], array $addons = [], array $rules = []): array
    {
        return [
            'plans' => Plan::query()->offered()->get()->mapWithKeys(fn (Plan $p) => [$p->id => ['monthly_price' => $p->monthly_price, 'original_price' => $p->original_price]])->replace($plans)->all(),
            'addons' => AddonPrice::query()->get()->mapWithKeys(fn (AddonPrice $a) => [$a->id => array_filter(['monthly_price' => $a->monthly_price, 'is_active' => $a->is_active ? 1 : null])])->replace($addons)->all(),
            'rules' => $rules + ['custom_minimum' => 299, 'operations_markup_pct' => 25, 'extra_branch_off_pct' => 40],
        ];
    }

    public function test_the_page_lists_every_plan_module_and_rule(): void
    {
        $pack = AddonPrice::query()->where('key', 'pos_pack')->sole();
        $full = Plan::query()->where('code', 'full')->sole();

        $this->actingAs($this->admin(), 'admin')->get($this->adminUrl('/prices'))->assertOk()
            ->assertSee('name="plans['.$full->id.'][monthly_price]"', false)
            ->assertSee('name="addons['.$pack->id.'][monthly_price]"', false)
            ->assertSee('name="rules[custom_minimum]" type="number" step="1" min="0" value="299"', false);
    }

    public function test_saving_changes_plan_and_module_prices_and_the_rules_they_are_charged_with(): void
    {
        $full = Plan::query()->where('code', 'full')->sole();
        $pos = AddonPrice::query()->where('key', 'pos_pack')->sole();
        $kitchen = AddonPrice::query()->where('key', 'kitchen_pack')->sole();

        $this->actingAs($this->admin(), 'admin')->post($this->adminUrl('/prices'), $this->form(
            plans: [$full->id => ['monthly_price' => 1400, 'original_price' => 2800]],
            addons: [$pos->id => ['monthly_price' => 250, 'is_active' => 1], $kitchen->id => ['monthly_price' => 149]],
            rules: ['custom_minimum' => 350, 'operations_markup_pct' => 30, 'extra_branch_off_pct' => 50],
        ))->assertRedirect(route('admin.prices'))->assertSessionHas('ok');

        $this->assertSame([1400.0, 2800.0], [(float) $full->fresh()->monthly_price, (float) $full->fresh()->original_price]);
        $this->assertSame([250.0, true], [$pos->fresh()->monthly_price, $pos->fresh()->is_active]);
        $this->assertFalse($kitchen->fresh()->is_active); // unticked = off sale

        PricingRules::flush();
        $this->assertSame([350.0, 0.3, 0.5], [PricingRules::customMinimum(), PricingRules::operationsMarkup(), PricingRules::extraBranchShare()]);
        $custom = Plan::query()->where('code', CustomPlan::CODE)->sole();
        $this->assertSame(325.0, CustomPlan::priceOf($pos->fresh(), $custom)); // 250 + 30%

        $tenant = $this->restaurant('two-branches', 'full');
        $this->as($tenant, fn () => Branch::query()->create(['name' => 'Second', 'slug' => 'second', 'is_active' => true]));
        $this->assertSame(375.0, $pos->fresh()->priceFor($tenant)); // 250 + half of 250 for the second branch
    }

    public function test_only_admins_who_manage_plans_may_change_prices(): void
    {
        $full = Plan::query()->where('code', 'full')->sole();

        $sales = $this->admin('sales');
        $this->actingAs($sales, 'admin')->get($this->adminUrl('/prices'))->assertForbidden();
        $this->actingAs($sales, 'admin')->post($this->adminUrl('/prices'), $this->form(plans: [$full->id => ['monthly_price' => 1]]))->assertForbidden();
        $this->assertSame(1250.0, (float) $full->fresh()->monthly_price);
    }

    public function test_a_deploy_keeps_an_edited_extra_branch_and_staff_price(): void
    {
        AddonPrice::query()->where('key', 'branches')->update(['monthly_price' => 700]);
        AddonPrice::query()->where('key', 'staff')->update(['monthly_price' => 120]);

        $this->seed(PlanSeeder::class);

        $this->assertSame(700.0, (float) AddonPrice::query()->where('key', 'branches')->value('monthly_price'));
        $this->assertSame(120.0, (float) AddonPrice::query()->where('key', 'staff')->value('monthly_price'));
    }
}
