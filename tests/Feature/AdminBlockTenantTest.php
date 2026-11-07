<?php

namespace Tests\Feature;

use App\Models\BlockedSignup;
use App\Models\PlatformAdmin;
use App\Models\Tenant;
use Tests\TenantTestCase;

/** Blocking a copycat from the admin: menu and dashboard closed, the owner's email and mobile refused at sign-up. */
class AdminBlockTenantTest extends TenantTestCase
{
    private function admin(string $role = 'owner'): PlatformAdmin
    {
        config(['eghub.admin_require_2fa' => false]);

        return PlatformAdmin::query()->create(['name' => ucfirst($role), 'email' => "$role@egweb.test", 'password' => 'Secret-pass-123', 'role' => $role]);
    }

    /** @return array<string, string> */
    private function signup(string $email, string $phone): array
    {
        return [
            'name' => 'كافيه تاني', 'slug' => 'copy-cafe', 'owner_name' => 'Ahmed', 'email' => $email, 'phone' => $phone,
            'password' => 'Secret-pass-123', 'password_confirmation' => 'Secret-pass-123', 'terms' => '1',
        ];
    }

    public function test_a_blocked_restaurant_is_closed_and_its_owner_cannot_sign_up_again_until_the_block_is_lifted(): void
    {
        $tenant = $this->restaurant('cafe-a');
        $owner = $this->owner($tenant);
        $owner->forceFill(['phone' => '01003819792'])->save();
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')->post($this->adminUrl('/tenants/cafe-a/block'), ['reason' => 'بيقلد'])->assertRedirect();

        $tenant->refresh();
        $this->assertTrue($tenant->isBlocked() && $tenant->isSuspended());
        $this->assertSame([['owner@cafe-a.test', '01003819792', 'بيقلد']], BlockedSignup::query()->get(['email', 'phone', 'reason'])->map->only('email', 'phone', 'reason')->map(fn ($r) => array_values($r))->all());
        $this->get($this->pathUrl('cafe-a'))->assertStatus(503);
        // the team is signed out and can't sign in again
        $this->actingAs($owner)->get($this->dashUrl('/'))->assertRedirect($this->dashUrl('/login'));
        $this->app['auth']->forgetGuards();
        $this->post($this->dashUrl('/login'), ['login' => 'owner@cafe-a.test', 'password' => 'Secret-pass-123'])->assertSessionHasErrors('login');
        $this->assertGuest('web');

        // the same mobile written another way, or the same email in capitals: refused
        $refused = ['email' => 'مش هنقدر نفتح حساب بالبيانات دي. كلّم دعم EgHub.'];
        $this->post($this->menuUrl('register'), $this->signup('new@copy.test', '+20 100 381 9792'))->assertSessionHasErrors($refused);
        $this->post($this->menuUrl('register'), $this->signup('OWNER@cafe-a.test', '01099999999'))->assertSessionHasErrors($refused);
        $this->assertNull(Tenant::query()->find('copy-cafe'));

        $this->actingAs($admin, 'admin')->get($this->adminUrl('/blocked-signups'))->assertOk()->assertSee('01003819792')->assertSee('بيقلد');
        $this->post($this->adminUrl('/tenants/cafe-a/unblock'))->assertRedirect();
        $this->assertFalse($tenant->refresh()->isBlocked() || $tenant->isSuspended());
        $this->assertSame(0, BlockedSignup::query()->count());
    }

    public function test_only_who_manages_restaurants_blocks_and_a_removed_row_may_sign_up_again(): void
    {
        $this->restaurant('cafe-a');
        $this->actingAs($this->admin('finance'), 'admin')->post($this->adminUrl('/tenants/cafe-a/block'))->assertForbidden();
        $this->assertSame(0, BlockedSignup::query()->count());

        $blocked = BlockedSignup::query()->create(['email' => 'gone@copy.test', 'phone' => '01055555555']);
        $this->actingAs($this->admin(), 'admin')->post($this->adminUrl("/blocked-signups/{$blocked->id}/delete"))->assertRedirect();
        $this->app['auth']->forgetGuards();

        $this->post($this->menuUrl('register'), $this->signup('gone@copy.test', '01055555555'))->assertSessionHasNoErrors();
        $this->assertNotNull(Tenant::query()->find('copy-cafe'));
    }
}
