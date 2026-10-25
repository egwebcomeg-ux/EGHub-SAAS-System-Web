<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\PlatformAdmin;
use App\Models\PlatformAuditLog;
use App\Models\SupportAccessTicket;
use App\Models\Tenant;
use App\Models\User;
use App\Services\SupportAccess;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\TenantTestCase;

/** "Open the restaurant dashboard" from the admin (App\Services\SupportAccess): ticket, banner, guard rails, audits. */
class AdminSupportAccessTest extends TenantTestCase
{
    private function admin(string $role = 'owner', array $extra = []): PlatformAdmin
    {
        return PlatformAdmin::query()->create([
            'name' => ucfirst($role), 'email' => "$role@egweb.test", 'password' => 'Secret-pass-123',
            'role' => $role, 'permissions' => $extra ?: null,
        ]);
    }

    /** The admin asks for a ticket; returns the app.<domain> URL it redirects to. */
    private function ticketUrl(PlatformAdmin $admin, Tenant $tenant, array $data = []): string
    {
        $response = $this->actingAs($admin, 'admin')->post($this->adminUrl("/tenants/{$tenant->id}/support-access"), $data);
        $response->assertRedirect();
        $url = (string) $response->headers->get('Location');
        $this->assertMatchesRegularExpression('#^http://app\.egmenu\.test/support-access/[a-f0-9]{48}$#', $url);

        return $url;
    }

    private function enter(PlatformAdmin $admin, Tenant $tenant, array $data = []): TestResponse
    {
        return $this->get($this->ticketUrl($admin, $tenant, $data));
    }

    private function dashHome(): string
    {
        return 'http://app.egmenu.test';
    }

    /** @return list<string> */
    private function platformActions(): array
    {
        return PlatformAuditLog::query()->orderBy('id')->pluck('action')->all();
    }

    /** @return list<string> */
    private function tenantActions(Tenant $tenant): array
    {
        return $this->as($tenant, fn () => AuditLog::query()->orderBy('id')->pluck('action')->all());
    }

    public function test_an_admin_without_the_permission_gets_403_and_no_button(): void
    {
        $tenant = $this->restaurant('cafe-a');
        $support = $this->admin('support');

        $this->actingAs($support, 'admin')->post($this->adminUrl('/tenants/cafe-a/support-access'))->assertForbidden();
        $this->actingAs($support, 'admin')->get($this->adminUrl('/tenants/cafe-a'))->assertOk()->assertDontSee('data-support-access', false);
        $this->actingAs($support, 'admin')->get($this->adminUrl('/tenants'))->assertOk()->assertDontSee('data-support-access', false);
        $this->assertSame(0, SupportAccessTicket::query()->count());

        // granted as an extra permission it works
        $granted = $this->admin('manager', ['tenants.impersonate']);
        $this->enter($granted, $tenant)->assertRedirect($this->dashHome());
        $this->assertAuthenticatedAs($this->owner($tenant), 'web');
    }

    public function test_the_owner_admin_opens_the_dashboard_as_the_restaurant_owner(): void
    {
        $tenant = $this->restaurant('cafe-a');
        $owner = $this->owner($tenant);
        $owner->forceFill(['remember_token' => 'keep-me'])->save();
        $passwordHash = $owner->fresh()->password;
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')->get($this->adminUrl('/tenants'))->assertOk()->assertSee('data-support-access="cafe-a"', false);
        $url = $this->ticketUrl($admin, $tenant);
        // only the hash is stored, bound to the admin, the restaurant and the owner
        $row = SupportAccessTicket::query()->sole();
        $this->assertSame(hash('sha256', basename($url)), $row->token_hash);
        $this->assertSame([$admin->id, 'cafe-a', $owner->id], [$row->admin_id, $row->tenant_id, $row->user_id]);

        $this->get($url)->assertRedirect($this->dashHome());
        $this->assertAuthenticatedAs($owner, 'web');
        $this->assertSame($admin->id, session(SupportAccess::ADMIN));
        $this->assertNotNull(session(SupportAccess::STARTED));

        $owner->refresh();
        $this->assertSame($passwordHash, $owner->password);
        $this->assertSame('keep-me', $owner->remember_token);

        $this->get($this->dashUrl('/'))->assertOk()->assertSee('data-support-banner', false)
            ->assertSee(__('You are signed in as :user at :restaurant from the admin', ['user' => $owner->name, 'restaurant' => $tenant->name]))
            ->assertSee('owner@egweb.test')->assertSee(__('Leave and go back to the admin'))->assertDontSee('data-support-suspended', false);

        $this->assertSame(['tenant.impersonation_started'], $this->platformActions());
        $started = PlatformAuditLog::query()->sole();
        $this->assertSame([$admin->id, 'cafe-a', $owner->id], [$started->admin_id, $started->subject, $started->meta['user']]);
        $this->assertNotNull($started->ip);
        $this->assertSame(['support.access_by_platform'], $this->tenantActions($tenant));
    }

    public function test_a_ticket_works_once(): void
    {
        $tenant = $this->restaurant('cafe-a');
        $url = $this->ticketUrl($this->admin(), $tenant);

        $this->get($url)->assertRedirect($this->dashHome());
        $this->post($this->dashUrl('/support-access/exit'));
        $this->assertGuest('web');

        $this->get($url)->assertRedirect($this->dashUrl('/login'))->assertSessionHasErrors('login');
        $this->assertGuest('web');
        $this->get($this->dashUrl('/support-access/'.str_repeat('a', 48)))->assertRedirect($this->dashUrl('/login'));
        $this->assertGuest('web');
    }

    public function test_a_ticket_expires_after_60_seconds(): void
    {
        $tenant = $this->restaurant('cafe-a');
        $url = $this->ticketUrl($this->admin(), $tenant);

        $this->travel(61)->seconds();
        $this->get($url)->assertRedirect($this->dashUrl('/login'))->assertSessionHasErrors('login');
        $this->assertGuest('web');
        $this->assertSame([], $this->platformActions());
    }

    public function test_the_ticket_is_bound_to_the_picked_account_the_restaurant_and_the_admin(): void
    {
        $tenant = $this->restaurant('cafe-a');
        $other = $this->restaurant('cafe-b');
        $cashier = $this->staff($tenant, 'cashier', 'cashier@cafe-a.test');
        $admin = $this->admin();

        // a picked staff account of this restaurant
        $this->enter($admin, $tenant, ['user_id' => $cashier->id])->assertRedirect($this->dashHome());
        $this->assertAuthenticatedAs($cashier, 'web');
        $this->post($this->dashUrl('/support-access/exit'));

        // an account of another restaurant is never picked
        $this->actingAs($admin, 'admin')->post($this->adminUrl('/tenants/cafe-a/support-access'), ['user_id' => $this->owner($other)->id])
            ->assertSessionHasErrors('support_access');
        $this->assertSame(1, SupportAccessTicket::query()->count());

        // the admin switched off before the ticket is used
        $url = $this->ticketUrl($admin, $tenant);
        $admin->forceFill(['is_active' => false])->save();
        $this->get($url)->assertRedirect($this->dashUrl('/login'));
        $this->assertGuest('web');
        $admin->forceFill(['is_active' => true])->save();

        // the restaurant deleted before the ticket is used, and no ticket at all for a deleted one
        $url = $this->ticketUrl($admin, $tenant);
        $tenant->delete();
        $this->get($url)->assertRedirect($this->dashUrl('/login'));
        $this->assertGuest('web');
        $this->actingAs($admin, 'admin')->post($this->adminUrl('/tenants/cafe-a/support-access'))->assertNotFound();
    }

    public function test_a_suspended_restaurant_opens_with_the_suspension_notice(): void
    {
        $tenant = $this->restaurant('cafe-a');
        $tenant->update(['status' => 'suspended', 'suspended_at' => now()]);

        $this->enter($this->admin(), $tenant)->assertRedirect($this->dashHome());
        $this->get($this->dashUrl('/'))->assertOk()->assertSee('data-support-suspended', false)
            ->assertSee(__('This restaurant is suspended: its menu is offline for guests.'));
    }

    public function test_account_and_money_actions_are_blocked_but_the_rest_works(): void
    {
        $tenant = $this->restaurant('cafe-a');
        $owner = $this->owner($tenant);
        $cashier = $this->staff($tenant, 'cashier', 'cashier@cafe-a.test');
        $owner->forceFill(['must_change_password' => true])->save();
        $hash = $owner->fresh()->password;
        $this->enter($this->admin(), $tenant);

        // the welcome-kit "pick your own password first" gate is skipped for support
        $this->get($this->dashUrl('/'))->assertOk();

        $blocked = __('Not available while signed in from the admin.');
        $this->from($this->dashUrl('/security'))->post($this->dashUrl('/security/password'), [
            'current_password' => 'Secret-pass-123', 'password' => 'New-pass-12345', 'password_confirmation' => 'New-pass-12345',
        ])->assertRedirect($this->dashUrl('/security'))->assertSessionHasErrors(['support_access' => $blocked]);
        $this->post($this->dashUrl('/security/two-factor'))->assertSessionHasErrors('support_access');
        $this->post($this->dashUrl('/security/two-factor/disable'))->assertSessionHasErrors('support_access');
        $this->post($this->dashUrl('/billing/checkout'), ['plan' => 'business'])->assertSessionHasErrors('support_access');
        $this->post($this->dashUrl('/billing/invoices/INV-1/pay'))->assertSessionHasErrors('support_access');
        $this->post($this->dashUrl('/phone'), ['phone' => '01011112222'])->assertSessionHasErrors('support_access');
        $this->postJson($this->dashUrl('/billing/checkout'), ['plan' => 'business'])->assertForbidden();
        // staff accounts: neither the impersonated account nor any other (a password support picked would outlive the session)
        $this->post($this->dashUrl("/users/{$owner->id}"), ['name' => 'Owner', 'email' => 'new@cafe-a.test', 'role' => 'owner'])
            ->assertSessionHasErrors('support_access');
        $this->post($this->dashUrl("/users/{$cashier->id}"), ['name' => 'Cashier two', 'email' => 'cashier@cafe-a.test', 'role' => 'cashier'])
            ->assertSessionHasErrors('support_access');
        $this->post($this->dashUrl('/users'), ['name' => 'Backdoor', 'email' => 'back@cafe-a.test', 'role' => 'manager', 'password' => 'Secret-pass-123'])
            ->assertSessionHasErrors('support_access');
        // the restaurant's data never leaves with support: customer export, the domain (backups: same middleware, plan-gated)
        $this->get($this->dashUrl('/customers/export'))->assertRedirect()->assertSessionHasErrors('support_access');
        $this->post($this->dashUrl('/domain/delete'))->assertSessionHasErrors('support_access');

        $owner->refresh();
        $this->assertSame($hash, $owner->password);
        $this->assertSame('owner@cafe-a.test', $owner->email);
        $this->assertNull($owner->two_factor_secret);

        // menus and settings stay editable, and the restaurant's audit names the EgWeb admin
        $this->post($this->dashUrl('/menu/categories'), ['name' => 'Desserts'])->assertSessionDoesntHaveErrors('support_access');
        $this->post($this->dashUrl('/settings'), ['brand_name' => 'Cafe A', 'currency' => 'ج.م', 'currency_en' => 'EGP', 'color_primary' => '#F26522',
            'color_secondary' => '#0A2540', 'color_bg' => '#FFFFFF', 'font' => 'Cairo', 'default_locale' => 'ar'])->assertSessionDoesntHaveErrors('support_access');
        $tagged = $this->as($tenant, fn () => AuditLog::query()->where('action', '!=', 'support.access_by_platform')->get());
        $this->assertNotEmpty($tagged);
        $this->assertTrue($tagged->every(fn (AuditLog $log) => ($log->meta['support_admin'] ?? null) === 'owner@egweb.test'));
    }

    public function test_the_support_session_ends_by_itself_after_60_minutes(): void
    {
        $tenant = $this->restaurant('cafe-a');
        $this->enter($this->admin(), $tenant);

        $this->travel(59)->minutes();
        $this->get($this->dashUrl('/'))->assertOk();
        $this->travel(2)->minutes();
        $this->get($this->dashUrl('/'))->assertRedirect($this->dashUrl('/login'))->assertSessionHasErrors('login');
        $this->assertGuest('web');
        $this->assertSame(['tenant.impersonation_started', 'tenant.impersonation_expired'], $this->platformActions());
        $this->assertSame(['support.access_by_platform', 'support.exit_by_platform'], $this->tenantActions($tenant));
    }

    public function test_losing_the_permission_ends_the_session(): void
    {
        $tenant = $this->restaurant('cafe-a');
        $admin = $this->admin('manager', ['tenants.impersonate']);
        $this->enter($admin, $tenant);

        $admin->forceFill(['permissions' => null])->save();
        $this->get($this->dashUrl('/'))->assertRedirect($this->dashUrl('/login'));
        $this->assertGuest('web');
        $this->assertSame('revoked', PlatformAuditLog::query()->latest('id')->first()->meta['reason']);
    }

    public function test_leaving_returns_to_the_admin_page_and_signs_out(): void
    {
        $tenant = $this->restaurant('cafe-a', 'full');
        $owner = $this->owner($tenant);
        $this->enter($this->admin(), $tenant);

        $this->post($this->dashUrl('/support-access/exit'))->assertRedirect($this->adminUrl('/tenants/cafe-a'));
        $this->assertGuest('web');
        $this->assertFalse(session()->has(SupportAccess::ADMIN));
        $this->assertSame(['tenant.impersonation_started', 'tenant.impersonation_ended'], $this->platformActions());
        $this->assertSame(['support.access_by_platform', 'support.exit_by_platform'], $this->tenantActions($tenant));
        $this->assertSame($owner->id, $this->as($tenant, fn () => AuditLog::query()->latest('id')->first()->user_id));

        // the owner reads it in their audit log, and the admin on the restaurant page
        $this->actingAs($owner, 'web')->get($this->dashUrl('/audit'))->assertOk()->assertSee('دخول من فريق EgHub');
        $this->actingAs($this->admin('manager'), 'admin')->get($this->adminUrl('/tenants/cafe-a'))->assertOk()
            ->assertSee(__('Signed in to the dashboard (support)'));

        // without a support session there is nothing to leave
        $this->post($this->dashUrl('/support-access/exit'))->assertNotFound();
    }

    public function test_a_normal_owner_session_is_untouched(): void
    {
        $tenant = $this->restaurant('cafe-a');
        $owner = $this->owner($tenant);

        $this->actingAs($owner, 'web')->get($this->dashUrl('/'))->assertOk()->assertDontSee('data-support-banner', false);
        $this->from($this->dashUrl('/security'))->post($this->dashUrl('/security/password'), [
            'current_password' => 'Secret-pass-123', 'password' => 'New-pass-12345', 'password_confirmation' => 'New-pass-12345',
        ])->assertSessionDoesntHaveErrors();
        $this->assertTrue(Hash::check('New-pass-12345', User::query()->find($owner->id)->password));
    }
}
