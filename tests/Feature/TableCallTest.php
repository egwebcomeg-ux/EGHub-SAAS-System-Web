<?php

namespace Tests\Feature;

use App\Models\DiningTable;
use App\Models\TableCall;
use Tests\TenantTestCase;

class TableCallTest extends TenantTestCase
{
    public function test_guest_calls_the_waiter_and_staff_handle_it_on_the_board(): void
    {
        $a = $this->restaurant('cafe-a', 'basic');
        $table = $this->as($a, fn () => DiningTable::query()->where('name', '3')->first());

        // without scanning a table nothing is created
        $this->postJson($this->subUrl('cafe-a', 'call'), ['kind' => 'waiter'])->assertStatus(422);

        $this->get($this->subUrl('cafe-a', "t/{$table->qr_token}"));
        $this->postJson($this->subUrl('cafe-a', 'call'), ['kind' => 'waiter'])->assertOk();
        $this->postJson($this->subUrl('cafe-a', 'call'), ['kind' => 'waiter'])->assertOk(); // same open call, not a second one
        $this->postJson($this->subUrl('cafe-a', 'call'), ['kind' => 'bill'])->assertOk();
        $this->assertSame(2, $this->as($a, fn () => TableCall::query()->count()));

        $owner = $this->owner($a);
        $feed = $this->actingAs($owner)->getJson($this->dashUrl('/orders/feed'))->assertOk()->json('calls');
        $this->assertCount(2, $feed);
        $this->assertSame('3', $feed[0]['table']);

        $this->actingAs($owner)->postJson($this->dashUrl("/orders/calls/{$feed[0]['id']}"))->assertOk();
        $this->assertCount(1, $this->actingAs($owner)->getJson($this->dashUrl('/orders/feed'))->json('calls'));
    }

    public function test_calls_are_private_to_each_restaurant(): void
    {
        $a = $this->restaurant('cafe-a', 'basic');
        $b = $this->restaurant('cafe-b', 'basic');
        $call = $this->as($a, function () {
            $table = DiningTable::query()->first();

            return TableCall::query()->create(['branch_id' => $table->branch_id, 'dining_table_id' => $table->id, 'kind' => 'bill']);
        });

        $this->assertCount(0, $this->actingAs($this->owner($b))->getJson($this->dashUrl('/orders/feed'))->json('calls'));
        $this->actingAs($this->owner($b))->postJson($this->dashUrl("/orders/calls/{$call->id}"))->assertNotFound();
        $this->assertNull($this->as($a, fn () => $call->fresh()->handled_at));
    }
}
