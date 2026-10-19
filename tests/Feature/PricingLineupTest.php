<?php

namespace Tests\Feature;

use App\Billing\Billing;
use App\Models\AddonPrice;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\PlatformAdmin;
use App\Models\Subscription;
use App\Services\Entitlements;
use Database\Seeders\PlanSeeder;
use Tests\TenantTestCase;

/** The public line-up: Free, Complete and Enterprise (contact sales); the older plans hidden but kept for their subscribers. */
class PricingLineupTest extends TenantTestCase
{
    public function test_the_public_line_up_is_free_complete_and_enterprise(): void
    {
        $this->assertSame(['free', 'full', 'enterprise'], Plan::query()->where('is_public', true)->orderBy('sort_order')->pluck('code')->all());

        $complete = Plan::query()->where('code', 'full')->firstOrFail();
        $this->assertSame(1250.0, $complete->monthly_price);
        $this->assertSame(12000.0, $complete->priceFor(12));
        $this->assertSame(15000.0, $complete->priceFor(1) * 12);
        $this->assertTrue($complete->is_featured);
        $this->assertTrue(Plan::query()->where('code', 'enterprise')->value('contact_sales'));
    }

    public function test_complete_has_every_module_but_operations_and_enterprise_has_everything(): void
    {
        $complete = Entitlements::for($this->restaurant('cafe-full', 'full'));
        foreach (['pos', 'shifts', 'customer_display', 'kitchen_display', 'kitchen_stations', 'kiosk', 'call_center', 'inventory', 'branch_pricing'] as $feature) {
            $this->assertFalse($complete->allows($feature), "$feature should not be in Complete");
        }
        foreach (['qr_ordering', 'online_ordering', 'delivery_zones', 'scheduled_orders', 'reservations', 'extra_languages', 'printed_menu', 'tv_screens',
            'ai_import', 'loyalty_stamps', 'loyalty_points', 'offers', 'whatsapp_notifications', 'campaigns', 'wallet', 'cashback', 'prepaid_packages',
            'membership', 'daily_reports', 'advanced_reports', 'custom_domain', 'staff_roles', 'audit_log', 'hide_badge', 'priority_support'] as $feature) {
            $this->assertTrue($complete->allows($feature), "$feature should be in Complete");
        }
        $this->assertSame([1, null, 8, null, 20], [$complete->limit('branches'), $complete->limit('menu_items'), $complete->limit('staff'), $complete->limit('tables'), $complete->limit('ai_imports_per_month')]);

        $enterprise = Entitlements::for($this->restaurant('chain', 'enterprise'));
        foreach (array_keys(Plan::FEATURES) as $feature) {
            $this->assertTrue($enterprise->allows($feature), "$feature should be in Enterprise");
        }
        foreach (array_keys(Plan::LIMITS) as $limit) {
            $this->assertNull($enterprise->limit($limit), "$limit should be unlimited in Enterprise");
        }
    }

    public function test_older_plans_are_hidden_but_their_subscribers_keep_them_and_can_renew(): void
    {
        $pro = $this->restaurant('cafe-pro', 'pro');
        $rights = Entitlements::for($pro);
        $this->assertTrue($rights->allows('loyalty_stamps'));
        $this->assertSame(50, $rights->limit('tables'));

        $this->get($this->menuUrl('pricing'))->assertOk()->assertDontSee('data-plan="pro"', false)->assertDontSee('data-plan="business"', false);

        $owner = $this->owner($pro);
        $this->actingAs($owner)->get($this->dashUrl('/billing'))->assertOk()
            ->assertSee('data-billing-plan="pro"', false)->assertSee('data-legacy-plan', false)->assertDontSee('data-billing-plan="business"', false);

        $basic = Plan::query()->where('code', 'basic')->value('id');
        $this->actingAs($owner)->post($this->dashUrl('/billing/checkout'), ['plan_id' => $basic, 'months' => 1])->assertSessionHasErrors('plan_id');
        $proId = Plan::query()->where('code', 'pro')->value('id');
        $this->actingAs($owner)->post($this->dashUrl('/billing/checkout'), ['plan_id' => $proId, 'months' => 1])->assertSessionHasNoErrors();
        $this->assertSame(1, Invoice::query()->where('tenant_id', 'cafe-pro')->where('plan_id', $proId)->count());
    }

    public function test_enterprise_is_contact_only_everywhere_and_never_invoiced_from_the_dashboard(): void
    {
        $enterprise = Plan::query()->where('code', 'enterprise')->firstOrFail();

        $this->get($this->menuUrl('pricing'))->assertOk()
            ->assertSee('data-plan="enterprise" data-contact-sales', false)->assertSee('data-contact-sales-link', false)->assertSee('data-enterprise-includes', false);
        $this->get($this->menuUrl('/'))->assertOk()->assertSee('data-home-plan="enterprise"', false)->assertSee('data-contact-sales-link', false);

        $tenant = $this->restaurant('cafe-free', 'free');
        $owner = $this->owner($tenant);
        $html = $this->actingAs($owner)->get($this->dashUrl('/billing'))->assertOk()
            ->assertSee('data-billing-plan="enterprise" data-contact-sales', false)->getContent();
        $this->assertStringNotContainsString('name="plan_id" value="'.$enterprise->id.'"', $html, 'no subscribe form for Enterprise');

        $this->actingAs($owner)->post($this->dashUrl('/billing/checkout'), ['plan_id' => $enterprise->id, 'months' => 12])->assertSessionHasErrors('plan_id');
        $this->assertSame(0, Invoice::query()->where('tenant_id', 'cafe-free')->count());

        $this->expectException(\DomainException::class);
        app(Billing::class)->invoiceForPlan($tenant, $enterprise, 1);
    }

    public function test_the_super_admin_assigns_enterprise_with_any_period(): void
    {
        $this->restaurant('chain', 'free');
        $admin = PlatformAdmin::query()->create(['name' => 'Admin', 'email' => 'admin@egmenu.test', 'password' => 'Secret-pass-123']);
        $enterprise = Plan::query()->where('code', 'enterprise')->firstOrFail();

        $this->actingAs($admin, 'admin')->get($this->adminUrl('/plans'))->assertOk()->assertSee('data-plan-contact', false);
        $this->actingAs($admin, 'admin')->post($this->adminUrl('/tenants/chain/subscription'), [
            'plan_id' => $enterprise->id, 'status' => 'active', 'cycle_months' => 12, 'current_period_end' => now()->addMonths(18)->toDateString(),
        ])->assertSessionHasNoErrors();

        $sub = Subscription::query()->where('tenant_id', 'chain')->firstOrFail();
        $this->assertSame('enterprise', $sub->plan->code);
        $this->assertTrue(Entitlements::for($sub->tenant)->allows('pos'));
    }

    public function test_feature_packs_are_off_sale_and_holders_keep_them(): void
    {
        $this->assertSame(['featured_listing'], AddonPrice::query()->where('type', 'feature')->where('is_active', true)->pluck('key')->all());
        $this->assertSame(['branches', 'staff', 'menu_items', 'tables', 'wa_500', 'wa_2000'], AddonPrice::query()->where('type', '!=', 'feature')->where('is_active', true)->orderBy('sort_order')->pluck('key')->all());

        $tenant = $this->restaurant('cafe-free', 'free');
        $owner = $this->owner($tenant);
        $this->actingAs($owner)->get($this->dashUrl('/billing'))->assertOk()->assertDontSee(route('dash.billing.addon', 'loyalty_pack'), false);
        $this->actingAs($owner)->post($this->dashUrl('/billing/addons/loyalty_pack'))->assertNotFound();
        $this->get($this->menuUrl('pricing'))->assertOk()->assertDontSee('+'.money(AddonPrice::query()->where('key', 'ops_bundle')->value('monthly_price')), false);

        // a restaurant that bought a pack before keeps it, and sees it as active
        app(Billing::class)->setAddon($tenant, 'loyalty_pack', 1);
        $this->assertTrue(Entitlements::for($tenant->fresh())->allows('loyalty_stamps'));
        $this->actingAs($owner)->get($this->dashUrl('/billing'))->assertOk()->assertSee(__('No longer sold'));
    }

    public function test_the_seeder_runs_again_without_touching_plans_subscriptions_or_edits(): void
    {
        $this->restaurant('cafe-pro', 'pro');
        Plan::query()->where('code', 'full')->update(['monthly_price' => 1300]);
        $plans = Plan::query()->count();

        $this->seed(PlanSeeder::class);

        $this->assertSame($plans, Plan::query()->count());
        $this->assertSame(1300.0, Plan::query()->where('code', 'full')->value('monthly_price') + 0.0);
        $this->assertSame('pro', Subscription::query()->where('tenant_id', 'cafe-pro')->first()->plan->code);
        $this->assertFalse((bool) Plan::query()->where('code', 'pro')->value('is_public'));
        $this->assertFalse((bool) AddonPrice::query()->where('key', 'pos_pack')->value('is_active'));
    }

    public function test_a_launch_discount_shows_the_regular_price_crossed_out_with_its_share(): void
    {
        $this->get($this->menuUrl('pricing'))->assertOk()->assertDontSee('data-orig-pct', false);

        Plan::query()->where('code', 'full')->update(['original_price' => 2500]);

        $this->get($this->menuUrl('pricing'))->assertOk()
            ->assertSee('data-orig-month="2500"', false)->assertSee('خصم 50٪ لفترة الإطلاق');
        $this->get($this->menuUrl('/'))->assertOk()->assertSee('2,500')->assertSee('خصم 50٪ لفترة الإطلاق');
    }
}
