<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\PlatformAdmin;
use App\Models\PlatformAuditLog;
use App\Models\Subscription;
use App\Support\SiteModules;
use Database\Seeders\PlanSeeder;
use Illuminate\Validation\ValidationException;
use Tests\TenantTestCase;

/** Archived plans (plans.archived_at): never offered or assignable, archived only once nobody is on them. */
class ArchivedPlansTest extends TenantTestCase
{
    private function admin(string $role = 'owner'): PlatformAdmin
    {
        return PlatformAdmin::query()->create(['name' => 'Admin', 'email' => "$role@egweb.test", 'password' => 'Secret-pass-123', 'role' => $role]);
    }

    private function plan(string $code): Plan
    {
        return Plan::query()->where('code', $code)->sole();
    }

    public function test_an_archived_plan_is_offered_nowhere_and_cannot_be_assigned(): void
    {
        // a public, self-serve plan, so every place that lists plans would show it
        $business = $this->plan('business');
        $business->update(['is_public' => true]);
        $tenant = $this->restaurant('cafe-a', 'full');
        $admin = $this->admin();

        $this->get($this->menuUrl('pricing'))->assertOk()->assertSee('بيزنس');
        $this->assertTrue(SiteModules::pricing()['plans']->contains('code', 'business'));

        $business->archive();

        $this->assertFalse($business->fresh()->is_public);
        $this->assertFalse(SiteModules::pricing()['plans']->contains('code', 'business')); // pricing, home, module picker
        $this->get($this->menuUrl('pricing'))->assertOk()->assertDontSee('بيزنس');
        $this->actingAs($this->owner($tenant))->get($this->dashUrl('/billing'))->assertOk()
            ->assertDontSee('name="plan_id" value="'.$business->id.'"', false);
        $this->actingAs($this->owner($tenant))->post($this->dashUrl('/billing/checkout'), ['plan_id' => $business->id, 'months' => 1])
            ->assertSessionHasErrors('plan_id');
        $this->actingAs($admin, 'admin')->get($this->adminUrl('/tenants/cafe-a'))->assertOk()
            ->assertDontSee('<option value="'.$business->id.'"', false)->assertSee('<option value="'.$this->plan('full')->id.'"', false);
        $this->actingAs($admin, 'admin')->get($this->adminUrl('/tenants/create'))->assertOk()
            ->assertDontSee('<option value="business"', false)->assertSee('<option value="full"', false);

        // the admin's plan change and a new restaurant on it are refused
        $this->actingAs($admin, 'admin')->post($this->adminUrl('/tenants/cafe-a/subscription'), [
            'plan_id' => $business->id, 'status' => 'active', 'cycle_months' => 1,
        ])->assertSessionHasErrors('plan_id');
        $this->assertSame($this->plan('full')->id, $tenant->subscription()->value('plan_id'));
        $this->expectException(ValidationException::class);
        $this->restaurant('cafe-b', 'business');
    }

    public function test_a_restaurant_already_on_a_plan_keeps_it_and_blocks_archiving_it(): void
    {
        $this->restaurant('cafe-a', 'pro');
        $admin = $this->admin();
        $pro = $this->plan('pro');

        $this->actingAs($admin, 'admin')->post($this->adminUrl("/plans/{$pro->id}/archive"))
            ->assertSessionHasErrors('plan');
        $this->assertStringContainsString('cafe-a', session('errors')->first('plan'));
        $this->assertNull($pro->fresh()->archived_at);

        // the essential plans can never be archived
        $this->actingAs($admin, 'admin')->post($this->adminUrl("/plans/{$this->plan('free')->id}/archive"))->assertSessionHasErrors('plan');
        $this->assertNull($this->plan('free')->archived_at);
    }

    public function test_admin_archives_and_unarchives_with_audit_and_the_list_hides_archived_by_default(): void
    {
        $admin = $this->admin();
        $starter = $this->plan('starter');

        $this->actingAs($admin, 'admin')->post($this->adminUrl("/plans/{$starter->id}/archive"))->assertSessionHas('ok');
        $this->assertNotNull($starter->fresh()->archived_at);
        $this->assertSame('plan.archived', PlatformAuditLog::query()->latest('id')->value('action'));

        $this->actingAs($admin, 'admin')->get($this->adminUrl('/plans'))->assertOk()
            ->assertDontSee('data-plan="starter"', false)->assertSee('data-plan="pro"', false)
            ->assertSee(__('Show archived (:count)', ['count' => 1]));
        $this->actingAs($admin, 'admin')->get($this->adminUrl('/plans?archived=1'))->assertOk()
            ->assertSee('data-plan="starter"', false)->assertSee('data-plan-archived', false)->assertSee(__('Hide archived plans'));

        $this->actingAs($admin, 'admin')->post($this->adminUrl("/plans/{$starter->id}/unarchive"))->assertSessionHas('ok');
        $this->assertNull($starter->fresh()->archived_at);
        $this->assertFalse($starter->fresh()->is_public);
        $this->assertSame('plan.unarchived', PlatformAuditLog::query()->latest('id')->value('action'));
    }

    public function test_only_plan_managers_can_archive(): void
    {
        $starter = $this->plan('starter');

        $this->actingAs($this->admin('support'), 'admin')->post($this->adminUrl("/plans/{$starter->id}/archive"))->assertForbidden();
        $this->assertNull($starter->fresh()->archived_at);
    }

    public function test_the_command_refuses_while_a_legacy_plan_is_in_use(): void
    {
        $this->restaurant('cafe-a', 'basic');

        $this->artisan('egmenu:archive-legacy-plans')->expectsOutputToContain('cafe-a')->assertFailed();

        $this->assertSame(0, Plan::query()->whereNotNull('archived_at')->count());
    }

    public function test_the_dry_run_changes_nothing_and_the_real_run_is_idempotent(): void
    {
        // a canceled subscription does not hold a plan back
        $this->restaurant('cafe-a', 'pro');
        Subscription::query()->where('tenant_id', 'cafe-a')->update(['status' => 'canceled']);

        $this->artisan('egmenu:archive-legacy-plans --dry-run')->expectsOutputToContain('would be archived')->assertSuccessful();
        $this->assertSame(0, Plan::query()->whereNotNull('archived_at')->count());

        $this->artisan('egmenu:archive-legacy-plans')->assertSuccessful();
        $this->assertEqualsCanonicalizing(PlanSeeder::LEGACY, Plan::query()->whereNotNull('archived_at')->pluck('code')->all());
        $this->assertSame(['free', 'full', 'enterprise'], Plan::query()->offered()->orderBy('sort_order')->pluck('code')->all());

        $this->artisan('egmenu:archive-legacy-plans')->expectsOutputToContain('already archived')->assertSuccessful();
    }

    public function test_the_seeder_keeps_archived_plans_archived(): void
    {
        $this->artisan('egmenu:archive-legacy-plans')->assertSuccessful();

        $this->seed(PlanSeeder::class);

        $this->assertSame(4, Plan::query()->whereIn('code', PlanSeeder::LEGACY)->whereNotNull('archived_at')->count());
        $this->assertSame(7, Plan::query()->count());
    }

    public function test_the_seeder_never_recreates_the_legacy_plans_in_production(): void
    {
        Plan::query()->whereIn('code', PlanSeeder::LEGACY)->each(fn (Plan $plan) => $plan->features()->delete());
        Plan::query()->whereIn('code', PlanSeeder::LEGACY)->delete();

        $this->app->detectEnvironment(fn () => 'production');
        try {
            app(PlanSeeder::class)->run(); // not $this->seed(): the production guard would ask for confirmation
        } finally {
            $this->app->detectEnvironment(fn () => 'testing');
        }

        $this->assertSame([], Plan::query()->whereIn('code', PlanSeeder::LEGACY)->pluck('code')->all());
        $this->assertEqualsCanonicalizing(['free', 'full', 'enterprise'], Plan::query()->pluck('code')->all());
    }
}
