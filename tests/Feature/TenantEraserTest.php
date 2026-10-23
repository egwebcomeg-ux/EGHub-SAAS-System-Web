<?php

namespace Tests\Feature;

use App\Billing\Billing;
use App\Models\Branch;
use App\Models\DeliveryZone;
use App\Models\Invoice;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Plan;
use App\Models\PlatformAdmin;
use App\Models\PlatformAuditLog;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Orders\OrderService;
use App\Services\TenantEraser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TenantTestCase;

/** "Delete restaurant permanently" in the super admin: every row and folder of that restaurant, nothing of another. */
class TenantEraserTest extends TenantTestCase
{
    private function admin(string $role = 'owner'): PlatformAdmin
    {
        return PlatformAdmin::query()->firstOrCreate(['email' => "$role@egmenu.test"], ['name' => ucfirst($role), 'password' => 'Secret-pass-123', 'role' => $role]);
    }

    /** A restaurant with two branches, a sub-zone, delivery and takeaway orders, staff with roles and uploads. */
    private function seeded(string $slug): Tenant
    {
        $tenant = $this->restaurant($slug, 'full');
        $item = $this->item($tenant, 'Latte', 60);
        $this->staff($tenant, 'cashier', "c@$slug.test");
        $this->as($tenant, function () use ($item) {
            $second = Branch::query()->create(['name' => 'Zamalek', 'slug' => 'zamalek']);
            $zone = DeliveryZone::query()->create(['branch_id' => $second->id, 'name' => 'Maadi', 'fee' => 20, 'is_active' => true]);
            DeliveryZone::query()->create(['branch_id' => $second->id, 'name' => 'Road 9', 'parent_id' => $zone->id]);
            foreach ([Branch::query()->first(), $second] as $branch) {
                app(OrderService::class)->place(['items' => [['item_id' => $item->id, 'qty' => 2]], 'source' => 'pos', 'type' => 'takeaway'], $branch);
            }
            app(OrderService::class)->place(['items' => [['item_id' => $item->id, 'qty' => 1]], 'source' => 'pos', 'type' => 'delivery',
                'delivery_zone' => $zone, 'delivery_address' => ['zone' => 'Maadi', 'text' => 'x'], 'customer_phone' => '01012345678'], $second);
        });
        Storage::disk('public')->put("tenants/$slug/items/a.webp", 'x');
        Storage::disk('local')->put("backups/$slug/b.zip", 'x');

        return $tenant;
    }

    public function test_the_platform_owner_deletes_a_restaurant_and_everything_of_it_only(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $gone = $this->seeded('cafe-a');
        $kept = $this->seeded('cafe-b');
        $goneUsers = User::query()->withoutGlobalScopes()->where('tenant_id', 'cafe-a')->pluck('id');
        // a plain forceDelete() of the tenant is what failed before (orders → branches has no cascade)
        $this->assertSame(3, DB::table('orders')->where('tenant_id', 'cafe-a')->count());

        $this->actingAs($this->admin(), 'admin')->get($this->adminUrl('/tenants/cafe-a'))->assertOk()->assertSee('data-tenant-delete', false);
        // the slug must be typed exactly
        $this->actingAs($this->admin(), 'admin')->post($this->adminUrl('/tenants/cafe-a/delete'), ['confirm_slug' => 'cafe'])
            ->assertSessionHasErrors('confirm_slug');
        $this->assertNotNull(Tenant::query()->find('cafe-a'));

        $this->actingAs($this->admin(), 'admin')->post($this->adminUrl('/tenants/cafe-a/delete'), ['confirm_slug' => 'cafe-a'])
            ->assertRedirect(route('admin.tenants'))->assertSessionHas('ok');

        $this->assertNull(Tenant::withTrashed()->find('cafe-a'));
        foreach (app(TenantEraser::class)->tenantTables() as $table) {
            $this->assertSame(0, DB::table($table)->where('tenant_id', 'cafe-a')->count(), $table);
        }
        $this->assertSame(0, DB::table('model_has_roles')->whereIn('model_id', $goneUsers)->count());
        $this->assertFalse(Storage::disk('public')->exists('tenants/cafe-a'));
        $this->assertFalse(Storage::disk('local')->exists('backups/cafe-a'));

        // the other restaurant is untouched
        $this->assertNotNull(Tenant::query()->find('cafe-b'));
        $this->assertSame(3, DB::table('orders')->where('tenant_id', 'cafe-b')->count());
        $this->assertSame(2, DB::table('branches')->where('tenant_id', 'cafe-b')->count());
        $this->assertSame(2, $this->as($kept, fn () => DeliveryZone::query()->count()));
        $this->assertSame(1, $this->as($kept, fn () => MenuItem::query()->count()));
        $this->assertSame(2, User::query()->withoutGlobalScopes()->where('tenant_id', 'cafe-b')->count());
        $this->assertTrue(Storage::disk('public')->exists('tenants/cafe-b/items/a.webp'));
        $this->assertTrue(Storage::disk('local')->exists('backups/cafe-b/b.zip'));
        $this->assertSame(3, $this->as($kept, fn () => Order::query()->count()));

        // recorded on the platform side
        $log = PlatformAuditLog::query()->where('action', 'tenant.deleted')->sole();
        $this->assertSame('cafe-a', $log->subject);
        $this->assertSame($this->admin()->id, $log->admin_id);
        $this->assertFalse($log->meta['forced']);
    }

    public function test_a_recent_payment_needs_delete_anyway_and_only_the_owner_may_delete(): void
    {
        $tenant = $this->seeded('cafe-a');
        $billing = app(Billing::class);
        $invoice = $billing->invoiceForPlan($tenant, Plan::query()->where('code', 'full')->firstOrFail(), 1);
        $billing->markPaid($invoice, 'instapay', 'ref', $invoice->total, 'pay-'.$invoice->number);

        // a manager admin (tenants.manage) cannot
        $this->actingAs($this->admin('manager'), 'admin')->post($this->adminUrl('/tenants/cafe-a/delete'), ['confirm_slug' => 'cafe-a'])
            ->assertForbidden();

        $this->actingAs($this->admin(), 'admin')->post($this->adminUrl('/tenants/cafe-a/delete'), ['confirm_slug' => 'cafe-a'])
            ->assertSessionHasErrors('delete');
        $this->assertNotNull(Tenant::query()->find('cafe-a'));

        $this->actingAs($this->admin(), 'admin')->post($this->adminUrl('/tenants/cafe-a/delete'), ['confirm_slug' => 'cafe-a', 'force' => '1'])
            ->assertRedirect(route('admin.tenants'));
        $this->assertNull(Tenant::withTrashed()->find('cafe-a'));
        $this->assertSame(0, Invoice::query()->where('tenant_id', 'cafe-a')->count());
        $this->assertTrue(PlatformAuditLog::query()->where('action', 'tenant.deleted')->sole()->meta['forced']);
    }
}
