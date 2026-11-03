<?php

namespace Tests\Feature;

use App\Billing\Billing;
use App\Models\DiningTable;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Orders\ShiftService;
use App\Services\Settings;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TenantTestCase;

/** Waiters take the money before the order goes to the kitchen (setting), hold the cash and hand it in to the cashier. */
class WaiterPayFirstTest extends TenantTestCase
{
    private Tenant $tenant;

    private MenuItem $latte;

    private DiningTable $table;

    private User $waiter;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->restaurant('cafe-a', 'basic');
        app(Billing::class)->setAddon($this->tenant, 'pos_pack', 1);
        $this->latte = $this->item($this->tenant, 'Latte', 60);
        $this->table = $this->as($this->tenant, fn () => DiningTable::query()->firstOrFail());
        $this->waiter = $this->staff($this->tenant, 'waiter', 'waiter@cafe-a.test');
        $this->cashier = $this->staff($this->tenant, 'cashier', 'cashier@cafe-a.test');
        $this->as($this->tenant, fn () => Settings::put(['pos_waiter_pay_first' => '1']));
    }

    private function order(User $by, ?string $pay = null, array $payments = []): TestResponse
    {
        return $this->actingAs($by)->postJson($this->dashUrl('/pos/orders'), [
            'client_uuid' => (string) Str::uuid(), 'type' => 'dine_in', 'table_id' => $this->table->id,
            'items' => [['item_id' => $this->latte->id, 'qty' => 2]], 'pay' => $pay, 'payments' => $payments,
        ]);
    }

    public function test_a_waiter_order_reaches_the_kitchen_only_paid_and_the_cash_stays_with_the_waiter(): void
    {
        $this->order($this->waiter)->assertStatus(422)->assertJsonPath('message', 'حصّل الفلوس الأول: الطلب بيدخل المطبخ بعد الدفع.');
        $this->assertSame(0, $this->as($this->tenant, fn () => Order::query()->count()));

        $this->actingAs($this->waiter)->get($this->dashUrl('/pos'))->assertOk()
            ->assertSee('data-pay-first', false)->assertDontSee('data-send>', false)->assertDontSee('افتح وردية عشان تحصّل');
        $this->order($this->waiter, 'cash')->assertOk()->assertJsonPath('paid', true);

        $order = $this->as($this->tenant, fn () => Order::query()->firstOrFail());
        $this->assertSame(['paid', 'cash', 120.0, null, $this->waiter->id], [$order->payment_status, $order->payment_method, $order->cash_collected, $order->shift_id, $order->created_by]);

        // the cashier still opens a table and pays at the end
        $this->order($this->cashier)->assertOk()->assertJsonPath('paid', false);
    }

    public function test_the_cashier_takes_the_waiters_cash_into_the_open_shift(): void
    {
        $this->order($this->waiter, 'cash')->assertOk();
        $this->order($this->waiter, 'split', [['method' => 'cash', 'amount' => 20], ['method' => 'card', 'amount' => 100]])->assertOk();
        $this->actingAs($this->cashier)->post($this->dashUrl('/shifts/open'), ['opening_cash' => 200])->assertSessionHasNoErrors();
        $shift = $this->as($this->tenant, fn () => Shift::query()->firstOrFail());
        $this->assertSame(200.0, $this->as($this->tenant, fn () => app(ShiftService::class)->summary($shift)['expected_cash']));

        $this->actingAs($this->cashier)->get($this->dashUrl('/shifts'))->assertOk()
            ->assertSee('data-waiter-cash', false)->assertSee('data-waiter="'.$this->waiter->id.'"', false)->assertSee('140');
        $this->actingAs($this->cashier)->post($this->dashUrl("/shifts/waiters/{$this->waiter->id}/settle"))->assertSessionHasNoErrors();

        $summary = $this->as($this->tenant, fn () => app(ShiftService::class)->summary($shift));
        $this->assertSame([340.0, 140.0, 100.0], [$summary['expected_cash'], $summary['by_method']['cash'], $summary['by_method']['card']]);
        $this->assertSame([$this->cashier->id], $this->as($this->tenant, fn () => Order::query()->pluck('cash_settled_by')->unique()->values()->all()));

        // handed in once
        $this->actingAs($this->cashier)->post($this->dashUrl("/shifts/waiters/{$this->waiter->id}/settle"))->assertSessionHasErrors('shift');
        $this->actingAs($this->cashier)->get($this->dashUrl('/shifts'))->assertOk()->assertDontSee('data-waiter-cash', false);
    }

    public function test_a_card_payment_holds_no_cash_and_joins_the_open_shift(): void
    {
        $this->actingAs($this->cashier)->post($this->dashUrl('/shifts/open'), ['opening_cash' => 0])->assertSessionHasNoErrors();
        $this->order($this->waiter, 'card')->assertOk()->assertJsonPath('paid', true);

        $order = $this->as($this->tenant, fn () => Order::query()->firstOrFail());
        $shift = $this->as($this->tenant, fn () => Shift::query()->firstOrFail());
        $this->assertSame([null, $shift->id], [$order->cash_collected, $order->shift_id]);
        $this->actingAs($this->cashier)->get($this->dashUrl('/shifts'))->assertOk()->assertDontSee('data-waiter-cash', false);
    }

    public function test_with_the_setting_off_a_waiter_sends_unpaid_orders(): void
    {
        $manager = $this->staff($this->tenant, 'manager', 'manager@cafe-a.test');
        $this->actingAs($manager)->get($this->dashUrl('/pos/terminals'))->assertOk()->assertSee('data-waiter-payment', false);
        $this->actingAs($manager)->post($this->dashUrl('/pos/terminals/waiter-payment'))->assertSessionHasNoErrors();
        $this->assertSame('0', $this->as($this->tenant, fn () => Settings::get('pos_waiter_pay_first')));

        $this->order($this->waiter)->assertOk()->assertJsonPath('paid', false);
        $this->actingAs($this->waiter)->get($this->dashUrl('/pos'))->assertOk()->assertSee('data-send>', false);
        $this->actingAs($this->waiter)->post($this->dashUrl('/pos/terminals/waiter-payment'), ['pos_waiter_pay_first' => 1])->assertForbidden();
    }

    public function test_a_waiter_of_another_restaurant_is_not_found(): void
    {
        $other = $this->restaurant('cafe-b', 'basic');
        $stranger = $this->staff($other, 'waiter', 'waiter@cafe-b.test');
        $this->actingAs($this->cashier)->post($this->dashUrl('/shifts/open'), ['opening_cash' => 0])->assertSessionHasNoErrors();

        $this->actingAs($this->cashier)->post($this->dashUrl("/shifts/waiters/{$stranger->id}/settle"))->assertNotFound();
    }
}
