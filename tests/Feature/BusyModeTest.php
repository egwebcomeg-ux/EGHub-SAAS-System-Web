<?php

namespace Tests\Feature;

use App\Models\DiningTable;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Tenant;
use App\Services\Orders\BusyMode;
use App\Services\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/** "We're busy": pauses online / takeaway / delivery ordering until a time; table orders go on; expires by itself. */
class BusyModeTest extends TenantTestCase
{
    private function onlineOrder(Tenant $tenant)
    {
        $item = $this->as($tenant, fn () => MenuItem::query()->firstOrFail());

        return $this->postJson($this->subUrl($tenant->id, 'order'), [
            'client_uuid' => (string) Str::uuid(), 'items' => [['item_id' => $item->id, 'qty' => 1]],
            'type' => 'takeaway', 'customer_name' => 'Mona', 'customer_phone' => '01012345678',
        ]);
    }

    private function tableOrder(Tenant $tenant)
    {
        $table = $this->as($tenant, fn () => DiningTable::query()->firstOrFail());
        $item = $this->as($tenant, fn () => MenuItem::query()->firstOrFail());
        $this->get($this->subUrl($tenant->id, "t/{$table->qr_token}"));

        return $this->postJson($this->subUrl($tenant->id, 'order'), ['client_uuid' => (string) Str::uuid(), 'items' => [['item_id' => $item->id, 'qty' => 1]]]);
    }

    public function test_busy_pauses_online_orders_but_not_table_orders_and_expires(): void
    {
        $a = $this->restaurant('cafe-a', 'pro');
        $this->item($a, 'Latte', 60);

        $this->onlineOrder($a)->assertOk();
        $this->as($a, fn () => BusyMode::start(now()->addMinutes(30), 'الكاشير زحمة، نص ساعة ونرجع'));

        // the menu shows the message and offers no pickup / delivery; the order itself is refused with the message
        $this->get($this->subUrl('cafe-a'))->assertOk()->assertSee('data-busy-notice', false)->assertSee('الكاشير زحمة')
            ->assertDontSee('data-otype="takeaway"', false);
        $this->onlineOrder($a)->assertStatus(422)->assertJsonFragment(['message' => 'الكاشير زحمة، نص ساعة ونرجع']);
        $this->tableOrder($a)->assertOk();
        $this->assertSame(2, $this->as($a, fn () => Order::query()->count()));

        // time passes: busy mode is over without anyone touching it
        $this->travel(31)->minutes();
        $this->assertFalse($this->as($a, fn () => BusyMode::isBusy()));
        $this->onlineOrder($a)->assertOk();
        $this->get($this->subUrl('cafe-a'))->assertOk()->assertDontSee('data-busy-notice', false);
    }

    public function test_staff_switch_it_from_the_board_with_presets_and_back(): void
    {
        $a = $this->restaurant('cafe-a', 'pro');
        $owner = $this->owner($a);
        $this->travelTo(Carbon::parse('2026-10-20 15:00', config('app.timezone')));

        $this->actingAs($owner)->get($this->dashUrl('/orders'))->assertOk()->assertSee('data-busy-box', false)->assertSee('data-alarm-mute', false);

        $this->actingAs($owner)->postJson($this->dashUrl('/orders/busy'), ['mode' => 'minutes', 'minutes' => 60, 'message' => 'Back soon'])
            ->assertOk()->assertJsonPath('busy.message', 'Back soon');
        $until = $this->as($a, fn () => BusyMode::until());
        $this->assertEqualsWithDelta(now()->addHour()->timestamp, $until->timestamp, 5);

        // a clock time: today when still ahead, otherwise tomorrow
        $this->actingAs($owner)->postJson($this->dashUrl('/orders/busy'), ['mode' => 'time', 'time' => now()->subHour()->format('H:i')])->assertOk();
        $this->assertTrue($this->as($a, fn () => BusyMode::until())->isTomorrow());

        // the board feed carries the state for every open screen
        $this->actingAs($owner)->getJson($this->dashUrl('/orders/feed'))->assertOk()->assertJsonStructure(['busy' => ['until', 'message']]);

        $this->actingAs($owner)->postJson($this->dashUrl('/orders/busy'), ['mode' => 'off'])->assertOk()->assertJsonPath('busy', null);
        $this->assertSame('', $this->as($a, fn () => Settings::get('busy_until')));

        $this->actingAs($owner)->postJson($this->dashUrl('/orders/busy'), ['mode' => 'minutes'])->assertStatus(422);
        $this->actingAs($owner)->postJson($this->dashUrl('/orders/busy'), ['mode' => 'time', 'time' => '25:99'])->assertStatus(422);
    }

    public function test_only_staff_who_manage_orders_can_switch_it(): void
    {
        $a = $this->restaurant('cafe-a', 'pro');
        $kitchen = $this->staff($a, 'kitchen', 'k@cafe-a.test');
        $this->actingAs($kitchen)->postJson($this->dashUrl('/orders/busy'), ['mode' => 'minutes', 'minutes' => 30])->assertForbidden();
        $this->assertFalse($this->as($a, fn () => BusyMode::isBusy()));
    }
}
