<?php

namespace Tests\Feature;

use App\Billing\Billing;
use App\Models\Branch;
use App\Models\DiningTable;
use App\Models\Kiosk;
use App\Models\Order;
use App\Models\Tenant;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/** Self-order kiosk (kiosk pack): public tablet link per kiosk, orders with source kiosk in the kiosk's branch. */
class KioskTest extends TenantTestCase
{
    private function withKioskPack(string $slug, string $plan = 'basic'): Tenant
    {
        $tenant = $this->restaurant($slug, $plan);
        app(Billing::class)->setAddon($tenant, 'kiosk_pack', 1);

        return $tenant;
    }

    private function kiosk(Tenant $tenant, array $attributes = []): Kiosk
    {
        return $this->as($tenant, fn () => Kiosk::query()->create($attributes + [
            'branch_id' => Branch::query()->value('id'), 'name' => 'Counter', 'token' => random_token(10),
        ]));
    }

    private function order(string $slug, Kiosk $kiosk, array $extra = [])
    {
        return $this->postJson($this->pathUrl($slug, "kiosk/{$kiosk->token}/order"), $extra + [
            'client_uuid' => (string) Str::uuid(), 'type' => 'takeaway', 'customer_name' => 'Mona',
        ]);
    }

    public function test_owner_creates_a_kiosk_and_a_guest_orders_from_it_into_its_branch(): void
    {
        $a = $this->withKioskPack('cafe-a');
        $latte = $this->item($a, 'Latte', 60);
        $owner = $this->owner($a);
        $second = $this->as($a, fn () => Branch::query()->create(['name' => 'Second', 'slug' => 'second']));

        $this->actingAs($owner)->post($this->dashUrl('/kiosks'), [
            'name' => 'Front tablet', 'branch_id' => $second->id, 'idle_seconds' => 45, 'order_types' => ['takeaway', 'dine_in'],
        ])->assertSessionHasNoErrors();
        $kiosk = $this->as($a, fn () => Kiosk::query()->firstOrFail());
        $this->assertSame(['takeaway', 'dine_in'], $kiosk->options()['order_types']);
        $this->actingAs($owner)->get($this->dashUrl('/kiosks'))->assertOk()->assertSee($kiosk->token);

        $this->get($this->pathUrl('cafe-a', "kiosk/{$kiosk->token}"))->assertOk()->assertSee('Latte');
        $this->order('cafe-a', $kiosk, ['items' => [['item_id' => $latte->id, 'qty' => 2]]])
            ->assertOk()->assertJsonPath('number', 1);

        $order = $this->as($a, fn () => Order::query()->with('items')->firstOrFail());
        $this->assertSame('kiosk', $order->source);
        $this->assertSame($second->id, $order->branch_id);
        $this->assertSame('takeaway', $order->type);
        $this->assertSame('Mona', $order->customer_name);
        $this->assertEquals(120, $order->total);
        $this->assertSame('new', $order->status);

        // dine-in: a known table is linked, anything else stays as a note for the waiter
        $table = $this->as($a, fn () => DiningTable::query()->create(['branch_id' => $second->id, 'name' => '7']));
        $this->order('cafe-a', $kiosk, ['type' => 'dine_in', 'table' => '7', 'items' => [['item_id' => $latte->id, 'qty' => 1]]])->assertOk();
        $this->assertSame($table->id, $this->as($a, fn () => Order::query()->latest('id')->value('dining_table_id')));
        $this->order('cafe-a', $kiosk, ['type' => 'dine_in', 'items' => [['item_id' => $latte->id, 'qty' => 1]]])->assertStatus(422);

        // the order shows on the order board like any other
        $this->actingAs($owner)->getJson($this->dashUrl('/orders/feed'))->assertOk()->assertJsonFragment(['source' => 'kiosk']);
    }

    public function test_settings_are_enforced(): void
    {
        $a = $this->withKioskPack('cafe-a');
        $latte = $this->item($a, 'Latte', 60);
        $kiosk = $this->kiosk($a, ['settings' => ['order_types' => ['takeaway'], 'require_phone' => true, 'idle_seconds' => 30]]);
        $items = ['items' => [['item_id' => $latte->id, 'qty' => 1]]];

        $this->order('cafe-a', $kiosk, $items + ['type' => 'dine_in', 'table' => '3', 'customer_phone' => '01012345678'])->assertJsonValidationErrors('type');
        $this->order('cafe-a', $kiosk, $items)->assertJsonValidationErrors('customer_phone');
        $this->order('cafe-a', $kiosk, $items + ['customer_name' => ''])->assertJsonValidationErrors('customer_name');
        $this->order('cafe-a', $kiosk, $items + ['customer_phone' => '0101'])->assertStatus(422);
        $this->order('cafe-a', $kiosk, $items + ['customer_phone' => '01012345678'])->assertOk();
    }

    public function test_inactive_or_unknown_token_is_not_found(): void
    {
        $a = $this->withKioskPack('cafe-a');
        $latte = $this->item($a, 'Latte', 60);
        $kiosk = $this->kiosk($a, ['is_active' => false]);

        $this->get($this->pathUrl('cafe-a', "kiosk/{$kiosk->token}"))->assertNotFound();
        $this->order('cafe-a', $kiosk, ['items' => [['item_id' => $latte->id, 'qty' => 1]]])->assertNotFound();
        $this->get($this->pathUrl('cafe-a', 'kiosk/'.random_token(10)))->assertNotFound();

        // a new link retires the old one
        $this->as($a, fn () => $kiosk->update(['is_active' => true]));
        $old = $kiosk->token;
        $this->actingAs($this->owner($a))->post($this->dashUrl("/kiosks/{$kiosk->id}/link"))->assertSessionHasNoErrors();
        $this->get($this->pathUrl('cafe-a', "kiosk/$old"))->assertNotFound();
        $this->assertSame(0, $this->as($a, fn () => Order::query()->count()));
    }

    public function test_kiosk_needs_the_pack(): void
    {
        $a = $this->restaurant('cafe-a', 'business');
        $latte = $this->item($a, 'Latte', 60);
        $kiosk = $this->kiosk($a);

        $this->get($this->pathUrl('cafe-a', "kiosk/{$kiosk->token}"))->assertNotFound();
        $this->order('cafe-a', $kiosk, ['items' => [['item_id' => $latte->id, 'qty' => 1]]])->assertNotFound();
        $this->actingAs($this->owner($a))->get($this->dashUrl('/kiosks'))->assertForbidden();

        // staff without the permission cannot manage kiosks even with the pack
        $b = $this->withKioskPack('cafe-b');
        $this->actingAs($this->staff($b, 'cashier', 'cashier@b.test'))->get($this->dashUrl('/kiosks'))->assertForbidden();
        $this->actingAs($this->staff($b, 'manager', 'manager@b.test'))->get($this->dashUrl('/kiosks'))->assertOk();
    }

    public function test_kiosks_are_isolated_per_restaurant(): void
    {
        $a = $this->withKioskPack('cafe-a');
        $b = $this->withKioskPack('cafe-b');
        $foreignItem = $this->item($b, 'Foreign', 10);
        $this->item($a, 'Latte', 60);
        $kioskB = $this->kiosk($b);
        $kioskA = $this->kiosk($a);
        $branchB = $this->as($b, fn () => Branch::query()->value('id'));

        // B's link under A's address does not open, and A's kiosk cannot order B's items
        $this->get($this->pathUrl('cafe-a', "kiosk/{$kioskB->token}"))->assertNotFound();
        $this->get($this->subUrl('cafe-a', "kiosk/{$kioskB->token}"))->assertNotFound();
        $this->order('cafe-a', $kioskA, ['items' => [['item_id' => $foreignItem->id, 'qty' => 1]]])->assertStatus(422);

        // A cannot attach a kiosk to B's branch, nor edit B's kiosk
        $owner = $this->owner($a);
        $this->actingAs($owner)->post($this->dashUrl('/kiosks'), [
            'name' => 'X', 'branch_id' => $branchB, 'idle_seconds' => 60, 'order_types' => ['takeaway'],
        ])->assertSessionHasErrors('branch_id');
        $this->actingAs($owner)->post($this->dashUrl("/kiosks/{$kioskB->id}/delete"))->assertNotFound();
        $this->assertSame(1, $this->as($b, fn () => Kiosk::query()->count()));
        $this->assertSame(0, $this->as($b, fn () => Order::query()->count()));
    }
}
