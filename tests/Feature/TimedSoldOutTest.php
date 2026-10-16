<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Tenant;
use App\Services\Menu\MenuCatalog;
use App\Services\Orders\OrderException;
use App\Services\Orders\OrderService;
use Illuminate\Support\Carbon;
use Tests\TenantTestCase;

/** Sold out "until": the item comes back by itself once the time passes, on the menu and for ordering. */
class TimedSoldOutTest extends TenantTestCase
{
    private function tryOrder(Tenant $tenant, MenuItem $item): ?Order
    {
        return $this->as($tenant, function () use ($item) {
            try {
                return app(OrderService::class)->place(['source' => 'pos', 'type' => 'takeaway', 'items' => [['item_id' => $item->id, 'qty' => 1]]], Branch::query()->firstOrFail());
            } catch (OrderException) {
                return null;
            }
        });
    }

    public function test_item_is_back_once_its_time_passes(): void
    {
        $this->travelTo(Carbon::parse('2026-10-20 15:00', config('app.timezone')));
        $a = $this->restaurant('cafe-a');
        $item = $this->item($a, 'Latte', 60);
        $this->as($a, fn () => $item->update(['is_sold_out' => true, 'available_from' => now()->addHours(2)]));

        $soldOut = fn () => $this->as($a, fn () => MenuCatalog::forBranch(null)->apply(MenuItem::query()->findOrFail($item->id))->is_sold_out);
        $this->assertTrue($soldOut());
        $this->assertNull($this->tryOrder($a, $item));
        $this->get($this->subUrl('cafe-a'))->assertOk()->assertSee('"soldOut":true', false);

        $this->travel(121)->minutes();
        $this->assertFalse($soldOut());
        $this->assertFalse($this->as($a, fn () => MenuItem::query()->findOrFail($item->id)->isSoldOutNow()));
        $this->assertNotNull($this->tryOrder($a, $item));
        $this->get($this->subUrl('cafe-a'))->assertOk()->assertSee('"soldOut":false', false);
    }

    public function test_quick_toggle_takes_a_preset_and_the_form_keeps_a_running_end(): void
    {
        $this->travelTo(Carbon::parse('2026-10-20 15:00', config('app.timezone')));
        $a = $this->restaurant('cafe-a');
        $owner = $this->owner($a);
        $item = $this->item($a, 'Latte', 60);
        $fresh = fn () => $this->as($a, fn () => MenuItem::query()->findOrFail($item->id));

        $this->actingAs($owner)->postJson($this->dashUrl("/menu/items/{$item->id}/toggle"), ['field' => 'sold_out', 'until' => 'hours_2'])
            ->assertOk()->assertJsonPath('value', true)->assertJsonPath('until', fn ($u) => str_contains((string) $u, '05:00'));
        $this->assertTrue($fresh()->is_sold_out);
        $this->assertSame('2026-10-20 17:00', $fresh()->available_from->timezone(config('app.timezone'))->format('Y-m-d H:i'));

        // the list shows the end; toggling again brings the item back and clears the end
        $this->actingAs($owner)->get($this->dashUrl('/menu'))->assertOk()->assertSee('05:00');
        $this->actingAs($owner)->postJson($this->dashUrl("/menu/items/{$item->id}/toggle"), ['field' => 'sold_out'])->assertOk()->assertJsonPath('value', false);
        $this->assertFalse($fresh()->is_sold_out);
        $this->assertNull($fresh()->available_from);

        // end of day / custom presets
        $this->actingAs($owner)->postJson($this->dashUrl("/menu/items/{$item->id}/toggle"), ['field' => 'sold_out', 'until' => 'end_of_day'])->assertOk();
        $this->assertSame('2026-10-20 23:59', $fresh()->available_from->timezone(config('app.timezone'))->format('Y-m-d H:i'));
        $this->actingAs($owner)->postJson($this->dashUrl("/menu/items/{$item->id}/toggle"), ['field' => 'sold_out'])->assertOk(); // back
        $this->actingAs($owner)->postJson($this->dashUrl("/menu/items/{$item->id}/toggle"), ['field' => 'sold_out', 'until' => 'custom', 'until_at' => '2026-10-22T10:30'])->assertOk();
        $this->assertSame('2026-10-22 10:30', $fresh()->available_from->timezone(config('app.timezone'))->format('Y-m-d H:i'));
        $this->actingAs($owner)->postJson($this->dashUrl("/menu/items/{$item->id}/toggle"), ['field' => 'sold_out', 'until' => 'nonsense'])->assertStatus(422);

        // an ended timed sold-out counts as available: the toggle marks it sold out again instead of "bringing it back"
        $this->travelTo(Carbon::parse('2026-10-23 09:00', config('app.timezone')));
        $this->actingAs($owner)->postJson($this->dashUrl("/menu/items/{$item->id}/toggle"), ['field' => 'sold_out'])->assertOk()->assertJsonPath('value', true);
        $this->assertNull($fresh()->available_from);

        // the item form saves a preset with the sold-out box, and unticking clears it
        $post = fn (array $extra) => $this->actingAs($owner)->post($this->dashUrl("/menu/items/{$item->id}"), $extra + [
            'category_id' => $item->category_id, 'name' => 'Latte', 'price' => 60, 'is_active' => 1,
        ]);
        $post(['is_sold_out' => 1, 'sold_out_until' => 'tomorrow'])->assertRedirect();
        $this->assertSame('2026-10-24 06:00', $fresh()->available_from->timezone(config('app.timezone'))->format('Y-m-d H:i'));
        $post([])->assertRedirect();
        $this->assertFalse($fresh()->is_sold_out);
        $this->assertNull($fresh()->available_from);
    }
}
