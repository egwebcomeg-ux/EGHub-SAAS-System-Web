<?php

namespace Tests\Feature;

use App\Models\PlatformAdmin;
use App\Models\Tenant;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/** Roles of the platform admin team: each route checks its permission (403) and the sidebar hides what is not allowed. */
class AdminPermissionsTest extends TenantTestCase
{
    private function admin(string $role, array $extra = []): PlatformAdmin
    {
        return PlatformAdmin::query()->create([
            'name' => ucfirst($role), 'email' => "$role@egweb.test", 'password' => 'Secret-pass-123',
            'role' => $role, 'permissions' => $extra ?: null,
        ]);
    }

    public function test_existing_admins_are_owners_and_see_every_section(): void
    {
        $owner = PlatformAdmin::query()->create(['name' => 'Old', 'email' => 'old@egweb.test', 'password' => 'Secret-pass-123']);
        $this->assertSame('owner', $owner->fresh()->role);

        $page = $this->actingAs($owner, 'admin')->get($this->adminUrl('/'))->assertOk();
        foreach (['admin.tenants', 'admin.invoices', 'admin.plans', 'admin.services', 'admin.blog', 'admin.settings', 'admin.branding', 'admin.admins', 'admin.security'] as $route) {
            $page->assertSee('data-nav="'.$route.'"', false);
        }
        foreach (['/tenants', '/invoices', '/plans', '/addons', '/settings', '/branding', '/team', '/services', '/blog', '/testimonials', '/assistant'] as $path) {
            $this->actingAs($owner, 'admin')->get($this->adminUrl($path))->assertOk();
        }
    }

    public function test_support_handles_restaurants_but_not_money_settings_or_the_team(): void
    {
        $support = $this->admin('support');
        $this->restaurant('cafe-a');

        $page = $this->actingAs($support, 'admin')->get($this->adminUrl('/'))->assertOk()
            ->assertSee('data-nav="admin.tenants"', false)->assertSee('data-nav="admin.services"', false)
            ->assertDontSee('data-nav="admin.plans"', false)->assertDontSee('data-nav="admin.invoices"', false)
            ->assertDontSee('data-nav="admin.settings"', false)->assertDontSee('data-nav="admin.admins"', false);
        $this->assertStringNotContainsString(__('Unpaid invoices'), $page->getContent());

        $this->actingAs($support, 'admin')->get($this->adminUrl('/tenants/cafe-a'))->assertOk()
            ->assertSee(__('Set temporary password'))->assertDontSee(__('Save subscription'));
        foreach (['/plans', '/addons', '/invoices', '/settings', '/branding', '/team', '/blog'] as $path) {
            $this->actingAs($support, 'admin')->get($this->adminUrl($path))->assertForbidden();
        }
        $this->actingAs($support, 'admin')->post($this->adminUrl('/tenants/cafe-a/subscription'), [])->assertForbidden();
        $this->actingAs($support, 'admin')->post($this->adminUrl('/tenants/cafe-a/suspend'))->assertRedirect();
        $this->assertTrue(Tenant::query()->find('cafe-a')->isSuspended());
    }

    public function test_finance_sees_restaurants_read_only_and_manages_invoices(): void
    {
        $finance = $this->admin('finance');
        $this->restaurant('cafe-a');

        $this->actingAs($finance, 'admin')->get($this->adminUrl('/invoices'))->assertOk();
        $this->actingAs($finance, 'admin')->get($this->adminUrl('/tenants/cafe-a'))->assertOk()
            ->assertSee(__('Save subscription'))->assertDontSee(__('Set temporary password'))->assertDontSee(__('Suspend'));
        $this->actingAs($finance, 'admin')->post($this->adminUrl('/tenants/cafe-a/suspend'))->assertForbidden();
        $this->actingAs($finance, 'admin')->get($this->adminUrl('/tenants/create'))->assertForbidden();
        $this->actingAs($finance, 'admin')->get($this->adminUrl('/plans'))->assertForbidden();
    }

    public function test_content_only_reaches_blog_testimonials_and_assistant(): void
    {
        $content = $this->admin('content');

        $this->actingAs($content, 'admin')->get($this->adminUrl('/'))->assertOk()
            ->assertSee('data-nav="admin.blog"', false)->assertDontSee('data-nav="admin.tenants"', false);
        foreach (['/blog', '/testimonials', '/assistant', '/security'] as $path) {
            $this->actingAs($content, 'admin')->get($this->adminUrl($path))->assertOk();
        }
        foreach (['/tenants', '/invoices', '/services', '/team'] as $path) {
            $this->actingAs($content, 'admin')->get($this->adminUrl($path))->assertForbidden();
        }
    }

    public function test_extra_permissions_add_to_the_role(): void
    {
        $content = $this->admin('content', ['services.manage']);

        $this->actingAs($content, 'admin')->get($this->adminUrl('/services'))->assertOk();
        $this->actingAs($content, 'admin')->get($this->adminUrl('/'))->assertSee('data-nav="admin.services"', false);
    }

    public function test_a_switched_off_admin_is_signed_out_and_cannot_sign_in(): void
    {
        $admin = $this->admin('manager');
        $admin->update(['is_active' => false]);

        $this->actingAs($admin, 'admin')->get($this->adminUrl('/'))->assertRedirect(route('admin.login'));
        $this->assertGuest('admin');
        $this->post($this->adminUrl('/login'), ['email' => 'manager@egweb.test', 'password' => 'Secret-pass-123'])->assertSessionHasErrors('email');
        $this->assertGuest('admin');
    }

    public function test_platform_roles_never_touch_the_restaurant_roles(): void
    {
        $this->admin('manager');
        $owner = $this->owner($this->restaurant('cafe-a'));

        // restaurant roles stay the spatie "web" roles; platform roles live on platform_admins only
        $this->assertSame(['web'], Role::query()->distinct()->pluck('guard_name')->all());
        $this->assertSame('owner', $owner->roleKey());
        $this->actingAs($owner)->get($this->adminUrl('/'))->assertRedirect(route('admin.login'));
    }
}
