<?php

namespace Tests\Feature;

use App\Billing\Billing;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Ingredient;
use App\Models\IngredientStock;
use App\Models\Purchase;
use App\Models\RecipeLine;
use App\Models\StockCount;
use App\Models\StockCountLine;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Inventory\StockService;
use App\Services\Orders\OrderService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/** Stock counts, suppliers and purchases, the variance report and the food-cost target of the inventory pack. */
class InventoryOperationsTest extends TenantTestCase
{
    private Tenant $tenant;

    private User $owner;

    /** A cashier the owner gave inventory.manage on top of the role: does the daily stock work, is not a manager. */
    private User $storekeeper;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->restaurant('cafe-a', 'basic');
        app(Billing::class)->setAddon($this->tenant, 'inventory_pack', 1);
        $this->owner = $this->owner($this->tenant);
        $this->storekeeper = $this->staff($this->tenant, 'cashier', 'store@cafe-a.test');
        $this->as($this->tenant, fn () => $this->storekeeper->givePermissionTo('inventory.manage'));
        $this->branch = $this->as($this->tenant, fn () => Branch::query()->first());
    }

    private function ingredient(string $name, float $cost, string $kind = 'ingredient'): Ingredient
    {
        return $this->as($this->tenant, fn () => Ingredient::query()->create(['name' => $name, 'unit' => 'ml', 'cost_per_unit' => $cost, 'kind' => $kind]));
    }

    private function receive(Ingredient $ingredient, float $qty): void
    {
        $this->as($this->tenant, fn () => app(StockService::class)->purchase($ingredient->fresh(), $this->branch, $qty, $qty * $ingredient->cost_per_unit, null));
    }

    private function stock(Ingredient $ingredient): float
    {
        return $this->as($this->tenant, fn () => (float) IngredientStock::query()->where('ingredient_id', $ingredient->id)->value('quantity'));
    }

    /** Sells $qty lattes that use $ml of $milk each. */
    private function sellLattes(Ingredient $milk, float $ml, int $qty): void
    {
        $latte = $this->item($this->tenant, 'Latte', 60);
        $this->as($this->tenant, function () use ($latte, $milk, $ml, $qty) {
            RecipeLine::query()->create(['menu_item_id' => $latte->id, 'ingredient_id' => $milk->id, 'quantity' => $ml]);
            app(OrderService::class)->place([
                'client_uuid' => (string) Str::uuid(), 'source' => 'pos', 'type' => 'takeaway', 'items' => [['item_id' => $latte->id, 'qty' => $qty]],
            ], $this->branch);
        });
    }

    public function test_a_count_is_saved_as_a_draft_and_only_a_manager_posts_its_differences(): void
    {
        $milk = $this->ingredient('Milk', 0.05);
        $coffee = $this->ingredient('Coffee', 0.8);
        $this->ingredient('Sugar', 0.01);
        $this->receive($milk, 1000);

        $this->actingAs($this->storekeeper)->post($this->dashUrl('/inventory/counts'), ['scope' => 'some', 'ingredient_ids' => [$milk->id, $coffee->id]])
            ->assertRedirect();
        $count = $this->as($this->tenant, fn () => StockCount::query()->with('lines')->sole());
        $this->assertSame(2, $count->lines->count());
        $milkLine = $count->lines->firstWhere('ingredient_id', $milk->id);
        $coffeeLine = $count->lines->firstWhere('ingredient_id', $coffee->id);

        // the storekeeper counts 950 ml (the system says 1000) and leaves the coffee for later: nothing moves yet
        $this->actingAs($this->storekeeper)->post($this->dashUrl("/inventory/counts/{$count->id}"), ['counted' => [$milkLine->id => 950, $coffeeLine->id => '']])
            ->assertSessionHasNoErrors();
        $this->assertSame(1000.0, $this->stock($milk));
        $this->actingAs($this->storekeeper)->get($this->dashUrl("/inventory/counts/{$count->id}"))->assertOk()
            ->assertSee('data-count-shortage>2.5<', false)->assertDontSee('data-count-post', false);

        // 2 lattes are sold after the counting: the shelf and the system both drop by 200, so the shortage stays 50
        $this->sellLattes($milk, 100, 2);
        $this->assertSame(800.0, $this->stock($milk));

        $this->actingAs($this->storekeeper)->post($this->dashUrl("/inventory/counts/{$count->id}/post"), ['counted' => [$milkLine->id => 950]])->assertForbidden();
        $this->assertTrue($this->as($this->tenant, fn () => $count->fresh()->isDraft()));

        $this->actingAs($this->owner)->post($this->dashUrl("/inventory/counts/{$count->id}/post"), ['counted' => [$milkLine->id => 950, $coffeeLine->id => '']])
            ->assertSessionHasNoErrors();
        $this->assertSame(750.0, $this->stock($milk));
        $this->assertSame(0.0, $this->stock($coffee)); // not counted: untouched
        $movement = $this->as($this->tenant, fn () => StockMovement::query()->where('type', 'count')->sole());
        $this->assertSame([-50.0, $count->id], [$movement->quantity, $movement->stock_count_id]);
        $line = $this->as($this->tenant, fn () => StockCountLine::query()->find($milkLine->id));
        $this->assertSame([-50.0, 0.05], [$line->adjustment, $line->unit_cost]);
        $audit = $this->as($this->tenant, fn () => AuditLog::query()->where('action', 'stock.count_posted')->sole());
        $this->assertSame([$this->owner->id, -2.5], [$audit->user_id, $audit->meta['value']]);

        // posted: read-only, and posting again moves nothing
        $this->actingAs($this->owner)->post($this->dashUrl("/inventory/counts/{$count->id}/post"), ['counted' => [$milkLine->id => 10]])->assertSessionHasErrors('count');
        $this->actingAs($this->storekeeper)->post($this->dashUrl("/inventory/counts/{$count->id}"), ['counted' => [$milkLine->id => 10]])->assertSessionHasErrors('count');
        $this->actingAs($this->owner)->post($this->dashUrl("/inventory/counts/{$count->id}/delete"))->assertSessionHasErrors('count');
        $this->assertSame(750.0, $this->stock($milk));
        $this->assertSame(1, $this->as($this->tenant, fn () => StockMovement::query()->where('type', 'count')->count()));
    }

    public function test_two_sheets_counting_the_same_shelf_correct_it_once(): void
    {
        $milk = $this->ingredient('Milk', 0.05);
        $this->receive($milk, 1000);
        foreach ([1, 2] as $n) {
            $this->actingAs($this->owner)->post($this->dashUrl('/inventory/counts'), ['scope' => 'some', 'ingredient_ids' => [$milk->id]])->assertRedirect();
        }
        $sheets = $this->as($this->tenant, fn () => StockCount::query()->with('lines')->orderBy('id')->get());
        // both find 950 on the shelf (the system says 1000)
        foreach ($sheets as $sheet) {
            $this->actingAs($this->owner)->post($this->dashUrl("/inventory/counts/{$sheet->id}"), ['counted' => [$sheet->lines->sole()->id => 950]])->assertSessionHasNoErrors();
        }
        $this->travel(1)->minutes();

        foreach ($sheets as $sheet) {
            $this->actingAs($this->owner)->post($this->dashUrl("/inventory/counts/{$sheet->id}/post"))->assertSessionHasNoErrors();
        }
        // the second sheet sees the first one's correction: the stock is the shelf, not 50 less
        $this->assertSame(950.0, $this->stock($milk));
    }

    public function test_a_count_sheet_needs_an_ingredient_and_a_counted_quantity_to_post(): void
    {
        $this->ingredient('Milk', 0.05);

        $this->actingAs($this->owner)->post($this->dashUrl('/inventory/counts'), ['scope' => 'some', 'ingredient_ids' => []])->assertSessionHasErrors('ingredient_ids');
        $this->actingAs($this->owner)->post($this->dashUrl('/inventory/counts'), ['scope' => 'all'])->assertRedirect();
        $count = $this->as($this->tenant, fn () => StockCount::query()->sole());
        $this->actingAs($this->owner)->post($this->dashUrl("/inventory/counts/{$count->id}/post"))
            ->assertSessionHasErrors(['count' => __('Enter at least one counted quantity before posting.')]);
        $this->assertTrue($this->as($this->tenant, fn () => $count->fresh()->isDraft()));
    }

    public function test_the_quick_count_on_the_stock_page_is_for_managers_only(): void
    {
        $milk = $this->ingredient('Milk', 0.05);
        $this->receive($milk, 1000);

        $this->actingAs($this->storekeeper)->post($this->dashUrl('/inventory/move'), ['type' => 'count', 'ingredient_id' => $milk->id, 'quantity' => 10])
            ->assertSessionHasErrors('type');
        $this->actingAs($this->storekeeper)->post($this->dashUrl('/inventory/move'), ['type' => 'waste', 'ingredient_id' => $milk->id, 'quantity' => 10])
            ->assertSessionHasNoErrors();
        $this->assertSame(990.0, $this->stock($milk));
    }

    public function test_a_posted_purchase_adds_stock_at_the_weighted_average_cost_and_only_a_manager_cancels_it(): void
    {
        $milk = $this->ingredient('Milk', 0.04);
        $coffee = $this->ingredient('Coffee', 0.8);
        $this->receive($milk, 1000);
        $this->actingAs($this->storekeeper)->post($this->dashUrl('/inventory/suppliers'), ['name' => 'Dairy Co', 'phone' => '01000000000'])->assertSessionHasNoErrors();
        $supplier = $this->as($this->tenant, fn () => Supplier::query()->sole());

        $this->actingAs($this->storekeeper)->post($this->dashUrl('/inventory/purchases'), [
            'supplier_id' => $supplier->id, 'purchased_on' => now()->toDateString(), 'reference' => 'INV-77',
            'lines' => [
                ['ingredient_id' => $milk->id, 'quantity' => 1000, 'unit_cost' => 0.06],
                ['ingredient_id' => $coffee->id, 'quantity' => 500, 'unit_cost' => 0.8],
                ['ingredient_id' => '', 'quantity' => '', 'unit_cost' => ''],
            ],
        ])->assertSessionHasNoErrors();
        $purchase = $this->as($this->tenant, fn () => Purchase::query()->sole());
        $this->assertSame(['draft', 460.0, $this->branch->id], [$purchase->status, $purchase->total, $purchase->branch_id]);
        $this->assertSame(1000.0, $this->stock($milk)); // a draft moves nothing

        // posting (daily work, no manager needed): + 1000 ml at 0.06 → (1000 × 0.04 + 60) / 2000 = 0.05
        $this->actingAs($this->storekeeper)->post($this->dashUrl("/inventory/purchases/{$purchase->id}/post"))->assertSessionHasNoErrors();
        $this->assertSame(2000.0, $this->stock($milk));
        $this->assertSame(500.0, $this->stock($coffee));
        $this->assertEqualsWithDelta(0.05, $this->as($this->tenant, fn () => $milk->fresh()->cost_per_unit), 0.00001);
        $this->assertSame(2, $this->as($this->tenant, fn () => StockMovement::query()->where('purchase_id', $purchase->id)->count()));
        $this->assertTrue($this->as($this->tenant, fn () => AuditLog::query()->where('action', 'stock.purchase_posted')->exists()));

        // posted: the lines cannot change and only a manager cancels
        $this->actingAs($this->storekeeper)->post($this->dashUrl("/inventory/purchases/{$purchase->id}"), [
            'supplier_id' => $supplier->id, 'purchased_on' => now()->toDateString(), 'lines' => [['ingredient_id' => $milk->id, 'quantity' => 1, 'unit_cost' => 1]],
        ])->assertSessionHasErrors('purchase');
        $this->assertSame(460.0, $this->as($this->tenant, fn () => $purchase->fresh()->total));
        $this->actingAs($this->storekeeper)->post($this->dashUrl("/inventory/purchases/{$purchase->id}/cancel"), ['reason' => 'Typed twice'])->assertForbidden();

        $this->actingAs($this->owner)->post($this->dashUrl("/inventory/purchases/{$purchase->id}/cancel"), ['reason' => 'Typed twice'])->assertSessionHasNoErrors();
        $this->assertSame(1000.0, $this->stock($milk));
        $this->assertSame(0.0, $this->stock($coffee));
        $this->assertEqualsWithDelta(0.04, $this->as($this->tenant, fn () => $milk->fresh()->cost_per_unit), 0.00001);
        $this->assertSame('cancelled', $this->as($this->tenant, fn () => $purchase->fresh()->status));
        $audit = $this->as($this->tenant, fn () => AuditLog::query()->where('action', 'stock.purchase_cancelled')->sole());
        $this->assertSame([$this->owner->id, 'Typed twice'], [$audit->user_id, $audit->meta['reason']]);

        // cancelling twice takes nothing out twice
        $this->actingAs($this->owner)->post($this->dashUrl("/inventory/purchases/{$purchase->id}/cancel"), ['reason' => 'Again'])->assertSessionHasErrors('reason');
        $this->assertSame(1000.0, $this->stock($milk));
    }

    public function test_the_supplier_statement_counts_posted_purchases_only_and_downloads(): void
    {
        $milk = $this->ingredient('Milk', 0.05);
        [$supplier, $other] = $this->as($this->tenant, fn () => [Supplier::query()->create(['name' => 'Dairy Co']), Supplier::query()->create(['name' => 'Other'])]);
        $make = function (Supplier $s, string $ref, float $qty) use ($milk) {
            $this->actingAs($this->owner)->post($this->dashUrl('/inventory/purchases'), [
                'supplier_id' => $s->id, 'purchased_on' => now()->toDateString(), 'reference' => $ref, 'post' => 1,
                'lines' => [['ingredient_id' => $milk->id, 'quantity' => $qty, 'unit_cost' => 0.05]],
            ])->assertSessionHasNoErrors();

            return $this->as($this->tenant, fn () => Purchase::query()->where('reference', $ref)->sole());
        };
        $make($supplier, 'INV-1', 1000);
        $cancelled = $make($supplier, 'INV-2', 2000);
        $make($other, 'INV-OTHER', 4000);
        $this->actingAs($this->owner)->post($this->dashUrl("/inventory/purchases/{$cancelled->id}/cancel"), ['reason' => 'Returned']);

        $this->actingAs($this->owner)->get($this->dashUrl("/inventory/suppliers/{$supplier->id}"))->assertOk()
            ->assertSee('data-supplier-total>50<', false)->assertSee('INV-2')->assertDontSee('INV-OTHER');
        $csv = $this->actingAs($this->owner)->get($this->dashUrl("/inventory/suppliers/{$supplier->id}?format=csv"))->assertOk()->streamedContent();
        $this->assertStringContainsString('INV-1', $csv);
        $this->assertStringNotContainsString('INV-OTHER', $csv);
    }

    public function test_the_variance_report_splits_the_period_by_movement_and_puts_the_biggest_loss_first(): void
    {
        $milk = $this->ingredient('Milk', 0.05);
        $saffron = $this->ingredient('Saffron', 10);
        $this->travelTo(Carbon::parse('2026-10-01 12:00', 'Africa/Cairo'));
        $this->receive($milk, 1000);
        $this->receive($saffron, 10);

        $this->travelTo(Carbon::parse('2026-10-05 12:00', 'Africa/Cairo'));
        $this->sellLattes($milk, 100, 2);                        // 200 ml by the recipes
        $this->as($this->tenant, function () use ($milk, $saffron) {
            $stock = app(StockService::class);
            $stock->waste($milk->fresh(), $this->branch, 50, null);
            $stock->count($milk->fresh(), $this->branch, 700, null);     // expected 750: 50 ml (2.50) unexplained
            $stock->count($saffron->fresh(), $this->branch, 8, null);    // 2 g of saffron (20.00) gone
        });

        $this->travelTo(Carbon::parse('2026-10-20 12:00', 'Africa/Cairo'));         // after the period: not in it
        $this->receive($milk, 500);

        $response = $this->actingAs($this->owner)->get($this->dashUrl('/inventory/variance?from=2026-10-02&to=2026-10-10'))->assertOk();
        $rows = $response->viewData('rows');
        $this->assertSame(['Saffron', 'Milk'], array_map(fn ($r) => $r['ingredient']->name, $rows));
        $this->assertSame(
            ['opening' => 1000.0, 'purchases' => 0.0, 'sales' => 200.0, 'waste' => 50.0, 'counts' => -50.0, 'closing' => 700.0, 'actual' => 300.0, 'unexplained' => 50.0, 'unexplained_cost' => 2.5],
            array_intersect_key($rows[1], array_flip(['opening', 'purchases', 'sales', 'waste', 'counts', 'closing', 'actual', 'unexplained', 'unexplained_cost'])),
        );
        $this->assertSame(22.5, $response->viewData('totals')['unexplained']);

        $csv = $this->actingAs($this->owner)->get($this->dashUrl('/inventory/variance?from=2026-10-02&to=2026-10-10&format=csv'))->assertOk()->streamedContent();
        $this->assertStringContainsString('Saffron', $csv);
        $this->assertStringContainsString(__('Unexplained difference'), $csv);
    }

    public function test_items_above_the_food_cost_target_are_flagged(): void
    {
        $milk = $this->ingredient('Milk', 0.04);
        $latte = $this->item($this->tenant, 'Latte', 20);
        $this->as($this->tenant, fn () => RecipeLine::query()->create(['menu_item_id' => $latte->id, 'ingredient_id' => $milk->id, 'quantity' => 200])); // 8 = 40%

        $this->actingAs($this->owner)->get($this->dashUrl('/inventory/recipes'))->assertOk()->assertSee('data-above-target', false);
        $this->actingAs($this->owner)->post($this->dashUrl('/inventory/food-cost-target'), ['food_cost_target' => 0])->assertSessionHasErrors('food_cost_target');
        $this->actingAs($this->owner)->post($this->dashUrl('/inventory/food-cost-target'), ['food_cost_target' => 45])->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->get($this->dashUrl('/inventory/recipes'))->assertOk()->assertDontSee('data-above-target', false);
    }

    public function test_counts_purchases_and_suppliers_of_another_restaurant_or_branch_are_not_found(): void
    {
        $b = $this->restaurant('cafe-b', 'basic');
        app(Billing::class)->setAddon($b, 'inventory_pack', 1);
        [$foreignCount, $foreignPurchase, $foreignSupplier, $foreignMilk] = $this->as($b, function () {
            $branch = Branch::query()->first();
            $milk = Ingredient::query()->create(['name' => 'B milk', 'unit' => 'ml']);
            $supplier = Supplier::query()->create(['name' => 'B supplier']);

            return [StockCount::query()->create(['branch_id' => $branch->id]),
                Purchase::query()->create(['supplier_id' => $supplier->id, 'branch_id' => $branch->id, 'purchased_on' => now()]), $supplier, $milk];
        });

        $this->actingAs($this->owner)->get($this->dashUrl("/inventory/counts/{$foreignCount->id}"))->assertNotFound();
        $this->actingAs($this->owner)->post($this->dashUrl("/inventory/purchases/{$foreignPurchase->id}/post"))->assertNotFound();
        $this->actingAs($this->owner)->get($this->dashUrl("/inventory/suppliers/{$foreignSupplier->id}"))->assertNotFound();
        $supplier = $this->as($this->tenant, fn () => Supplier::query()->create(['name' => 'Dairy Co']));
        $this->actingAs($this->owner)->post($this->dashUrl('/inventory/purchases'), [
            'supplier_id' => $supplier->id, 'purchased_on' => now()->toDateString(), 'lines' => [['ingredient_id' => $foreignMilk->id, 'quantity' => 5, 'unit_cost' => 1]],
        ])->assertSessionHasErrors('lines.0.ingredient_id');
        $this->actingAs($this->owner)->post($this->dashUrl('/inventory/purchases'), ['supplier_id' => $foreignSupplier->id, 'purchased_on' => now()->toDateString()])
            ->assertSessionHasErrors('supplier_id');

        // staff tied to one branch do not see another branch's count sheet
        $second = $this->as($this->tenant, fn () => Branch::query()->create(['name' => 'Second', 'slug' => 'second']));
        $otherCount = $this->as($this->tenant, fn () => StockCount::query()->create(['branch_id' => $second->id]));
        $this->as($this->tenant, fn () => $this->storekeeper->forceFill(['branch_id' => $this->branch->id])->save());
        $this->actingAs($this->storekeeper)->get($this->dashUrl("/inventory/counts/{$otherCount->id}"))->assertNotFound();
    }

    public function test_the_new_pages_are_closed_without_the_inventory_pack(): void
    {
        $plain = $this->restaurant('cafe-plain', 'business');

        $this->actingAs($this->owner($plain))->get($this->dashUrl('/inventory/counts'))->assertForbidden();
        $this->actingAs($this->owner($plain))->get($this->dashUrl('/inventory/purchases'))->assertForbidden();
        $this->actingAs($this->owner($plain))->get($this->dashUrl('/inventory/variance'))->assertForbidden();
    }

    public function test_every_inventory_page_opens(): void
    {
        $this->ingredient('Milk', 0.05);
        $supplier = $this->as($this->tenant, fn () => Supplier::query()->create(['name' => 'Dairy Co']));
        $this->actingAs($this->owner)->post($this->dashUrl('/inventory/counts'), ['scope' => 'all']);
        $count = $this->as($this->tenant, fn () => StockCount::query()->sole());

        foreach (['/inventory', '/inventory/counts', "/inventory/counts/{$count->id}", '/inventory/purchases', '/inventory/purchases/create',
            '/inventory/suppliers', "/inventory/suppliers/{$supplier->id}", '/inventory/movements', '/inventory/variance', '/inventory/recipes'] as $path) {
            $this->actingAs($this->owner)->get($this->dashUrl($path))->assertOk()->assertSee(__('Stock counts'));
        }
    }
}
