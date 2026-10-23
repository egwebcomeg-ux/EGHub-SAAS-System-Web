<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Permissions;
use Tests\TenantTestCase;

/**
 * Custom staff permissions (the `staff_roles` plan feature): the owner adds permissions to a staff member on top of
 * their role, never the owner-only ones, and unticking them puts the member back on the role's defaults.
 */
class StaffPermissionsTest extends TenantTestCase
{
    /** @param  list<string>  $permissions */
    private function saveStaff(Tenant $tenant, User $member, array $permissions, string $role = 'cashier')
    {
        return $this->actingAs($this->owner($tenant))->post($this->dashUrl("/users/{$member->id}"), [
            'name' => $member->name, 'email' => $member->email, 'role' => $role, 'is_active' => '1', 'permissions' => $permissions,
        ]);
    }

    /** @return list<string> */
    private function directPermissions(Tenant $tenant, User $member): array
    {
        return $this->as($tenant, fn () => $member->fresh()->permissions->pluck('name')->sort()->values()->all());
    }

    public function test_the_owner_grants_extra_permissions_on_top_of_the_role_when_the_plan_has_the_feature(): void
    {
        $a = $this->restaurant('cafe-a', 'full');
        $cashier = $this->staff($a, 'cashier', 'c@cafe-a.test');
        $this->assertFalse($cashier->can('reports.view'));

        $page = $this->actingAs($this->owner($a))->get($this->dashUrl('/users'))->assertOk()
            ->assertSee('data-extra-permissions', false)->assertSee('data-role-info', false)
            ->assertSee(Permissions::roleHint('cashier'))->assertDontSee('data-permissions-locked', false);
        $page->assertSee('name="permissions[]" value="reports.view"', false)
            ->assertDontSee('name="permissions[]" value="billing.manage"', false)
            ->assertDontSee('name="permissions[]" value="users.manage"', false);

        // a permission of the role itself is posted too (a ticked, locked box): only the extra one is stored
        $this->saveStaff($a, $cashier, ['reports.view', 'pos.use'])->assertSessionHasNoErrors();

        $this->assertSame(['reports.view'], $this->directPermissions($a, $cashier));
        $fresh = $this->as($a, fn () => User::query()->findOrFail($cashier->id));
        $this->assertTrue($fresh->can('reports.view'));
        $this->assertTrue($fresh->can('pos.use'));
        $this->assertTrue($fresh->hasRole('cashier'));
        $this->actingAs($fresh)->get($this->dashUrl('/reports'))->assertOk();

        $log = $this->as($a, fn () => AuditLog::query()->where('action', 'user.permissions')->latest('id')->first());
        $this->assertNotNull($log);
        $this->assertSame(['reports.view'], $log->meta['added']);
        $this->assertSame([], $log->meta['removed']);
        $this->assertSame($this->owner($a)->id, $log->user_id);

        $this->actingAs($this->owner($a))->get($this->dashUrl('/users'))->assertOk()->assertSee('data-extra-badge', false)->assertSee('+1');
    }

    public function test_a_new_member_can_be_added_with_extra_permissions_in_one_go(): void
    {
        $a = $this->restaurant('cafe-a', 'full');
        $this->actingAs($this->owner($a))->post($this->dashUrl('/users'), [
            'name' => 'Nour', 'email' => 'nour@cafe-a.test', 'role' => 'kitchen', 'password' => 'Secret-pass-123', 'permissions' => ['orders.view'],
        ])->assertSessionHasNoErrors();

        $nour = $this->as($a, fn () => User::query()->where('email', 'nour@cafe-a.test')->firstOrFail());
        $this->assertTrue($nour->hasRole('kitchen'));
        $this->assertTrue($nour->can('orders.view'));
        $this->assertSame(['orders.view'], $this->directPermissions($a, $nour));
    }

    public function test_switching_the_role_in_the_form_never_saves_the_previous_roles_boxes_as_extras(): void
    {
        $a = $this->restaurant('cafe-a', 'full');
        $this->actingAs($this->owner($a))->get($this->dashUrl('/users'))->assertOk()
            ->assertSee('name="permissions_role" value="cashier"', false)->assertSee("box.dataset.own === '1'", false);

        // the add form opened on cashier (its boxes ticked and locked), then switched to driver: the cashier boxes
        // come along as posted for the role they were drawn for
        $this->actingAs($this->owner($a))->post($this->dashUrl('/users'), [
            'name' => 'Sayed', 'phone' => '01055555555', 'role' => 'driver', 'password' => 'Secret-pass-123',
            'permissions' => Permissions::of('cashier'), 'permissions_role' => 'cashier',
        ])->assertSessionHasNoErrors();

        $driver = $this->as($a, fn () => User::query()->where('phone', '01055555555')->firstOrFail());
        $this->assertTrue($driver->hasRole('driver'));
        $this->assertSame([], $this->directPermissions($a, $driver));

        // an extra really ticked for the driver (the boxes drawn for the driver role) is kept
        $this->actingAs($this->owner($a))->post($this->dashUrl("/users/{$driver->id}"), [
            'name' => 'Sayed', 'phone' => '01055555555', 'role' => 'driver', 'is_active' => '1',
            'permissions' => ['orders.view'], 'permissions_role' => 'driver',
        ])->assertSessionHasNoErrors();
        $this->assertSame(['orders.view'], $this->directPermissions($a, $driver));
    }

    public function test_without_the_feature_the_extra_permissions_are_hidden_and_ignored(): void
    {
        $a = $this->restaurant('cafe-a', 'pro');
        $cashier = $this->staff($a, 'cashier', 'c@cafe-a.test');

        $this->actingAs($this->owner($a))->get($this->dashUrl('/users'))->assertOk()
            ->assertDontSee('name="permissions[]"', false)->assertSee('data-permissions-locked', false)
            ->assertSee(Permissions::roleHint('cashier'));

        $this->saveStaff($a, $cashier, ['reports.view'])->assertSessionHasNoErrors();

        $this->assertSame([], $this->directPermissions($a, $cashier));
        $this->assertFalse($this->as($a, fn () => User::query()->findOrFail($cashier->id))->can('reports.view'));
        $this->assertSame(0, $this->as($a, fn () => AuditLog::query()->where('action', 'user.permissions')->count()));
    }

    public function test_owner_only_permissions_are_never_granted_to_staff(): void
    {
        $a = $this->restaurant('cafe-a', 'full');
        $cashier = $this->staff($a, 'cashier', 'c@cafe-a.test');

        foreach (Permissions::OWNER_ONLY as $forbidden) {
            $this->saveStaff($a, $cashier, [$forbidden])->assertSessionHasErrors('permissions.0');
        }
        $this->saveStaff($a, $cashier, ['not.a.permission'])->assertSessionHasErrors('permissions.0');

        $this->assertSame([], $this->directPermissions($a, $cashier));
        $fresh = $this->as($a, fn () => User::query()->findOrFail($cashier->id));
        $this->assertFalse($fresh->can('billing.manage'));
        $this->assertFalse($fresh->can('users.manage'));
        $this->assertSame([], array_intersect(Permissions::grantable(), Permissions::OWNER_ONLY));
    }

    public function test_the_owner_role_keeps_no_extras_and_revoking_returns_the_member_to_the_role_defaults(): void
    {
        $a = $this->restaurant('cafe-a', 'full');
        $waiter = $this->staff($a, 'waiter', 'w@cafe-a.test');

        $this->saveStaff($a, $waiter, ['reports.view', 'customers.view'], 'waiter')->assertSessionHasNoErrors();
        $this->assertSame(['customers.view', 'reports.view'], $this->directPermissions($a, $waiter));

        // unticked: back to what a waiter has
        $this->actingAs($this->owner($a))->post($this->dashUrl("/users/{$waiter->id}"), [
            'name' => $waiter->name, 'email' => $waiter->email, 'role' => 'waiter', 'is_active' => '1',
        ])->assertSessionHasNoErrors();
        $this->assertSame([], $this->directPermissions($a, $waiter));
        $fresh = $this->as($a, fn () => User::query()->findOrFail($waiter->id));
        $this->assertFalse($fresh->can('reports.view'));
        $this->assertTrue($fresh->can('orders.view'));
        $this->assertSame(collect(Permissions::of('waiter'))->sort()->values()->all(), $fresh->getAllPermissions()->pluck('name')->sort()->values()->all());
        $log = $this->as($a, fn () => AuditLog::query()->where('action', 'user.permissions')->latest('id')->first());
        $this->assertSame(['customers.view', 'reports.view'], $log->meta['removed']);

        // promoted to owner: everything comes from the role, nothing is stored as an extra
        $this->saveStaff($a, $waiter, ['reports.view'], 'owner')->assertSessionHasNoErrors();
        $this->assertSame([], $this->directPermissions($a, $waiter));
        $this->assertTrue($this->as($a, fn () => User::query()->findOrFail($waiter->id))->can('billing.manage'));
    }

    public function test_extra_permissions_never_cross_restaurants(): void
    {
        $a = $this->restaurant('cafe-a', 'full');
        $b = $this->restaurant('cafe-b', 'full');
        $cashierB = $this->staff($b, 'cashier', 'c@cafe-b.test');

        $this->actingAs($this->owner($a))->post($this->dashUrl("/users/{$cashierB->id}"), [
            'name' => 'Hijacked', 'email' => $cashierB->email, 'role' => 'cashier', 'is_active' => '1', 'permissions' => ['reports.view'],
        ])->assertNotFound();

        $this->assertSame([], $this->directPermissions($b, $cashierB));
        $this->assertSame('Cashier', $this->as($b, fn () => $cashierB->fresh()->name));
        // a member of B granted extras is not visible on A's team page
        $this->saveStaff($b, $cashierB, ['reports.view'])->assertSessionHasNoErrors();
        $this->actingAs($this->owner($a))->get($this->dashUrl('/users'))->assertOk()->assertDontSee('c@cafe-b.test');
    }

    public function test_a_driver_account_needs_delivery_in_the_plan(): void
    {
        $basic = $this->restaurant('cafe-basic', 'basic');
        $this->actingAs($this->owner($basic))->post($this->dashUrl('/users'), [
            'name' => 'Karim', 'phone' => '01111111111', 'role' => 'driver', 'password' => 'Secret-pass-123',
        ])->assertSessionHasErrors('role');
        $this->assertFalse($this->as($basic, fn () => User::query()->where('phone', '01111111111')->exists()));

        $pro = $this->restaurant('cafe-pro', 'pro');
        $this->actingAs($this->owner($pro))->post($this->dashUrl('/users'), [
            'name' => 'Karim', 'phone' => '01111111112', 'role' => 'driver', 'password' => 'Secret-pass-123',
        ])->assertSessionHasNoErrors();
        $this->assertTrue($this->as($pro, fn () => User::query()->where('phone', '01111111112')->firstOrFail())->hasRole('driver'));
    }
}
