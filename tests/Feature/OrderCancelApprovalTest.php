<?php

namespace Tests\Feature;

use App\Billing\Billing;
use App\Models\AuditLog;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TenantTestCase;

/**
 * Cancelling an order from the board is a manager's call: staff without that right need a manager's PIN on their
 * device, and a paid order is never cancelled there, its money goes back through a refund.
 */
class OrderCancelApprovalTest extends TenantTestCase
{
    private Tenant $tenant;

    private MenuItem $latte;

    private User $cashier;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->restaurant('cafe-a', 'pro');
        app(Billing::class)->setAddon($this->tenant, 'pos_pack', 1);
        $this->latte = $this->item($this->tenant, 'Latte', 60);
        $this->cashier = $this->staff($this->tenant, 'cashier', 'cashier@cafe-a.test');
        $this->manager = $this->staff($this->tenant, 'manager', 'manager@cafe-a.test');
        $this->actingAs($this->cashier)->post($this->dashUrl('/shifts/open'), ['opening_cash' => 100])->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->post($this->dashUrl('/security/pin'), ['pin' => '2468', 'pin_confirmation' => '2468', 'current_password' => 'Secret-pass-123'])
            ->assertSessionHasNoErrors();
    }

    /** A POS ticket sent to the kitchen (unpaid), or paid at once with $pay. */
    private function ticket(?string $pay = null): int
    {
        return $this->actingAs($this->cashier)->postJson($this->dashUrl('/pos/orders'), [
            'client_uuid' => (string) Str::uuid(), 'type' => 'takeaway', 'items' => [['item_id' => $this->latte->id, 'qty' => 1]], 'pay' => $pay,
        ])->assertOk()->json('id');
    }

    private function cancel(int $id, User $by, array $approval = []): TestResponse
    {
        return $this->actingAs($by)->postJson($this->dashUrl("/orders/$id/status"), ['status' => 'cancelled', 'reason' => 'guest left'] + $approval);
    }

    private function orderStatus(int $id): string
    {
        return $this->as($this->tenant, fn () => Order::query()->findOrFail($id)->status);
    }

    public function test_staff_without_the_right_cancel_only_with_a_managers_pin(): void
    {
        $id = $this->ticket();

        $this->cancel($id, $this->cashier)->assertStatus(422)->assertJson(['approval' => true]);
        $this->cancel($id, $this->cashier, ['approver_id' => $this->manager->id, 'pin' => '1111'])->assertStatus(422);
        $this->assertSame('new', $this->orderStatus($id));

        $this->cancel($id, $this->cashier, ['approver_id' => $this->manager->id, 'pin' => '2468'])->assertOk();
        $this->assertSame('cancelled', $this->orderStatus($id));
        $audit = $this->as($this->tenant, fn () => AuditLog::query()->where('action', 'order.cancelled')->firstOrFail());
        $this->assertSame($this->manager->name, $audit->meta['approved_by']);
    }

    public function test_a_manager_cancels_alone_and_moving_an_order_along_needs_no_approval(): void
    {
        $moved = $this->ticket();
        $this->actingAs($this->cashier)->postJson($this->dashUrl("/orders/$moved/status"), ['status' => 'preparing'])->assertOk();

        $id = $this->ticket();
        $this->cancel($id, $this->manager)->assertOk();
        $this->assertSame('cancelled', $this->orderStatus($id));
    }

    public function test_a_paid_order_is_refunded_never_cancelled_even_by_a_manager(): void
    {
        $id = $this->ticket('cash');

        $this->cancel($id, $this->manager)->assertStatus(422)
            ->assertJson(['message' => __('This order is paid: give the money back with a refund from the cashier screen (a manager approves).')]);
        $this->cancel($id, $this->cashier, ['approver_id' => $this->manager->id, 'pin' => '2468'])->assertStatus(422);
        $this->assertNotSame('cancelled', $this->orderStatus($id));
    }

    public function test_the_board_offers_the_managers_who_can_approve(): void
    {
        $this->actingAs($this->cashier)->get($this->dashUrl('/orders'))->assertOk()
            ->assertSee('data-cancel-alone="0"', false)->assertSee($this->manager->name);
        $this->actingAs($this->manager)->get($this->dashUrl('/orders'))->assertOk()
            ->assertSee('data-cancel-alone="1"', false);
    }
}
