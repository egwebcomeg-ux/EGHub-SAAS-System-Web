<?php

namespace Tests\Feature;

use App\Billing\Billing;
use App\Models\Branch;
use App\Models\Ingredient;
use App\Models\IngredientStock;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\PackagingRule;
use App\Models\Purchase;
use App\Models\RecipeLine;
use App\Models\StockCount;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Inventory\StockService;
use App\Services\Orders\OrderService;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/** Packaging materials: takeaway-only recipe lines, packaging per takeaway / delivery order, and packaging in counts and purchases. */
class InventoryPackagingTest extends TenantTestCase
{
    private Tenant $tenant;

    private User $owner;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->restaurant('cafe-a', 'basic');
        app(Billing::class)->setAddon($this->tenant, 'inventory_pack', 1);
        $this->owner = $this->owner($this->tenant);
        $this->branch = $this->as($this->tenant, fn () => Branch::query()->first());
    }

    private function material(string $name, string $kind, float $cost = 1): Ingredient
    {
        $this->actingAs($this->owner)->post($this->dashUrl('/inventory/ingredients'), ['name' => $name, 'kind' => $kind, 'unit' => 'pcs', 'cost_per_unit' => $cost])
            ->assertSessionHasNoErrors();

        return $this->as($this->tenant, fn () => Ingredient::query()->where('name', $name)->sole());
    }

    /** @return array<string,float> ingredient name => stock in the branch */
    private function stock(): array
    {
        return $this->as($this->tenant, fn () => IngredientStock::query()->with('ingredient')->get()
            ->mapWithKeys(fn ($s) => [$s->ingredient->name => (float) $s->quantity])->sortKeys()->all());
    }

    private function order(MenuItem $item, string $type, int $qty): Order
    {
        return $this->as($this->tenant, fn () => app(OrderService::class)->place([
            'client_uuid' => (string) Str::uuid(), 'source' => 'pos', 'type' => $type, 'items' => [['item_id' => $item->id, 'qty' => $qty]],
        ], $this->branch));
    }

    public function test_takeaway_packaging_and_per_order_packaging_follow_the_order_type_and_a_cancel_restores_them_once(): void
    {
        $milk = $this->material('Milk', 'ingredient', 0.05);
        $cup = $this->material('Cup', 'packaging', 2);
        $lid = $this->material('Lid', 'packaging', 0.5);
        $bag = $this->material('Bag', 'packaging', 1.5);
        $sticker = $this->material('Sticker', 'packaging', 0.25);
        $latte = $this->item($this->tenant, 'Latte', 60);

        $this->actingAs($this->owner)->post($this->dashUrl("/inventory/recipes/{$latte->id}"), ['lines' => [
            ['ingredient_id' => $milk->id, 'quantity' => 200],
            ['ingredient_id' => $cup->id, 'quantity' => 1, 'takeaway_only' => 1],
            ['ingredient_id' => $lid->id, 'quantity' => 1, 'takeaway_only' => 1],
        ]])->assertSessionHasNoErrors();
        foreach ([['takeaway', $bag, 1], ['delivery', $bag, 1], ['delivery', $sticker, 2]] as [$type, $material, $qty]) {
            $this->actingAs($this->owner)->post($this->dashUrl('/inventory/packaging-rules'), ['order_type' => $type, 'ingredient_id' => $material->id, 'quantity' => $qty])
                ->assertSessionHasNoErrors();
        }

        // dine-in: the recipe only, no cup, no lid, no bag
        $this->order($latte, 'dine_in', 2);
        $this->assertSame(['Milk' => -400.0], $this->stock());

        // takeaway: the cups and lids of each latte plus one bag for the order
        $takeaway = $this->order($latte, 'takeaway', 2);
        $this->assertSame(['Bag' => -1.0, 'Cup' => -2.0, 'Lid' => -2.0, 'Milk' => -800.0], $this->stock());

        // delivery: its own per-order packaging (a bag and two stickers)
        $this->order($latte, 'delivery', 1);
        $this->assertSame(['Bag' => -2.0, 'Cup' => -3.0, 'Lid' => -3.0, 'Milk' => -1000.0, 'Sticker' => -2.0], $this->stock());

        // cancelling the takeaway order gives back all of it, once
        $this->as($this->tenant, fn () => app(OrderService::class)->transition($takeaway->fresh(), 'cancelled'));
        $this->as($this->tenant, fn () => app(StockService::class)->restoreForOrder($takeaway->fresh()));
        $this->assertSame(['Bag' => -1.0, 'Cup' => -1.0, 'Lid' => -1.0, 'Milk' => -600.0, 'Sticker' => -2.0], $this->stock());

        // the item's cost with and without its takeaway packaging, and the cost of each order's packaging
        $page = $this->actingAs($this->owner)->get($this->dashUrl('/inventory/recipes'))->assertOk();
        $this->assertSame(10.0, round((float) $page->viewData('costs')[$latte->id], 2));
        $this->assertSame(12.5, round((float) $page->viewData('takeawayCosts')[$latte->id], 2));
        $this->assertSame(['takeaway' => 1.5, 'delivery' => 2.0], $page->viewData('orderPackaging'));
    }

    public function test_a_partial_refund_returns_the_items_packaging_but_not_the_orders_bag(): void
    {
        $cup = $this->material('Cup', 'packaging');
        $bag = $this->material('Bag', 'packaging');
        $latte = $this->item($this->tenant, 'Latte', 60);
        $this->as($this->tenant, function () use ($latte, $cup, $bag) {
            RecipeLine::query()->create(['menu_item_id' => $latte->id, 'ingredient_id' => $cup->id, 'quantity' => 1, 'takeaway_only' => true]);
            PackagingRule::query()->create(['ingredient_id' => $bag->id, 'order_type' => 'takeaway', 'quantity' => 1]);
        });
        $order = $this->order($latte, 'takeaway', 3);

        $this->as($this->tenant, function () use ($order) {
            $order = $order->fresh('items');
            app(StockService::class)->restoreLines($order, [$order->items->first()->id => 1]);
        });
        $this->assertSame(['Bag' => -1.0, 'Cup' => -2.0], $this->stock());
    }

    public function test_packaging_is_purchased_counted_and_filtered_like_an_ingredient(): void
    {
        $milk = $this->material('Milk', 'ingredient', 0.05);
        $cup = $this->material('Cup', 'packaging', 2);
        $supplier = $this->as($this->tenant, fn () => Supplier::query()->create(['name' => 'Pack Shop']));

        $this->actingAs($this->owner)->post($this->dashUrl('/inventory/purchases'), [
            'supplier_id' => $supplier->id, 'purchased_on' => now()->toDateString(), 'post' => 1,
            'lines' => [['ingredient_id' => $cup->id, 'quantity' => 100, 'unit_cost' => 2]],
        ])->assertSessionHasNoErrors();
        $this->assertSame('posted', $this->as($this->tenant, fn () => Purchase::query()->sole()->status));
        $this->assertSame(['Cup' => 100.0], $this->stock());

        // a count of the packaging only: 90 cups on the shelf
        $this->actingAs($this->owner)->post($this->dashUrl('/inventory/counts'), ['scope' => 'packaging'])->assertRedirect();
        $count = $this->as($this->tenant, fn () => StockCount::query()->with('lines')->sole());
        $this->assertSame([$cup->id], $count->lines->pluck('ingredient_id')->all());
        $this->actingAs($this->owner)->post($this->dashUrl("/inventory/counts/{$count->id}/post"), ['counted' => [$count->lines->first()->id => 90]])
            ->assertSessionHasNoErrors();
        $this->assertSame(['Cup' => 90.0], $this->stock());
        $this->assertSame(-10.0, $this->as($this->tenant, fn () => StockMovement::query()->where('type', 'count')->sole()->quantity));

        // the variance report totals packaging apart from the ingredients
        $totals = $this->actingAs($this->owner)->get($this->dashUrl('/inventory/variance'))->assertOk()->viewData('kindTotals');
        $this->assertSame([20.0, 0.0], [$totals['packaging']['unexplained'], $totals['ingredient']['unexplained']]);

        $this->actingAs($this->owner)->get($this->dashUrl('/inventory?kind=packaging'))->assertOk()->assertSee('Cup')->assertDontSee($milk->name);
    }
}
