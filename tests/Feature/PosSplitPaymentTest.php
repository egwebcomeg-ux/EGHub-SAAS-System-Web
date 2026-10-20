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
use App\Services\Reports\SalesReport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TenantTestCase;

/** Split payment at the POS: parts by several methods that add up to the order, change for cash, shifts / reports / receipts per method. */
class PosSplitPaymentTest extends TenantTestCase
{
    private Tenant $tenant;

    private MenuItem $latte;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->restaurant('cafe-a', 'basic');
        app(Billing::class)->setAddon($this->tenant, 'pos_pack', 1);
        $this->latte = $this->item($this->tenant, 'Latte', 60);
        $this->cashier = $this->staff($this->tenant, 'cashier', 'cashier@cafe-a.test');
        $this->actingAs($this->cashier)->post($this->dashUrl('/shifts/open'), ['opening_cash' => 200])->assertSessionHasNoErrors();
    }

    private function sell(int $qty, array $payments, string $pay = 'split'): TestResponse
    {
        return $this->actingAs($this->cashier)->postJson($this->dashUrl('/pos/orders'), [
            'client_uuid' => (string) Str::uuid(), 'type' => 'takeaway', 'items' => [['item_id' => $this->latte->id, 'qty' => $qty]],
            'pay' => $pay, 'payments' => $payments,
        ]);
    }

    public function test_one_order_paid_by_cash_and_card_counts_each_part_in_the_shift_and_reports(): void
    {
        $this->sell(2, [['method' => 'cash', 'amount' => 50, 'tendered' => 100], ['method' => 'card', 'amount' => 70]])->assertOk()->assertJsonPath('paid', true);
        $this->sell(1, [['method' => 'instapay', 'amount' => 60]], 'instapay')->assertOk();

        $order = $this->as($this->tenant, fn () => Order::query()->orderBy('id')->firstOrFail());
        $this->assertSame('split', $order->payment_method);
        $this->assertSame([
            ['method' => 'cash', 'amount' => 50, 'tendered' => 100, 'change' => 50],
            ['method' => 'card', 'amount' => 70],
        ], $order->payments);

        $shift = $this->as($this->tenant, fn () => Shift::query()->firstOrFail());
        $summary = $this->as($this->tenant, fn () => app(ShiftService::class)->summary($shift));
        $this->assertSame([50.0, 70.0, 60.0], [$summary['by_method']['cash'], $summary['by_method']['card'], $summary['by_method']['instapay']]);
        $this->assertSame(250.0, $summary['expected_cash']); // 200 + the cash part only
        $this->assertSame(180.0, $summary['total']);

        $report = $this->as($this->tenant, fn () => (new SalesReport(CarbonImmutable::today(), CarbonImmutable::today()))->build());
        $methods = collect($report['by_method'])->keyBy('key');
        $this->assertSame([50.0, 70.0, 60.0], [$methods['cash']['total'], $methods['card']['total'], $methods['instapay']['total']]);

        // the receipt lists the parts and the change
        $this->actingAs($this->cashier)->get($this->dashUrl("/orders/{$order->id}/receipt"))->assertOk()
            ->assertSee('data-receipt-payments', false)->assertSee('دفع مقسّم')->assertSee('الباقي 50');
        $this->actingAs($this->cashier)->get($this->dashUrl('/pos'))->assertOk()->assertSee('data-split-toggle', false);
    }

    public function test_parts_must_add_up_and_use_counter_methods_or_nothing_is_saved(): void
    {
        $this->sell(2, [['method' => 'cash', 'amount' => 50], ['method' => 'card', 'amount' => 60]])->assertStatus(422)
            ->assertJsonPath('message', 'الأجزاء مجموعها 110، والطلب محتاج 120.');
        $this->sell(2, [['method' => 'credit', 'amount' => 60], ['method' => 'card', 'amount' => 60]])->assertStatus(422);
        $this->sell(2, [['method' => 'cash', 'amount' => 0], ['method' => 'card', 'amount' => 120]])->assertStatus(422);
        $this->assertSame(0, $this->as($this->tenant, fn () => Order::query()->count()));
    }

    public function test_an_open_table_order_is_settled_by_split_payment(): void
    {
        $table = $this->as($this->tenant, fn () => DiningTable::query()->firstOrFail());
        $id = $this->actingAs($this->cashier)->postJson($this->dashUrl('/pos/orders'), [
            'client_uuid' => (string) Str::uuid(), 'type' => 'dine_in', 'table_id' => $table->id, 'items' => [['item_id' => $this->latte->id, 'qty' => 3]],
        ])->assertOk()->assertJsonPath('paid', false)->json('id');

        $this->actingAs($this->cashier)->postJson($this->dashUrl("/pos/orders/$id/pay"), [
            'method' => 'split', 'payments' => [['method' => 'wallet', 'amount' => 100], ['method' => 'cash', 'amount' => 80, 'tendered' => 80]],
        ])->assertOk();
        $order = $this->as($this->tenant, fn () => Order::query()->findOrFail($id));
        $this->assertSame(['paid', 'split'], [$order->payment_status, $order->payment_method]);
        $this->assertSame(['wallet' => 100.0, 'cash' => 80.0], ShiftService::split($order));
        // "split" without its parts is refused
        $this->actingAs($this->cashier)->postJson($this->dashUrl("/pos/orders/$id/pay"), ['method' => 'split'])->assertStatus(422);
    }
}
