<?php

namespace Tests\Feature;

use App\Models\PlatformAdmin;
use App\Notifications\ResetPasswordLink;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TenantTestCase;

/** admin › فريق الإدارة: adding people with a role, switching them off, removing them, and the guards around owners. */
class AdminTeamTest extends TenantTestCase
{
    private function platformOwner(): PlatformAdmin
    {
        return PlatformAdmin::query()->create(['name' => 'Owner', 'email' => 'owner@egweb.test', 'password' => 'Secret-pass-123', 'role' => 'owner']);
    }

    public function test_owner_adds_a_member_who_gets_a_set_password_link(): void
    {
        Notification::fake();
        $owner = $this->platformOwner();

        $this->actingAs($owner, 'admin')->get($this->adminUrl('/team/create'))->assertOk();
        $this->actingAs($owner, 'admin')->post($this->adminUrl('/team'), [
            'name' => 'Mona Support', 'email' => 'MONA@egweb.test', 'role' => 'support', 'permissions' => ['assistant.view', 'content.manage'], 'access' => 'link',
        ])->assertRedirect(route('admin.admins'));

        $mona = PlatformAdmin::query()->where('email', 'mona@egweb.test')->sole();
        $this->assertSame('support', $mona->role);
        $this->assertSame(['content.manage'], $mona->permissions); // assistant.view already comes with the role
        $this->assertTrue($mona->allows('content.manage'));
        $this->assertFalse($mona->allows('billing.manage'));
        Notification::assertSentTo($mona, ResetPasswordLink::class, fn (ResetPasswordLink $n) => str_contains($n->url, 'admin.'));
        $this->actingAs($owner, 'admin')->get($this->adminUrl('/team'))->assertOk()->assertSee('mona@egweb.test');
    }

    public function test_owner_adds_a_member_with_a_temporary_password_who_can_sign_in(): void
    {
        $owner = $this->platformOwner();

        $this->actingAs($owner, 'admin')->post($this->adminUrl('/team'), [
            'name' => 'Karim', 'email' => 'karim@egweb.test', 'role' => 'finance', 'access' => 'password', 'password' => 'short',
        ])->assertSessionHasErrors('password');
        $this->actingAs($owner, 'admin')->post($this->adminUrl('/team'), [
            'name' => 'Karim', 'email' => 'karim@egweb.test', 'role' => 'finance', 'access' => 'password', 'password' => 'Temp-pass-2026',
        ])->assertRedirect(route('admin.admins'));

        $karim = PlatformAdmin::query()->where('email', 'karim@egweb.test')->sole();
        $this->assertTrue(Hash::check('Temp-pass-2026', $karim->password));
        $this->post($this->adminUrl('/logout'));
        $this->post($this->adminUrl('/login'), ['email' => 'karim@egweb.test', 'password' => 'Temp-pass-2026'])->assertRedirect(route('admin.home'));
        $this->get($this->adminUrl('/invoices'))->assertOk();
        $this->get($this->adminUrl('/team'))->assertForbidden();
    }

    public function test_nobody_removes_or_switches_off_themselves_or_the_last_owner(): void
    {
        $owner = $this->platformOwner();

        $this->actingAs($owner, 'admin')->post($this->adminUrl("/team/{$owner->id}/delete"))->assertSessionHasErrors('role');
        $this->actingAs($owner, 'admin')->post($this->adminUrl("/team/{$owner->id}"), [
            'name' => 'Owner', 'email' => 'owner@egweb.test', 'role' => 'manager', 'is_active' => 1,
        ])->assertSessionHasErrors('role');
        $this->assertSame('owner', $owner->fresh()->role);
        $this->assertTrue(PlatformAdmin::query()->whereKey($owner->id)->exists());

        // with a second owner, one owner may remove the other; the one left stays the last owner
        $second = PlatformAdmin::query()->create(['name' => 'Second', 'email' => 'second@egweb.test', 'password' => 'Secret-pass-123', 'role' => 'owner']);
        $this->actingAs($owner, 'admin')->post($this->adminUrl("/team/{$second->id}/delete"))->assertRedirect(route('admin.admins'));
        $this->assertSame(1, PlatformAdmin::query()->where('role', 'owner')->count());
    }

    public function test_a_non_owner_cannot_make_owners_or_grant_what_they_do_not_have(): void
    {
        $lead = PlatformAdmin::query()->create(['name' => 'Lead', 'email' => 'lead@egweb.test', 'password' => 'Secret-pass-123', 'role' => 'support', 'permissions' => ['admins.manage']]);
        $owner = $this->platformOwner();

        $this->actingAs($lead, 'admin')->post($this->adminUrl('/team'), [
            'name' => 'X', 'email' => 'x@egweb.test', 'role' => 'owner', 'access' => 'link',
        ])->assertSessionHasErrors('role');
        $this->actingAs($lead, 'admin')->post($this->adminUrl('/team'), [
            'name' => 'X', 'email' => 'x@egweb.test', 'role' => 'support', 'permissions' => ['billing.manage'], 'access' => 'link',
        ])->assertSessionHasErrors('permissions.0');
        $this->assertFalse(PlatformAdmin::query()->where('email', 'x@egweb.test')->exists());

        $this->actingAs($lead, 'admin')->post($this->adminUrl("/team/{$owner->id}/delete"))->assertSessionHasErrors('role');
        $this->actingAs($lead, 'admin')->get($this->adminUrl("/team/{$owner->id}/edit"))->assertRedirect(route('admin.admins'));
        $this->assertTrue(PlatformAdmin::query()->whereKey($owner->id)->exists());
    }

    public function test_owner_types_a_new_password_for_a_member_who_then_signs_in_with_it(): void
    {
        $owner = $this->platformOwner();
        $member = PlatformAdmin::query()->create(['name' => 'Ganna', 'email' => 'ganna@egweb.test', 'password' => 'Secret-pass-123', 'role' => 'sales']);
        $fields = ['name' => 'Ganna', 'email' => 'ganna@egweb.test', 'role' => 'sales', 'is_active' => 1];

        $this->actingAs($owner, 'admin')->get($this->adminUrl("/team/{$member->id}/edit"))->assertOk()->assertSee('data-member-password', false);
        $this->post($this->adminUrl("/team/{$member->id}"), $fields + ['password' => 'short'])->assertSessionHasErrors('password');
        // left empty, the password stays
        $this->post($this->adminUrl("/team/{$member->id}"), $fields + ['password' => ''])->assertRedirect(route('admin.admins'));
        $this->assertTrue(Hash::check('Secret-pass-123', $member->fresh()->password));

        $this->post($this->adminUrl("/team/{$member->id}"), $fields + ['password' => 'Ganna-new-2026'])->assertRedirect(route('admin.admins'));
        $this->assertTrue(Hash::check('Ganna-new-2026', $member->fresh()->password));
        $this->post($this->adminUrl('/logout'));
        $this->post($this->adminUrl('/login'), ['email' => 'ganna@egweb.test', 'password' => 'Ganna-new-2026'])->assertRedirect(route('admin.home'));

        // one's own password is not set from here
        $this->actingAs($owner, 'admin')->get($this->adminUrl("/team/{$owner->id}/edit"))->assertDontSee('data-member-password', false);
        $this->post($this->adminUrl("/team/{$owner->id}"), ['name' => 'Owner', 'email' => 'owner@egweb.test', 'role' => 'owner', 'is_active' => 1, 'password' => 'Owner-new-2026']);
        $this->assertTrue(Hash::check('Secret-pass-123', $owner->fresh()->password));
    }

    public function test_an_owner_lets_a_sales_member_work_without_two_step_verification_but_never_an_owner(): void
    {
        config(['eghub.admin_require_2fa' => true]);
        $owner = $this->platformOwner();
        $owner->forceFill(['two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_confirmed_at' => now()])->save();
        $member = PlatformAdmin::query()->create(['name' => 'Ganna', 'email' => 'ganna@egweb.test', 'password' => 'Secret-pass-123', 'role' => 'sales']);
        $fields = ['name' => 'Ganna', 'email' => 'ganna@egweb.test', 'role' => 'sales', 'is_active' => 1];

        // required by default: only the security page opens
        $this->actingAs($member, 'admin')->get($this->adminUrl('/crm'))->assertRedirect(route('admin.security'));

        $this->actingAs($owner, 'admin')->get($this->adminUrl("/team/{$member->id}/edit"))->assertSee('data-two-factor-optional', false);
        $this->post($this->adminUrl("/team/{$member->id}"), $fields + ['two_factor_optional' => 1])->assertRedirect(route('admin.admins'));
        $this->assertTrue($member->fresh()->two_factor_optional);
        $this->actingAs($member->fresh(), 'admin')->get($this->adminUrl('/crm'))->assertOk();

        // a non-owner with the team permission cannot switch it, and an owner role never has it
        $lead = PlatformAdmin::query()->create(['name' => 'Lead', 'email' => 'lead@egweb.test', 'password' => 'Secret-pass-123', 'role' => 'manager', 'permissions' => ['admins.manage'], 'two_factor_optional' => true]);
        $this->actingAs($lead, 'admin')->get($this->adminUrl("/team/{$member->id}/edit"))->assertDontSee('data-two-factor-optional', false);
        $this->post($this->adminUrl("/team/{$member->id}"), $fields + ['two_factor_optional' => 0]);
        $this->assertTrue($member->fresh()->two_factor_optional);
        $second = PlatformAdmin::query()->create(['name' => 'Second', 'email' => 'second@egweb.test', 'password' => 'Secret-pass-123', 'role' => 'owner', 'two_factor_optional' => true]);
        $this->actingAs($second, 'admin')->get($this->adminUrl('/crm'))->assertRedirect(route('admin.security'));
    }

    public function test_switching_a_member_off_blocks_them(): void
    {
        $owner = $this->platformOwner();
        $member = PlatformAdmin::query()->create(['name' => 'Ali', 'email' => 'ali@egweb.test', 'password' => 'Secret-pass-123', 'role' => 'content']);

        $this->actingAs($owner, 'admin')->post($this->adminUrl("/team/{$member->id}"), [
            'name' => 'Ali', 'email' => 'ali@egweb.test', 'role' => 'content', 'is_active' => 0,
        ])->assertRedirect(route('admin.admins'));

        $this->assertFalse($member->fresh()->is_active);
        $this->assertFalse($member->fresh()->allows('content.manage'));
        $this->actingAs($member->fresh(), 'admin')->get($this->adminUrl('/blog'))->assertRedirect(route('admin.login'));
    }
}
