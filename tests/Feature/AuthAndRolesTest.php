<?php

namespace Tests\Feature;

use App\Models\PlatformAdmin;
use App\Models\User;
use Tests\TenantTestCase;

class AuthAndRolesTest extends TenantTestCase
{
    public function test_staff_can_log_in_with_email_or_phone(): void
    {
        $a = $this->restaurant('cafe-a');
        $this->as($a, fn () => $this->owner($a)->update(['phone' => '01011111111']));

        $this->post($this->dashUrl('/login'), ['login' => 'owner@cafe-a.test', 'password' => 'Secret-pass-123'])
            ->assertRedirect(route('dash.home'));
        $this->post($this->dashUrl('/logout'));

        $this->post($this->dashUrl('/login'), ['login' => '+20 101 111 1111', 'password' => 'Secret-pass-123'])
            ->assertRedirect(route('dash.home'));
    }

    public function test_wrong_password_and_inactive_user_are_rejected(): void
    {
        $a = $this->restaurant('cafe-a');
        $this->post($this->dashUrl('/login'), ['login' => 'owner@cafe-a.test', 'password' => 'nope'])->assertSessionHasErrors('login');

        $kitchen = $this->staff($a, 'kitchen', 'k@cafe-a.test');
        $kitchen->forceFill(['is_active' => false])->save();
        $this->post($this->dashUrl('/login'), ['login' => 'k@cafe-a.test', 'password' => 'Secret-pass-123'])->assertSessionHasErrors('login');
        $this->assertGuest('web');
    }

    public function test_deactivated_user_is_logged_out_on_next_request(): void
    {
        $a = $this->restaurant('cafe-a');
        $waiter = $this->staff($a, 'waiter', 'w@cafe-a.test');
        $waiter->forceFill(['is_active' => false])->save();

        $this->actingAs($waiter)->get($this->dashUrl())->assertRedirect($this->dashUrl('/login'));
    }

    public function test_roles_limit_dashboard_pages(): void
    {
        $a = $this->restaurant('cafe-a');
        $this->actingAs($this->staff($a, 'waiter', 'w@cafe-a.test'))->get($this->dashUrl('/users'))->assertForbidden();
        $this->actingAs($this->staff($a, 'manager', 'm@cafe-a.test'))->get($this->dashUrl('/users'))->assertForbidden();
        $this->actingAs($this->owner($a))->get($this->dashUrl('/users'))->assertOk();
    }

    public function test_owner_adds_staff_with_a_role(): void
    {
        $a = $this->restaurant('cafe-a');
        $this->actingAs($this->owner($a))->post($this->dashUrl('/users'), [
            'name' => 'Cashier One', 'phone' => '01022222222', 'role' => 'cashier', 'password' => 'Secret-pass-123',
        ])->assertSessionHasNoErrors();

        $cashier = User::query()->where('phone', '01022222222')->first();
        $this->assertSame('cafe-a', $cashier->tenant_id);
        $this->assertTrue($cashier->hasRole('cashier'));
        $this->assertTrue($cashier->can('pos.use'));
        $this->assertFalse($cashier->can('settings.manage'));
    }

    public function test_last_owner_cannot_be_demoted(): void
    {
        $a = $this->restaurant('cafe-a');
        $owner = $this->owner($a);
        $this->actingAs($owner)->post($this->dashUrl("/users/{$owner->id}"), [
            'name' => 'Owner', 'email' => 'owner@cafe-a.test', 'role' => 'waiter', 'is_active' => '1',
        ])->assertSessionHasErrors('role');
        $this->assertTrue($owner->fresh()->hasRole('owner'));
    }

    public function test_admin_and_staff_sessions_are_separate(): void
    {
        $a = $this->restaurant('cafe-a');
        $admin = PlatformAdmin::query()->create(['name' => 'Admin', 'email' => 'admin@egmenu.test', 'password' => 'Secret-pass-123']);

        // A platform admin session is not a restaurant session.
        $this->actingAs($admin, 'admin')->get($this->dashUrl())->assertRedirect($this->dashUrl('/login'));
        $this->actingAs($admin, 'admin')->get($this->adminUrl())->assertOk();

        // A restaurant owner is not a platform admin.
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->owner($a), 'web')->get($this->adminUrl())->assertRedirect($this->adminUrl('/login'));
    }
}
