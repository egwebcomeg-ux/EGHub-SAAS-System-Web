<?php

namespace Tests\Feature;

use App\Billing\Billing;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Ingredient;
use App\Models\IngredientStock;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderRefund;
use App\Models\RecipeLine;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Orders\OrderService;
use App\Services\Orders\ShiftService;
use App\Services\Reports\SalesReport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TenantTestCase;

/**
 * Refunds / returns at the POS: whole order or some lines, a manager's approval (signed in, or their PIN on the
 * cashier's device), the money out of the shift and the day's report, stock back, the guest's balance back, audit,
 * the refund slip, and isolation.
 */
class PosRefundTest extends TenantTestCase
{
    private Tenant $tenant;

    private MenuItem $latte;

    private User $cashier;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->restaurant('cafe-a', 'pro');
        foreach (['pos_pack', 'inventory_pack', 'wallet_pack'] as $pack) {
            app(Billing::class)->setAddon($this->tenant, $pack, 1);
        }
        $this->latte = $this->item($this->tenant, 'Latte', 60);
        $this->cashier = $this->staff($this->tenant, 'cashier', 'cashier@cafe-a.test');
        $this->manager = $this->staff($this->tenant, 'manager', 'manager@cafe-a.test');
        $this->actingAs($this->cashier)->post($this->dashUrl('/shifts/open'), ['opening_cash' => 100])->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->post($this->dashUrl('/shifts/open'), ['opening_cash' => 0])->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->post($this->dashUrl('/security/pin'), ['pin' => '2468', 'pin_confirmation' => '2468', 'current_password' => 'Secret-pass-123'])
            ->assertSessionHasNoErrors();
    }

    private function sell(int $qty, string $pay = 'cash', array $extra = []): Order
    {
        $id = $this->actingAs($this->cashier)->postJson($this->dashUrl('/pos/orders'), $extra + [
            'client_uuid' => (string) Str::uuid(), 'type' => 'takeaway', 'items' => [['item_id' => $this->latte->id, 'qty' => $qty]], 'pay' => $pay,
        ])->assertOk()->assertJsonPath('paid', true)->json('id');

        return $this->order($id);
    }

    private function order(int $id): Order
    {
        return $this->as($this->tenant, fn () => Order::query()->with('items')->findOrFail($id));
    }

    private function refund(Order $order, array $body, ?User $by = null): TestResponse
    {
        return $this->actingAs($by ?? $this->cashier)->postJson($this->dashUrl("/pos/orders/{$order->id}/refund"), $body + ['reason' => 'wrong item']);
    }

    /** The shift of $by (the cashier by default): a refund leaves the shift of whoever gives the money back. */
    private function summary(?User $by = null): array
    {
        $id = ($by ?? $this->cashier)->id;

        return $this->as($this->tenant, fn () => app(ShiftService::class)->summary(Shift::query()->where('user_id', $id)->firstOrFail()));
    }

    public function test_a_partial_refund_needs_a_manager_and_comes_out_of_the_shift_and_the_report(): void
    {
        $order = $this->sell(3); // 180 cash
        $line = ['order_item_id' => $order->items[0]->id, 'qty' => 1];

        // the cashier alone, or with a wrong PIN: refused, nothing moves
        $this->refund($order, ['full' => false, 'lines' => [$line], 'method' => 'cash'])->assertStatus(422)
            ->assertJsonPath('message', __('A manager must approve: choose the manager and enter their PIN.'));
        $this->refund($order, ['full' => false, 'lines' => [$line], 'method' => 'cash', 'approver_id' => $this->manager->id, 'pin' => '1111'])->assertStatus(422);
        $this->assertTrue($this->as($this->tenant, fn () => AuditLog::query()->where('action', 'pos.pin_failed')->exists()));
        $this->assertSame(0, $this->as($this->tenant, fn () => OrderRefund::query()->count()));

        // with the manager's PIN on the cashier's device
        $r = $this->refund($order, ['full' => false, 'lines' => [$line], 'method' => 'cash', 'approver_id' => $this->manager->id, 'pin' => '2468'])
            ->assertOk()->assertJsonPath('amount', 60)->assertJsonPath('status', 'partial');
        $order = $this->order($order->id);
        $this->assertSame([60.0, 'partial', 1], [$order->refunded_amount, $order->refund_status, $order->items[0]->refunded_qty]);
        $refund = $this->as($this->tenant, fn () => OrderRefund::query()->firstOrFail());
        $this->assertSame([$this->manager->id, $this->cashier->id, ['cash' => 60]], [$refund->approved_by, $refund->created_by, $refund->methods]);
        $audit = $this->as($this->tenant, fn () => AuditLog::query()->where('action', 'pos.refund')->firstOrFail());
        $this->assertSame('Manager', $audit->meta['approved_by']);

        $summary = $this->summary();
        $this->assertSame([120.0, ['cash' => 60.0], 220.0], [$summary['by_method']['cash'], $summary['refunds'], $summary['expected_cash']]);
        $report = $this->as($this->tenant, fn () => (new SalesReport(CarbonImmutable::parse(local_today()), CarbonImmutable::parse(local_today())))->build());
        $this->assertSame([120.0, 60.0], [$report['revenue'], $report['refunds']['total']]);

        // no more than what is left of a line; the slip prints
        $this->refund($order, ['full' => false, 'lines' => [['order_item_id' => $order->items[0]->id, 'qty' => 3]], 'method' => 'cash'], $this->manager)->assertStatus(422);
        $this->actingAs($this->cashier)->get($r->json('receipt'))->assertOk()->assertSee('data-refund-receipt', false)->assertSee('مرتجع جزئي')->assertSee('wrong item');
        $this->actingAs($this->cashier)->get($this->dashUrl('/shifts'))->assertOk()->assertSee('data-shift-refund', false);
    }

    public function test_a_full_refund_by_a_signed_in_manager_gives_back_the_rest_restocks_and_blocks_a_second_one(): void
    {
        $milk = $this->as($this->tenant, function () {
            $milk = Ingredient::query()->create(['name' => 'Milk', 'unit' => 'ml']);
            RecipeLine::query()->create(['menu_item_id' => $this->latte->id, 'ingredient_id' => $milk->id, 'quantity' => 200]);

            return $milk;
        });
        $stock = fn () => (float) $this->as($this->tenant, fn () => IngredientStock::query()->where('ingredient_id', $milk->id)->sum('quantity'));
        $order = $this->sell(2, 'card');
        $this->assertSame(-400.0, $stock());

        // one latte back: its milk too
        $this->refund($order, ['full' => false, 'lines' => [['order_item_id' => $order->items[0]->id, 'qty' => 1]], 'method' => 'card'], $this->manager)->assertOk();
        $this->assertSame(-200.0, $stock());
        // then the rest of the order: the way it was paid, the rest of the stock
        $this->refund($order, ['full' => true], $this->manager)->assertOk()->assertJsonPath('amount', 60)->assertJsonPath('methods.card', 60)->assertJsonPath('status', 'full');
        $this->assertSame(0.0, $stock());
        $order = $this->order($order->id);
        $this->assertSame([120.0, 'full'], [$order->refunded_amount, $order->refund_status]);

        $this->refund($order, ['full' => true], $this->manager)->assertStatus(422);
        // a refunded order cannot be cancelled as well (the money would go back twice)
        $this->actingAs($this->manager)->postJson($this->dashUrl("/orders/{$order->id}/status"), ['status' => 'cancelled'])->assertStatus(422);
        // sold in the cashier's shift, given back from the manager's
        $this->assertSame(120.0, $this->summary()['by_method']['card']);
        $summary = $this->summary($this->manager);
        $this->assertSame([-120.0, ['card' => 120.0]], [$summary['by_method']['card'], $summary['refunds']]);
    }

    public function test_a_discounted_line_refunds_its_share_and_a_balance_payment_goes_back_to_the_balance(): void
    {
        $order = $this->as($this->tenant, function () {
            $o = app(OrderService::class)->place(['source' => 'pos', 'type' => 'takeaway', 'discount' => 20, 'items' => [['item_id' => $this->latte->id, 'qty' => 2]]],
                Branch::query()->firstOrFail(), null, $this->manager);

            return app(OrderService::class)->pay($o, 'cash', $this->cashier, Shift::query()->where('user_id', $this->cashier->id)->firstOrFail());
        });
        $this->refund($this->order($order->id), ['full' => false, 'lines' => [['order_item_id' => $this->order($order->id)->items[0]->id, 'qty' => 1]], 'method' => 'instapay'], $this->manager)
            ->assertOk()->assertJsonPath('amount', 50); // 100 paid for 2 → 50 each

        // paid from the guest's balance: a full refund puts it back on the balance
        $this->actingAs($this->cashier)->postJson($this->dashUrl('/wallet/topup'), ['phone' => '01012345678', 'amount' => 200, 'method' => 'cash'])->assertOk();
        $credit = $this->sell(2, 'credit', ['customer_phone' => '01012345678']);
        $balance = fn () => (float) $this->as($this->tenant, fn () => Customer::query()->where('phone', '01012345678')->value('credit_balance'));
        $this->assertSame(80.0, $balance());
        $this->refund($credit, ['full' => true], $this->manager)->assertOk()->assertJsonPath('methods.credit', 120);
        $this->assertSame(200.0, $balance());
    }

    public function test_unpaid_or_other_restaurants_orders_cannot_be_refunded(): void
    {
        $open = $this->actingAs($this->cashier)->postJson($this->dashUrl('/pos/orders'), [
            'client_uuid' => (string) Str::uuid(), 'type' => 'takeaway', 'items' => [['item_id' => $this->latte->id, 'qty' => 1]],
        ])->json('id');
        $this->refund($this->order($open), ['full' => true], $this->manager)->assertStatus(422)->assertJsonPath('message', 'المرتجع بيبقى على طلب مدفوع بس.');
        $this->refund($this->sell(1), ['full' => true, 'reason' => ''], $this->manager)->assertStatus(422);

        $paid = $this->sell(1);
        $b = $this->restaurant('cafe-b', 'pro');
        app(Billing::class)->setAddon($b, 'pos_pack', 1);
        $this->actingAs($this->owner($b))->postJson($this->dashUrl("/pos/orders/{$paid->id}/refund"), ['full' => true, 'reason' => 'steal'])->assertNotFound();
        $this->actingAs($this->owner($b))->getJson($this->dashUrl('/pos/orders/recent'))->assertOk()->assertJsonPath('orders', []);
        $this->assertSame(0, $this->as($this->tenant, fn () => OrderRefund::query()->count()));

        // the search finds an order by its number
        $this->actingAs($this->cashier)->getJson($this->dashUrl('/pos/orders/recent?q=%23'.$paid->number))->assertOk()
            ->assertJsonPath('orders.0.id', $paid->id)->assertJsonCount(1, 'orders');
    }
}
