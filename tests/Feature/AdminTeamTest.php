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
