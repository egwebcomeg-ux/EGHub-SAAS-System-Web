<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Order;
use App\Models\PlatformAdmin;
use App\Models\Tenant;
use Tests\TenantTestCase;

/** The past orders of a restaurant, and the admin's filter of paying places with no recent orders. */
class OrderHistoryTest extends TenantTestCase
{
    private function order(Tenant $tenant, int $number, array $extra = [], ?string $at = null): Order
    {
        return $this->as($tenant, function () use ($number, $extra, $at) {
            $order = Order::query()->create($extra + ['branch_id' => Branch::query()->value('id'), 'number' => $number, 'status' => 'served', 'total' => 100]);
            if ($at) {
                $order->forceFill(['created_at' => $at])->save();
            }

            return $order;
        });
    }

    /** @return list<int> */
    private function numbers(string $query = ''): array
    {
        $response = $this->get($this->dashUrl('/orders/history'.$query))->assertOk();

        return collect($response->viewData('orders')->items())->pluck('number')->sort()->values()->all();
    }

    public function test_finds_an_old_order_by_number_name_mobile_status_and_date(): void
    {
        $tenant = $this->restaurant('cafe-a');
        $this->order($tenant, 7, ['customer_name' => 'Mona', 'customer_phone' => '01012345678'], now()->subMonths(2)->toDateTimeString());
        $this->order($tenant, 8, ['customer_name' => 'Karim', 'status' => 'cancelled']);
        $this->actingAs($this->owner($tenant));

        $this->assertSame([7, 8], $this->numbers());
        $this->assertSame([7], $this->numbers('?q=7'));
        $this->assertSame([7], $this->numbers('?q=Mona'));
        $this->assertSame([7], $this->numbers('?q=01012345678'));
        $this->assertSame([8], $this->numbers('?status=cancelled'));
        $this->assertSame([7], $this->numbers('?to='.now()->subMonth()->toDateString()));
        $this->assertSame([8], $this->numbers('?from='.now()->subDay()->toDateString()));
    }

    public function test_another_restaurants_and_another_branchs_orders_stay_out(): void
    {
        $tenant = $this->restaurant('cafe-a');
        $this->order($this->restaurant('cafe-b'), 50);
        $this->order($tenant, 1);
        $other = $this->as($tenant, fn () => Branch::query()->create(['name' => 'Other', 'slug' => 'other'])->id);
        $this->order($tenant, 2, ['branch_id' => $other]);
        $this->actingAs($this->owner($tenant));
        $this->assertSame([1, 2], $this->numbers());

        $staff = $this->staff($tenant, 'cashier', 'cashier@cafe.test');
        $staff->forceFill(['branch_id' => $other])->save();
        $this->actingAs($staff);
        $this->assertSame([2], $this->numbers());
    }

    public function test_staff_without_orders_access_are_refused(): void
    {
        $tenant = $this->restaurant('cafe-a');
        $this->actingAs($this->staff($tenant, 'kitchen', 'kitchen@cafe.test'))->get($this->dashUrl('/orders/history'))->assertForbidden();
    }

    public function test_the_quiet_filter_lists_paying_places_without_recent_orders(): void
    {
        $quiet = $this->restaurant('quiet-a', 'pro');
        $busy = $this->restaurant('busy-a', 'pro');
        $this->restaurant('free-a', 'free');
        $this->order($quiet, 1, [], now()->subDays(20)->toDateTimeString());
        $this->order($busy, 1);
        $admin = PlatformAdmin::query()->create(['name' => 'Support', 'email' => 'support@egweb.test', 'password' => 'Secret-pass-123', 'role' => 'support']);

        $listed = fn (string $days) => collect($this->actingAs($admin, 'admin')->get($this->adminUrl('/tenants?quiet='.$days))->assertOk()->viewData('tenants')->items())->pluck('id')->all();

        $this->assertSame(['quiet-a'], $listed('14'));
        $this->assertSame([], $listed('30'));
    }
}
