<?php

namespace Tests\Feature;

use App\Billing\Billing;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\HeldTicket;
use App\Models\MenuItem;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Tests\TenantTestCase;

/** Held POS tickets ("تعليق"): park, list, resume on another terminal once, discard with the right, branch and tenant isolation. */
class PosHoldsTest extends TenantTestCase
{
    private Tenant $tenant;

    private MenuItem $latte;

    private User $cashier;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->restaurant('cafe-a', 'basic');
        app(Billing::class)->setAddon($this->tenant, 'pos_pack', 1);
        $this->latte = $this->item($this->tenant, 'Latte', 60);
        $this->cashier = $this->staff($this->tenant, 'cashier', 'cashier@cafe-a.test');
        $this->manager = $this->staff($this->tenant, 'manager', 'manager@cafe-a.test');
    }

    private function hold(User $by, string $label = 'Ahmed · table 4'): TestResponse
    {
        return $this->actingAs($by)->postJson($this->dashUrl('/pos/holds'), [
            'label' => $label, 'total' => 120,
            'cart' => ['type' => 'takeaway', 'lines' => [['item_id' => $this->latte->id, 'qty' => 2, 'option_ids' => [], 'notes' => 'no sugar']],
                'customer_name' => 'Ahmed', 'discount' => 0, 'discount_kind' => 'amount'],
        ]);
    }

    public function test_a_held_ticket_is_resumed_once_from_another_terminal(): void
    {
        $this->hold($this->cashier)->assertOk();
        $list = $this->actingAs($this->manager)->getJson($this->dashUrl('/pos/holds'))->assertOk()->json('holds');
        $this->assertCount(1, $list);
        $this->assertSame(['Ahmed · table 4', 120.0, 2, 'Cashier'], [$list[0]['label'], (float) $list[0]['total'], $list[0]['count'], $list[0]['by']]);

        $this->actingAs($this->manager)->postJson($this->dashUrl("/pos/holds/{$list[0]['id']}/resume"))->assertOk()
            ->assertJsonPath('cart.lines.0.item_id', $this->latte->id)->assertJsonPath('cart.lines.0.qty', 2)
            ->assertJsonPath('cart.lines.0.notes', 'no sugar')->assertJsonPath('cart.customer_name', 'Ahmed');
        // gone from the list: the cashier's terminal cannot open it a second time
        $this->actingAs($this->cashier)->postJson($this->dashUrl("/pos/holds/{$list[0]['id']}/resume"))->assertNotFound();
        $this->assertSame([], $this->actingAs($this->cashier)->getJson($this->dashUrl('/pos/holds'))->json('holds'));

        // an empty cart cannot be held
        $this->actingAs($this->cashier)->postJson($this->dashUrl('/pos/holds'), ['label' => 'x', 'cart' => ['type' => 'takeaway', 'lines' => []]])->assertStatus(422);
        $this->actingAs($this->cashier)->get($this->dashUrl('/pos'))->assertOk()->assertSee('data-hold', false)->assertSee('data-holds-dialog', false);
    }

    public function test_discarding_needs_the_right_and_is_audited(): void
    {
        $id = $this->hold($this->cashier)->json('id');
        $this->actingAs($this->cashier)->postJson($this->dashUrl("/pos/holds/$id/discard"))->assertForbidden();
        $this->assertSame(1, $this->as($this->tenant, fn () => HeldTicket::query()->count()));

        $this->actingAs($this->manager)->postJson($this->dashUrl("/pos/holds/$id/discard"))->assertOk();
        $this->assertSame(0, $this->as($this->tenant, fn () => HeldTicket::query()->count()));
        $this->assertTrue($this->as($this->tenant, fn () => AuditLog::query()->where('action', 'pos.hold_discarded')->where('subject', 'Ahmed · table 4')->exists()));
        $this->assertTrue($this->as($this->tenant, fn () => $this->owner($this->tenant)->can('pos.hold.discard')));
    }

    public function test_another_branch_or_restaurant_never_sees_the_held_tickets(): void
    {
        $id = $this->hold($this->cashier)->json('id');
        $second = $this->as($this->tenant, fn () => Branch::query()->create(['name' => 'Second', 'slug' => 'second']));
        $there = $this->staff($this->tenant, 'manager', 'there@cafe-a.test');
        $this->as($this->tenant, fn () => $there->forceFill(['branch_id' => $second->id])->save());

        $this->assertSame([], $this->actingAs($there)->getJson($this->dashUrl('/pos/holds'))->json('holds'));
        $this->actingAs($there)->postJson($this->dashUrl("/pos/holds/$id/resume"))->assertNotFound();
        $this->actingAs($there)->postJson($this->dashUrl("/pos/holds/$id/discard"))->assertNotFound();

        $b = $this->restaurant('cafe-b', 'basic');
        app(Billing::class)->setAddon($b, 'pos_pack', 1);
        $this->actingAs($this->owner($b))->postJson($this->dashUrl("/pos/holds/$id/resume"))->assertNotFound();
        $this->assertSame([], $this->actingAs($this->owner($b))->getJson($this->dashUrl('/pos/holds'))->json('holds'));
        $this->assertSame(1, $this->as($this->tenant, fn () => HeldTicket::query()->count()));
    }
}
