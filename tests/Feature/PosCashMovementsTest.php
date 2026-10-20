<?php

namespace Tests\Feature;

use App\Billing\Billing;
use App\Models\AuditLog;
use App\Models\CashMovement;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Orders\ShiftService;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/** Cash in / out of the drawer from the POS: float top-ups and pay-outs with a reason and a category, in the expected cash, on the shift. */
class PosCashMovementsTest extends TenantTestCase
{
    private Tenant $tenant;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->restaurant('cafe-a', 'basic');
        app(Billing::class)->setAddon($this->tenant, 'pos_pack', 1);
        $this->cashier = $this->staff($this->tenant, 'cashier', 'cashier@cafe-a.test');
    }

    public function test_cash_in_and_out_from_the_pos_change_the_expected_cash_and_show_on_the_shift(): void
    {
        // no open shift yet: refused
        $this->actingAs($this->cashier)->postJson($this->dashUrl('/shifts/move'), ['type' => 'out', 'amount' => 30, 'reason' => 'ice'])
            ->assertStatus(422)->assertJsonPath('message', 'افتح وردية الأول.');

        $this->actingAs($this->cashier)->post($this->dashUrl('/shifts/open'), ['opening_cash' => 500])->assertSessionHasNoErrors();
        $latte = $this->item($this->tenant, 'Latte', 60);
        $this->actingAs($this->cashier)->postJson($this->dashUrl('/pos/orders'), [
            'client_uuid' => (string) Str::uuid(), 'type' => 'takeaway', 'items' => [['item_id' => $latte->id, 'qty' => 1]], 'pay' => 'cash',
        ])->assertOk();

        $this->actingAs($this->cashier)->postJson($this->dashUrl('/shifts/move'), ['type' => 'out', 'amount' => 30, 'reason' => 'ice', 'category' => 'expense'])
            ->assertOk()->assertJsonPath('expected_cash', 530);
        $this->actingAs($this->cashier)->postJson($this->dashUrl('/shifts/move'), ['type' => 'in', 'amount' => 200, 'reason' => 'change from the bank', 'category' => 'float'])
            ->assertOk()->assertJsonPath('expected_cash', 730);
        $this->actingAs($this->cashier)->postJson($this->dashUrl('/shifts/move'), ['type' => 'out', 'amount' => 10, 'reason' => 'x', 'category' => 'bribe'])->assertStatus(422);

        $shift = $this->as($this->tenant, fn () => Shift::query()->firstOrFail());
        $summary = $this->as($this->tenant, fn () => app(ShiftService::class)->summary($shift));
        $this->assertSame([200.0, 30.0, 30.0, 730.0], [$summary['cash_in'], $summary['cash_out'], $summary['expenses'], $summary['expected_cash']]);
        $this->assertSame(['expense', 'float'], $this->as($this->tenant, fn () => CashMovement::query()->orderBy('id')->pluck('category')->all()));
        $this->assertTrue($this->as($this->tenant, fn () => AuditLog::query()->where('action', 'shift.cash_out')->where('subject', 'ice')->exists()));

        $this->actingAs($this->cashier)->get($this->dashUrl('/shifts'))->assertOk()
            ->assertSee('data-movement="expense"', false)->assertSee('مصروف')->assertSee('منها مصروفات 30');
        $this->actingAs($this->cashier)->get($this->dashUrl('/pos'))->assertOk()->assertSee('data-cash-open', false)->assertSee('data-cash-dialog', false);

        // closing compares the count with it: 730 expected
        $this->actingAs($this->cashier)->post($this->dashUrl('/shifts/close'), ['closing_cash' => 730])->assertSessionHasNoErrors();
        $this->assertSame(0.0, $this->as($this->tenant, fn () => app(ShiftService::class)->summary($shift->fresh())['difference']));
    }

    public function test_a_waiter_without_the_shift_right_cannot_move_cash(): void
    {
        $waiter = $this->staff($this->tenant, 'waiter', 'waiter@cafe-a.test');
        $this->actingAs($waiter)->postJson($this->dashUrl('/shifts/move'), ['type' => 'out', 'amount' => 30, 'reason' => 'ice'])->assertForbidden();
        $this->actingAs($waiter)->get($this->dashUrl('/pos'))->assertOk()->assertDontSee('data-cash-open', false);
    }
}
