<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Orders\OrderService;
use Carbon\CarbonImmutable;
use Tests\TenantTestCase;

/**
 * The number an order is called by starts again at 1 with every shift of the branch (or every day without shifts),
 * carries on while shifts overlap, and each branch counts on its own.
 */
class OrderNumbersTest extends TenantTestCase
{
    private Tenant $tenant;

    private MenuItem $tea;

    private User $cashier;

    private function shop(string $plan): void
    {
        $this->tenant = $this->restaurant('cafe-n', $plan);
        $this->tea = $this->item($this->tenant, 'Tea', 20);
        $this->cashier = $this->staff($this->tenant, 'cashier', 'cashier@cafe-n.test');
    }

    private function sell(?Branch $branch = null): int
    {
        return $this->as($this->tenant, fn () => app(OrderService::class)->place([
            'items' => [['item_id' => $this->tea->id, 'qty' => 1]], 'source' => 'pos', 'type' => 'takeaway',
        ], $branch ?? Branch::query()->first())->number);
    }

    private function openShift(): Shift
    {
        return $this->as($this->tenant, fn () => Shift::query()->create([
            'branch_id' => Branch::query()->value('id'), 'user_id' => $this->cashier->id, 'opened_at' => now(),
        ]));
    }

    private function close(Shift $shift): void
    {
        $this->as($this->tenant, fn () => $shift->update(['closed_at' => now()]));
    }

    public function test_the_number_starts_again_with_every_shift_and_carries_on_while_shifts_overlap(): void
    {
        $this->shop('enterprise');
        $this->travelTo(CarbonImmutable::parse('2026-11-10 08:00', 'Africa/Cairo'));
        $morning = $this->openShift();
        $this->travel(5)->minutes();
        $this->assertSame([1, 2], [$this->sell(), $this->sell()]);

        // the evening cashier opens before the morning one closes: the count carries on
        $this->travel(7)->hours();
        $evening = $this->openShift();
        $this->travel(5)->minutes();
        $this->assertSame(3, $this->sell());
        $this->close($morning);
        $this->travel(5)->minutes();
        $this->assertSame(4, $this->sell());

        // the next shift opens with none open: back to 1, while another branch counts on its own
        $this->travel(6)->hours();
        $this->close($evening);
        $this->travel(1)->hours();
        $this->openShift();
        $this->travel(1)->minutes();
        $other = $this->as($this->tenant, fn () => Branch::query()->create(['name' => 'Second', 'slug' => 'second', 'is_active' => true]));
        $this->assertSame([1, 2, 1], [$this->sell(), $this->sell(), $this->sell($other)]);
        $this->assertSame(3, $this->as($this->tenant, fn () => Order::query()->where('number', 1)->count())); // the first shift's #1 stays
    }

    public function test_without_shifts_the_number_starts_again_every_day_in_cairo(): void
    {
        $this->shop('free');
        $this->travelTo(CarbonImmutable::parse('2026-11-10 23:50', 'Africa/Cairo'));
        $this->assertSame([1, 2], [$this->sell(), $this->sell()]);
        $this->travel(15)->minutes(); // past midnight in Cairo
        $this->assertSame(1, $this->sell());
    }
}
