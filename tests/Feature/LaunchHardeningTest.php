<?php

namespace Tests\Feature;

use App\Models\PlatformAdmin;
use App\Services\Auth\Totp;
use Tests\TenantTestCase;

/**
 * Launch hardening: the camera only on the dashboard host (staff QR scanners), two-factor authentication required of
 * every platform admin, login throttled per account as well as per IP, and idle admin sessions signed out.
 */
class LaunchHardeningTest extends TenantTestCase
{
    private function admin(): PlatformAdmin
    {
        return PlatformAdmin::query()->create(['name' => 'Admin', 'email' => 'admin@egmenu.test', 'password' => 'Secret-pass-123']);
    }

    public function test_only_the_dashboard_host_may_use_the_camera(): void
    {
        $this->get($this->dashUrl('/login'))->assertOk()
            ->assertHeader('Permissions-Policy', 'camera=(self), microphone=(), geolocation=()');
        $this->get($this->adminUrl('/login'))->assertOk()
            ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $this->get($this->menuUrl('/'))->assertOk()
            ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        $this->restaurant('cafe-a');
        $this->get($this->pathUrl('cafe-a'))->assertOk()
            ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
    }

    public function test_an_admin_without_two_factor_can_only_open_the_security_page(): void
    {
        config(['egmenu.admin_require_2fa' => true]);
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')->get($this->adminUrl('/'))->assertRedirect(route('admin.security'));
        $this->actingAs($admin, 'admin')->get($this->adminUrl('/tenants'))->assertRedirect(route('admin.security'));
        $this->actingAs($admin, 'admin')->get($this->adminUrl('/security'))->assertOk()
            ->assertSee('data-two-factor-required', false)
            ->assertSee(__('Every platform admin must use two-factor authentication. Turn it on here to open the rest of the admin panel.'));

        // the setup forms work, and once 2FA is on the panel opens
        $this->actingAs($admin, 'admin')->post($this->adminUrl('/security/two-factor'))->assertRedirect(route('admin.security'));
        $secret = $admin->fresh()->two_factor_secret;
        $this->actingAs($admin, 'admin')->post($this->adminUrl('/security/two-factor/confirm'), ['code' => Totp::code($secret)])
            ->assertRedirect(route('admin.security'));
        $this->actingAs($admin->fresh(), 'admin')->get($this->adminUrl('/'))->assertOk();
    }

    public function test_an_admin_without_two_factor_can_still_sign_out_and_the_flag_relaxes_it(): void
    {
        config(['egmenu.admin_require_2fa' => true]);
        $admin = $this->admin();
        $this->actingAs($admin, 'admin')->post($this->adminUrl('/logout'))->assertRedirect(route('admin.login'));
        $this->assertGuest('admin');

        config(['egmenu.admin_require_2fa' => false]);
        $this->actingAs($admin, 'admin')->get($this->adminUrl('/'))->assertOk();
    }

    public function test_a_sixth_login_try_at_one_account_is_throttled_even_from_new_ips(): void
    {
        $this->restaurant('cafe-a');
        for ($i = 1; $i <= 5; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => "10.0.0.$i"])
                ->post($this->dashUrl('/login'), ['login' => 'Owner@Cafe-a.test', 'password' => 'wrong'])->assertSessionHasErrors('login');
        }
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.99'])
            ->post($this->dashUrl('/login'), ['login' => 'owner@cafe-a.test', 'password' => 'wrong'])->assertStatus(429);
        // another account from that IP is not affected
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.99'])
            ->post($this->dashUrl('/login'), ['login' => 'other@cafe-a.test', 'password' => 'wrong'])->assertSessionHasErrors('login');

        // the admin login has the same per-account limit
        $this->admin();
        for ($i = 1; $i <= 5; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => "10.1.0.$i"])
                ->post($this->adminUrl('/login'), ['email' => 'admin@egmenu.test', 'password' => 'wrong'])->assertSessionHasErrors('email');
        }
        $this->withServerVariables(['REMOTE_ADDR' => '10.1.0.99'])
            ->post($this->adminUrl('/login'), ['email' => 'admin@egmenu.test', 'password' => 'wrong'])->assertStatus(429);
    }

    public function test_an_idle_admin_session_is_signed_out(): void
    {
        config(['egmenu.admin_idle_minutes' => 120]);
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')->get($this->adminUrl('/'))->assertOk();
        $this->travel(119)->minutes();
        $this->get($this->adminUrl('/'))->assertOk();
        $this->travel(121)->minutes();
        $this->get($this->adminUrl('/'))->assertRedirect(route('admin.login'))->assertSessionHasErrors('email');
        $this->assertGuest('admin');
    }

    public function test_the_dashboard_has_no_idle_limit(): void
    {
        $owner = $this->owner($this->restaurant('cafe-a'));
        $this->actingAs($owner)->get($this->dashUrl('/'))->assertOk();
        $this->travel(300)->minutes();
        $this->get($this->dashUrl('/'))->assertOk();
    }
}
