<?php

namespace Tests\Feature;

use App\Billing\Billing;
use App\Models\Branch;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\PosTerminal;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Reports\ZReport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/** End of day (Z) report: sales by method, refunds, discounts, cancels, cash in / out, shifts, offline sales; per terminal; print and export. */
class PosZReportTest extends TenantTestCase
{
    private Tenant $tenant;

    private MenuItem $latte;

    private User $cashier;

    private User $manager;

    private array $device;

    protected function setUp(): void
    {
        parent::setUp();
        // mid-afternoon: the day's sales below go back a few hours and must stay on "today" when the suite runs around midnight
        $this->travelTo(now()->setTime(15, 0));
        $this->tenant = $this->restaurant('cafe-a', 'basic');
        app(Billing::class)->setAddon($this->tenant, 'pos_pack', 1);
        $this->latte = $this->item($this->tenant, 'Latte', 60);
        $this->cashier = $this->staff($this->tenant, 'cashier', 'cashier@cafe-a.test');
        $this->manager = $this->staff($this->tenant, 'manager', 'manager@cafe-a.test');
        $branch = $this->as($this->tenant, fn () => Branch::query()->firstOrFail());
        $this->actingAs($this->manager)->post($this->dashUrl('/pos/terminals'), ['branch_id' => $branch->id, 'name' => 'Cashier 1']);
        $terminal = $this->as($this->tenant, fn () => PosTerminal::query()->firstOrFail());
        $this->device = $this->actingAs($this->cashier)->postJson($this->dashUrl("/pos/terminals/{$terminal->id}/claim"))->json();
    }

    private function headers(): array
    {
        return ['X-POS-Terminal' => (string) $this->device['id'], 'X-POS-Terminal-Token' => $this->device['token']];
    }

    private function sell(int $qty, ?string $pay, array $extra = []): int
    {
        $id = $this->actingAs($this->cashier)->withHeaders($this->headers())->postJson($this->dashUrl('/pos/orders'), $extra + [
            'client_uuid' => (string) Str::uuid(), 'type' => 'takeaway', 'items' => [['item_id' => $this->latte->id, 'qty' => $qty]], 'pay' => $pay,
        ])->assertOk()->json('id');
        $this->flushHeaders();

        return $id;
    }

    /** A day of one terminal: four sales (one split, one made offline), a cancel, an unpaid order, a refund, cash in and out. */
    private function day(): void
    {
        $this->travelTo(now()->subHours(3));
        $this->actingAs($this->cashier)->post($this->dashUrl('/shifts/open'), ['opening_cash' => 100, 'terminal_id' => $this->device['id'], 'terminal_token' => $this->device['token']])
            ->assertSessionHasNoErrors();
        $this->travelTo(now()->addHours(3)); // back to the frozen afternoon, not to the real clock
        $this->actingAs($this->manager)->post($this->dashUrl('/shifts/open'), ['opening_cash' => 0])->assertSessionHasNoErrors();
        $shift = $this->as($this->tenant, fn () => Shift::query()->where('user_id', $this->cashier->id)->firstOrFail());

        $cash = $this->sell(2, 'cash');
        $this->sell(1, 'card');
        $this->sell(1, 'split', ['payments' => [['method' => 'cash', 'amount' => 30], ['method' => 'instapay', 'amount' => 30]]]);
        $cancelled = $this->sell(1, null);
        $this->actingAs($this->manager)->postJson($this->dashUrl("/orders/$cancelled/status"), ['status' => 'cancelled', 'reason' => 'left'])->assertOk();
        $this->sell(1, null); // still unpaid
        $this->actingAs($this->cashier)->withHeaders($this->headers())->postJson($this->dashUrl('/pos/sync'), [
            'client_uuid' => (string) Str::uuid(), 'offline_ref' => 'OFF-K1-1', 'created_offline_at' => now()->subHour()->toIso8601String(),
            'offline_since' => now()->subHours(2)->toIso8601String(), 'shift_id' => $shift->id, 'type' => 'takeaway',
            'items' => [['item_id' => $this->latte->id, 'qty' => 2, 'name' => 'Latte', 'unit_price' => 60]],
            'subtotal' => 120, 'total' => 120, 'method' => 'cash',
        ])->assertOk();
        $this->flushHeaders();
        // the manager gives one latte back from their own shift (no terminal)
        $item = $this->as($this->tenant, fn () => Order::query()->with('items')->findOrFail($cash)->items[0]->id);
        $this->actingAs($this->manager)->postJson($this->dashUrl("/pos/orders/$cash/refund"), ['full' => false, 'lines' => [['order_item_id' => $item, 'qty' => 1]], 'method' => 'cash', 'reason' => 'cold'])->assertOk();
        $this->actingAs($this->cashier)->postJson($this->dashUrl('/shifts/move'), ['type' => 'out', 'amount' => 20, 'reason' => 'ice', 'category' => 'expense'])->assertOk();
        $this->actingAs($this->cashier)->postJson($this->dashUrl('/shifts/move'), ['type' => 'in', 'amount' => 50, 'reason' => 'change', 'category' => 'float'])->assertOk();
        $this->actingAs($this->cashier)->post($this->dashUrl('/shifts/close'), ['closing_cash' => 400])->assertSessionHasNoErrors();
    }

    public function test_the_z_report_adds_up_the_day_of_the_branch_and_of_one_terminal(): void
    {
        $this->day();
        $branch = $this->as($this->tenant, fn () => Branch::query()->firstOrFail());
        $z = $this->as($this->tenant, fn () => (new ZReport(CarbonImmutable::parse(local_today()), $branch->id))->build());

        $this->assertSame([4, 360.0], [$z['orders'], $z['gross']]);
        $this->assertSame(['cash' => 270.0, 'card' => 60.0, 'instapay' => 30.0], $z['by_method']);
        $this->assertSame(['count' => 1, 'total' => 60.0, 'by_method' => ['cash' => 60.0]], $z['refunds']);
        $this->assertSame([300.0, 210.0], [$z['net'], $z['net_by_method']['cash']]);
        $this->assertSame(['count' => 1, 'total' => 60.0], $z['cancelled']);
        $this->assertSame(['count' => 1, 'total' => 60.0], $z['unpaid']);
        $this->assertSame(['in' => 50.0, 'out' => 20.0, 'expenses' => 20.0], $z['cash']);
        $this->assertSame(['count' => 1, 'total' => 120.0, 'review' => 0], $z['offline']);
        $cashierShift = collect($z['shifts'])->firstWhere('user', 'Cashier');
        // 100 opening + 270 cash sales + 50 in − 20 out = 400 expected; counted 400
        $this->assertSame([400.0, 400.0, 0.0, 'Cashier 1 · K1'], [$cashierShift['expected'], $cashierShift['counted'], $cashierShift['difference'], $cashierShift['terminal']]);
        $this->assertCount(2, $z['shifts']);
        $this->assertSame([['name' => 'Cashier 1 · K1', 'orders' => 4, 'total' => 360.0]], $z['terminals']);

        // the terminal alone: its sales, not the manager's refund
        $k1 = $this->as($this->tenant, fn () => (new ZReport(CarbonImmutable::parse(local_today()), $branch->id, $this->device['id']))->build());
        $this->assertSame([360.0, 0.0, 360.0, 1], [$k1['gross'], $k1['refunds']['total'], $k1['net'], count($k1['shifts'])]);
        // another day is empty
        $this->assertSame(0, $this->as($this->tenant, fn () => (new ZReport(CarbonImmutable::yesterday(), $branch->id))->build())['orders']);
    }

    public function test_the_z_report_page_prints_and_exports_for_managers_only(): void
    {
        $this->day();
        $this->actingAs($this->manager)->get($this->dashUrl('/pos/z-report'))->assertOk()
            ->assertSee('data-z-kpis', false)->assertSee('data-z-method="cash"', false)->assertSee('data-z-shifts', false)->assertSee('Cashier 1 · K1');
        $this->actingAs($this->manager)->get($this->dashUrl('/pos/z-report?format=80mm'))->assertOk()->assertSee('data-z-paper="80mm"', false)->assertSee('80mm auto', false);
        $this->actingAs($this->manager)->get($this->dashUrl('/pos/z-report?format=a4'))->assertOk()->assertSee('data-z-paper="a4"', false)->assertSee('size: A4', false);
        $csv = $this->actingAs($this->manager)->get($this->dashUrl('/pos/z-report?format=csv'))->assertOk()->streamedContent();
        $this->assertStringContainsString('"صافي المبيعات",300', $csv);
        $this->actingAs($this->manager)->get($this->dashUrl('/pos/z-report?format=xlsx'))->assertOk()->assertDownload();
        // the cashier closes their own day too (shifts.manage); a waiter does not
        $this->actingAs($this->cashier)->get($this->dashUrl('/pos/z-report'))->assertOk();
        $waiter = $this->staff($this->tenant, 'waiter', 'waiter@cafe-a.test');
        $this->actingAs($waiter)->get($this->dashUrl('/pos/z-report'))->assertForbidden();

        // another restaurant's branch is not found; its own report is empty
        $b = $this->restaurant('cafe-b', 'basic');
        app(Billing::class)->setAddon($b, 'pos_pack', 1);
        $branch = $this->as($this->tenant, fn () => Branch::query()->firstOrFail());
        $this->actingAs($this->owner($b))->get($this->dashUrl('/pos/z-report?branch='.$branch->id))->assertNotFound();
        $this->actingAs($this->owner($b))->get($this->dashUrl('/pos/z-report'))->assertOk()->assertDontSee('Cashier 1 · K1');
    }
}
