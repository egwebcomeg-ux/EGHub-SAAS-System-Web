<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\PlatformAdmin;
use App\Models\Tenant;
use App\Notifications\ResetPasswordLink;
use App\Services\Settings;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TenantTestCase;

/** The admin restaurant page: support actions on the restaurant's users and its basic info (tenants.manage). */
class AdminTenantUsersTest extends TenantTestCase
{
    private function support(): PlatformAdmin
    {
        return PlatformAdmin::query()->create(['name' => 'Support', 'email' => 'support@egweb.test', 'password' => 'Secret-pass-123', 'role' => 'support']);
    }

    /** @return list<string> */
    private function auditActions(Tenant $tenant): array
    {
        return $this->as($tenant, fn () => AuditLog::query()->orderBy('id')->pluck('action')->all());
    }

    public function test_the_page_lists_the_users_with_their_actions(): void
    {
        $tenant = $this->restaurant('cafe-a');
        $this->staff($tenant, 'cashier', 'cashier@cafe-a.test');

        $this->actingAs($this->support(), 'admin')->get($this->adminUrl('/tenants/cafe-a'))->assertOk()
            ->assertSee('owner@cafe-a.test')->assertSee('cashier@cafe-a.test')
            ->assertSee(__('Email a reset link'))->assertSee(__('Sign out everywhere'))->assertSee(__('Restaurant info'));
    }

    public function test_support_emails_a_reset_link_to_a_restaurant_user(): void
    {
        Notification::fake();
        $tenant = $this->restaurant('cafe-a');
        $owner = $this->owner($tenant);

        $this->actingAs($this->support(), 'admin')->post($this->adminUrl("/tenants/cafe-a/users/{$owner->id}/reset-link"))->assertSessionHas('ok');

        Notification::assertSentTo($owner, ResetPasswordLink::class, fn (ResetPasswordLink $n) => str_contains($n->url, 'app.'));
        $this->assertSame(['user.password_link_by_platform'], $this->auditActions($tenant));
    }

    public function test_support_sets_a_temporary_password_shown_once(): void
    {
        $tenant = $this->restaurant('cafe-a');
        $owner = $this->owner($tenant);
        $oldToken = $owner->remember_token;

        $response = $this->actingAs($this->support(), 'admin')->post($this->adminUrl("/tenants/cafe-a/users/{$owner->id}/password"));
        $response->assertSessionHas('temporary_password');
        $password = session('temporary_password')['password'];

        $this->assertSame(12, strlen($password));
        $owner->refresh();
        $this->assertTrue(Hash::check($password, $owner->password));
        $this->assertFalse(Hash::check('Secret-pass-123', $owner->password));
        $this->assertNotSame($oldToken, $owner->remember_token);
        $this->assertSame(['user.password_set_by_platform'], $this->auditActions($tenant));

        // the restaurant user signs in with it
        $this->post($this->dashUrl('/login'), ['login' => 'owner@cafe-a.test', 'password' => $password])->assertRedirect();
        $this->assertAuthenticatedAs($owner, 'web');
    }

    public function test_sign_out_everywhere_rotates_the_remember_token(): void
    {
        $tenant = $this->restaurant('cafe-a');
        $owner = $this->owner($tenant);
        $owner->forceFill(['remember_token' => 'old-token'])->save();

        $this->actingAs($this->support(), 'admin')->post($this->adminUrl("/tenants/cafe-a/users/{$owner->id}/sign-out"))->assertSessionHas('ok');

        $this->assertNotSame('old-token', $owner->fresh()->remember_token);
        $this->assertSame(['user.signed_out_by_platform'], $this->auditActions($tenant));
    }

    public function test_an_admin_without_tenants_manage_cannot_touch_users(): void
    {
        Notification::fake();
        $tenant = $this->restaurant('cafe-a');
        $owner = $this->owner($tenant);
        $finance = PlatformAdmin::query()->create(['name' => 'Fin', 'email' => 'fin@egweb.test', 'password' => 'Secret-pass-123', 'role' => 'finance']);

        foreach (['reset-link', 'password', 'sign-out', 'reset-2fa', 'verify-phone'] as $action) {
            $this->actingAs($finance, 'admin')->post($this->adminUrl("/tenants/cafe-a/users/{$owner->id}/$action"))->assertForbidden();
        }
        $this->actingAs($finance, 'admin')->post($this->adminUrl('/tenants/cafe-a/info'), ['name' => 'Hacked'])->assertForbidden();

        $this->assertTrue(Hash::check('Secret-pass-123', $owner->fresh()->password));
        Notification::assertNothingSent();
        $this->assertSame([], $this->auditActions($tenant));
    }

    public function test_a_user_of_another_restaurant_is_not_found(): void
    {
        $this->restaurant('cafe-a');
        $other = $this->owner($this->restaurant('cafe-b'));

        $this->actingAs($this->support(), 'admin')->post($this->adminUrl("/tenants/cafe-a/users/{$other->id}/password"))->assertNotFound();
        $this->assertTrue(Hash::check('Secret-pass-123', $other->fresh()->password));
    }

    public function test_support_edits_the_restaurant_basic_info(): void
    {
        $tenant = $this->restaurant('cafe-a');

        $this->actingAs($this->support(), 'admin')->post($this->adminUrl('/tenants/cafe-a/info'), [
            'name' => 'Cafe Alpha', 'phone' => '01012345678', 'whatsapp' => '01112345678',
        ])->assertSessionHas('ok');

        $this->assertSame('Cafe Alpha', $tenant->fresh()->name);
        $this->assertSame(['01012345678', '01112345678'], $this->as($tenant, fn () => [Settings::get('phone'), Settings::get('whatsapp')]));
        $this->assertSame(['restaurant.info_by_platform'], $this->auditActions($tenant));
    }
}
