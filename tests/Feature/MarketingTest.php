<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\DiningTable;
use App\Models\MenuItem;
use App\Models\Offer;
use App\Models\Order;
use App\Models\Popup;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/** Timed offers, popups, "goes well with" pairings and combos. */
class MarketingTest extends TenantTestCase
{
    private function orderAtTable(Tenant $t, array $items)
    {
        $table = $this->as($t, fn () => DiningTable::query()->first());
        $this->get($this->menuUrl("{$t->id}/t/{$table->qr_token}"));
        return $this->postJson($this->menuUrl("{$t->id}/order"), ['client_uuid' => (string) Str::uuid(), 'items' => $items]);
    }

    public function test_overnight_and_daily_windows(): void
    {
        $offer = new Offer(['is_active' => true, 'starts_at' => '22:00:00', 'ends_at' => '02:00:00', 'days' => [5]]); // Friday night
        $fri = Carbon::parse('2026-10-16 23:30', 'Africa/Cairo');   // Friday
        $this->assertTrue($offer->runsAt($fri, null));
        $this->assertTrue($offer->runsAt($fri->copy()->addHours(2), null));   // Saturday 01:30 still Friday's night
        $this->assertFalse($offer->runsAt($fri->copy()->addHours(3), null));  // 02:30 over
        $this->assertFalse($offer->runsAt($fri->copy()->subDay(), null));     // Thursday

        $branchOnly = new Offer(['is_active' => true, 'branch_id' => 7]);
        $this->assertFalse($branchOnly->runsAt($fri, 8));
        $this->assertTrue($branchOnly->runsAt($fri, 7));
    }

    public function test_running_offer_lowers_the_charged_price_and_shows_on_the_menu(): void
    {
        $a = $this->restaurant('cafe-a');
        $item = $this->item($a, 'Iced Latte', 80);
        $this->as($a, fn () => Offer::query()->create(['title' => 'Happy hour', 'discount_percent' => 25, 'category_id' => $item->category_id]));

        $this->get($this->menuUrl('cafe-a'))->assertOk()->assertSee('Happy hour')->assertSee('-25%');
        $this->orderAtTable($a, [['item_id' => $item->id, 'qty' => 2]])->assertOk();

        $line = $this->as($a, fn () => Order::query()->first()->items()->first());
        $this->assertSame(60.0, $line->unit_price);
        $this->assertSame(80.0, $line->original_price);
        $this->assertSame(120.0, $line->line_total);
    }

    public function test_offer_outside_its_hours_or_plan_does_nothing(): void
    {
        $a = $this->restaurant('cafe-a');
        $item = $this->item($a, 'Tea', 40);
        $later = now()->addHours(3)->format('H:i');
        $muchLater = now()->addHours(4)->format('H:i');
        $this->as($a, fn () => Offer::query()->create(['title' => 'Later', 'discount_percent' => 50, 'starts_at' => $later, 'ends_at' => $muchLater]));
        $this->orderAtTable($a, [['item_id' => $item->id, 'qty' => 1]])->assertOk();
        $this->assertSame(40.0, $this->as($a, fn () => Order::query()->first()->total));

        $basic = $this->restaurant('cafe-basic', 'basic');
        $tea = $this->item($basic, 'Tea', 40);
        $this->as($basic, fn () => Offer::query()->create(['title' => 'Always', 'discount_percent' => 50]));
        $this->orderAtTable($basic, [['item_id' => $tea->id, 'qty' => 1]])->assertOk();
        $this->assertSame(40.0, $this->as($basic, fn () => Order::query()->first()->total));
        $this->actingAs($this->owner($basic))->get($this->dashUrl('/marketing'))->assertForbidden();
    }

    public function test_owner_creates_offer_and_popup_from_the_dashboard(): void
    {
        $a = $this->restaurant('cafe-a');
        $item = $this->item($a, 'Cake', 90);
        $owner = $this->owner($a);
        $this->actingAs($owner)->post($this->dashUrl('/marketing/offers'), [
            'title' => 'Weekend', 'discount_percent' => 15, 'days' => [5, 6], 'starts_at' => '15:00', 'ends_at' => '18:00',
        ])->assertSessionHasNoErrors();
        $this->assertSame([5, 6], $this->as($a, fn () => Offer::query()->first()->days));

        $this->actingAs($owner)->post($this->dashUrl('/marketing/popups'), [
            'type' => 'item', 'menu_item_id' => $item->id, 'title' => 'Try our cake', 'frequency' => 'day',
        ])->assertSessionHasNoErrors();
        $popup = $this->as($a, fn () => Popup::query()->first());
        $this->get($this->menuUrl('cafe-a'))->assertSee('Try our cake', false);

        $this->postJson($this->menuUrl("cafe-a/popups/{$popup->id}/view"))->assertOk();
        $this->postJson($this->menuUrl("cafe-a/popups/{$popup->id}/click"))->assertOk();
        $popup->refresh();
        $this->assertSame([1, 1], [$popup->views, $popup->clicks]);
    }

    public function test_combo_contents_reach_the_kitchen_and_pairings_are_saved_per_restaurant(): void
    {
        $a = $this->restaurant('cafe-a');
        $b = $this->restaurant('cafe-b');
        [$burger, $fries, $cola, $cat] = $this->as($a, function () {
            $cat = Category::query()->create(['name' => 'Meals']);
            return [MenuItem::query()->create(['category_id' => $cat->id, 'name' => 'Burger', 'price' => 120]),
                MenuItem::query()->create(['category_id' => $cat->id, 'name' => 'Fries', 'price' => 40]),
                MenuItem::query()->create(['category_id' => $cat->id, 'name' => 'Cola', 'price' => 30]), $cat];
        });
        $foreign = $this->item($b, 'Foreign', 10);
        $owner = $this->owner($a);

        $this->actingAs($owner)->post($this->dashUrl('/menu/items'), [
            'category_id' => $cat->id, 'name' => 'Burger Meal', 'price' => 160, 'is_active' => '1',
            'combo' => [['item_id' => $burger->id, 'qty' => 1], ['item_id' => $fries->id, 'qty' => 1], ['item_id' => $cola->id, 'qty' => 1]],
            'pairings' => [$cola->id],
        ])->assertSessionHasNoErrors();
        $meal = $this->as($a, fn () => MenuItem::query()->where('name', 'Burger Meal')->first());

        $this->assertSame(3, DB::table('combo_items')->where('combo_id', $meal->id)->where('tenant_id', 'cafe-a')->count());
        $this->assertSame(1, DB::table('item_pairings')->where('menu_item_id', $meal->id)->where('tenant_id', 'cafe-a')->count());
        $this->get($this->menuUrl('cafe-a'))->assertSee('"saving":30', false);   // 120+40+30 − 160

        $this->orderAtTable($a, [['item_id' => $meal->id, 'qty' => 1]])->assertOk();
        $options = $this->as($a, fn () => Order::query()->first()->items()->first()->options);
        $this->assertSame(['1× Burger', '1× Fries', '1× Cola'], array_column($options, 'name'));

        $this->actingAs($owner)->post($this->dashUrl("/menu/items/{$meal->id}"), [
            'category_id' => $cat->id, 'name' => 'Burger Meal', 'price' => 160, 'pairings' => [$foreign->id],
        ])->assertSessionHasErrors('pairings.0');
    }
}
