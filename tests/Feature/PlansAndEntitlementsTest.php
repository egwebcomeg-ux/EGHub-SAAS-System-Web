<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\PlatformAdmin;
use App\Models\Subscription;
use App\Services\Entitlements;
use App\Services\TenantProvisioner;
use Illuminate\Support\Facades\Route;
use Tests\TenantTestCase;

class PlansAndEntitlementsTest extends TenantTestCase
{
    public function test_seeded_plans_prices_and_feature_split(): void
    {
        $this->assertSame([149.0, 399.0, 799.0], Plan::query()->orderBy('sort_order')->pluck('monthly_price')->all());

        $basic = Entitlements::for($this->restaurant('cafe-basic', 'basic'));
        $pro = Entitlements::for($this->restaurant('cafe-pro', 'pro'));
        $business = Entitlements::for($this->restaurant('cafe-biz', 'business'));

        $this->assertTrue($basic->allows('qr_ordering'));
        $this->assertTrue($basic->allows('excel_import'));
        $this->assertFalse($basic->allows('ai_import'));
        $this->assertFalse($basic->allows('pos'));

        $this->assertTrue($pro->allows('pos'));
        $this->assertTrue($pro->allows('kitchen_display'));
        $this->assertTrue($pro->allows('ai_import'));
        $this->assertTrue($pro->allows('loyalty_stamps'));
        $this->assertFalse($pro->allows('inventory'));
        $this->assertFalse($pro->allows('call_center'));

        $this->assertTrue($business->allows('inventory'));
        $this->assertTrue($business->allows('call_center'));
        $this->assertTrue($business->allows('loyalty_points'));
        $this->assertSame(3, $business->limit('branches'));
        $this->assertNull($business->limit('staff'));
    }

    public function test_new_restaurant_gets_a_14_day_pro_trial(): void
    {
        $tenant = app(TenantProvisioner::class)->create([
            'slug' => 'trial-cafe', 'name' => 'Trial', 'owner_name' => 'O', 'email' => 'o@trial.test', 'password' => 'Secret-pass-123',
        ]);
        $sub = $tenant->subscription;
        $this->assertSame('trialing', $sub->status);
        $this->assertSame('pro', $sub->plan->code);
        $this->assertSame(14, $sub->daysLeft());
    }

    public function test_staff_limit_is_enforced_server_side(): void
    {
        $a = $this->restaurant('cafe-a', 'basic'); // basic: 2 staff, the owner is one
        $owner = $this->owner($a);
        $add = fn (string $phone) => $this->actingAs($owner)->post($this->dashUrl('/users'), [
            'name' => 'W', 'phone' => $phone, 'role' => 'waiter', 'password' => 'Secret-pass-123',
        ]);

        $add('01033333333')->assertSessionHasNoErrors();
        $add('01044444444')->assertSessionHasErrors('limit');
        $this->assertSame(2, $this->as($a, fn () => \App\Models\User::query()->count()));
    }

    public function test_feature_middleware_blocks_routes_outside_the_plan(): void
    {
        Route::domain('app.egmenu.test')->middleware(['web', 'auth:web', 'tenant.user', 'feature:pos'])
            ->get('/_test/pos', fn () => 'pos-ok');

        $basic = $this->restaurant('cafe-basic', 'basic');
        $pro = $this->restaurant('cafe-pro', 'pro');

        $this->actingAs($this->owner($basic))->get($this->dashUrl('/_test/pos'))->assertForbidden();
        $this->actingAs($this->owner($pro))->get($this->dashUrl('/_test/pos'))->assertOk()->assertSee('pos-ok');
    }

    public function test_admin_edits_a_plan_and_rights_change(): void
    {
        $a = $this->restaurant('cafe-a', 'basic');
        $this->assertFalse(Entitlements::for($a)->allows('ai_import'));

        $basic = Plan::query()->where('code', 'basic')->first();
        $features = array_keys(array_filter($basic->load('features')->entitlements()['features']));
        $admin = PlatformAdmin::query()->create(['name' => 'Admin', 'email' => 'admin@egmenu.test', 'password' => 'Secret-pass-123']);

        $this->actingAs($admin, 'admin')->post($this->adminUrl("/plans/{$basic->id}"), [
            'name' => 'أساسية', 'monthly_price' => 129, 'features' => [...$features, 'ai_import'],
            'limits' => ['branches' => 1, 'menu_items' => 150, 'staff' => 2, 'tables' => 15, 'ai_imports_per_month' => 2],
            'unlimited' => [], 'is_public' => '1',
        ])->assertRedirect($this->adminUrl('/plans'));

        Entitlements::flush();
        $rights = Entitlements::for($a);
        $this->assertTrue($rights->allows('ai_import'));
        $this->assertSame(2, $rights->limit('ai_imports_per_month'));
        $this->assertSame(129.0, $basic->fresh()->monthly_price);
    }

    public function test_admin_creates_a_restaurant_and_activates_a_paid_plan(): void
    {
        $admin = PlatformAdmin::query()->create(['name' => 'Admin', 'email' => 'admin@egmenu.test', 'password' => 'Secret-pass-123']);

        $this->actingAs($admin, 'admin')->post($this->adminUrl('/tenants'), [
            'name' => 'Koshary Place', 'slug' => 'koshary', 'owner_name' => 'Ahmed', 'email' => 'ahmed@koshary.test',
            'phone' => '01055555555', 'password' => 'Secret-pass-123', 'plan' => 'basic',
        ])->assertRedirect($this->adminUrl('/tenants/koshary'));

        $business = Plan::query()->where('code', 'business')->first();
        $this->actingAs($admin, 'admin')->post($this->adminUrl('/tenants/koshary/subscription'), [
            'plan_id' => $business->id, 'status' => 'active', 'cycle_months' => 12, 'current_period_end' => now()->addYear()->toDateString(),
        ])->assertSessionHasNoErrors();

        $sub = Subscription::query()->where('tenant_id', 'koshary')->first();
        $this->assertSame('business', $sub->plan->code);
        $this->assertSame('active', $sub->status);

        $this->actingAs($admin, 'admin')->post($this->adminUrl('/tenants'), [
            'name' => 'Dup', 'slug' => 'koshary', 'owner_name' => 'X', 'email' => 'x@x.test', 'password' => 'Secret-pass-123', 'plan' => 'basic',
        ])->assertSessionHasErrors('slug');
    }
}
