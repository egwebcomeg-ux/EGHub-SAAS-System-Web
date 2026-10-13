<?php

namespace Tests\Feature;

use App\Billing\Billing;
use App\Models\Branch;
use App\Models\Ingredient;
use App\Models\IngredientStock;
use App\Models\ItemOption;
use App\Models\ItemOptionGroup;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\RecipeLine;
use App\Models\StockMovement;
use App\Models\Tenant;
use App\Services\Orders\OrderService;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

class InventoryTest extends TenantTestCase
{
    private function withInventory(string $slug, string $plan = 'basic'): Tenant
    {
        $tenant = $this->restaurant($slug, $plan);
        app(Billing::class)->setAddon($tenant, 'inventory_pack', 1);
        return $tenant;
    }

    private function stock(Tenant $tenant, Ingredient $ingredient): float
    {
        return $this->as($tenant, fn () => (float) IngredientStock::query()->where('ingredient_id', $ingredient->id)->value('quantity'));
    }

    public function test_orders_deduct_recipes_with_options_and_cancel_restores(): void
    {
        $a = $this->withInventory('cafe-a');
        $latte = $this->item($a, 'Latte', 60);
        $owner = $this->owner($a);
        $branch = $this->as($a, fn () => Branch::query()->first());

        [$milk, $coffee, $large] = $this->as($a, function () use ($latte) {
            $milk = Ingredient::query()->create(['name' => 'Milk', 'unit' => 'ml', 'cost_per_unit' => 0.04, 'min_stock' => 500]);
            $coffee = Ingredient::query()->create(['name' => 'Coffee', 'unit' => 'g', 'cost_per_unit' => 0.8]);
            $group = ItemOptionGroup::query()->create(['menu_item_id' => $latte->id, 'name' => 'Size', 'type' => 'single']);
            $large = ItemOption::query()->create(['item_option_group_id' => $group->id, 'name' => 'Large', 'price' => 10]);
            return [$milk, $coffee, $large];
        });

        // receive 2000 ml milk for 100 EGP; average cost becomes 0.05
        $this->actingAs($owner)->post($this->dashUrl('/inventory/move'), ['type' => 'purchase', 'ingredient_id' => $milk->id, 'quantity' => 2000, 'total_cost' => 100])
            ->assertSessionHasNoErrors();
        $this->assertSame(2000.0, $this->stock($a, $milk));
        $this->assertEqualsWithDelta(0.05, $this->as($a, fn () => $milk->fresh()->cost_per_unit), 0.0001);

        // recipe: 200 ml milk + 18 g coffee; large adds 100 ml milk
        $this->actingAs($owner)->post($this->dashUrl("/inventory/recipes/{$latte->id}"), ['lines' => [
            ['ingredient_id' => $milk->id, 'quantity' => 200],
            ['ingredient_id' => $coffee->id, 'quantity' => 18],
            ['ingredient_id' => $milk->id, 'quantity' => 100, 'option_id' => $large->id],
            ['ingredient_id' => '', 'quantity' => ''],
        ]])->assertSessionHasNoErrors();
        $this->assertSame(3, $this->as($a, fn () => RecipeLine::query()->count()));

        // 2 large lattes: milk 2 × 300 = 600, coffee 36
        $order = $this->as($a, fn () => app(OrderService::class)->place([
            'client_uuid' => (string) Str::uuid(), 'source' => 'pos', 'type' => 'takeaway',
            'items' => [['item_id' => $latte->id, 'qty' => 2, 'option_ids' => [$large->id]]],
        ], $branch));
        $this->assertSame(1400.0, $this->stock($a, $milk));
        $this->assertSame(-36.0, $this->stock($a, $coffee)); // never received: goes negative, the count fixes it

        $this->as($a, fn () => app(OrderService::class)->transition($order->fresh(), 'cancelled'));
        $this->assertSame(2000.0, $this->stock($a, $milk));
        $this->assertSame(0.0, $this->stock($a, $coffee));
        // cancelling twice cannot give the stock back twice
        $this->as($a, fn () => app(\App\Services\Inventory\StockService::class)->restoreForOrder($order->fresh()));
        $this->assertSame(2000.0, $this->stock($a, $milk));

        // a count sets the shelf quantity
        $this->actingAs($owner)->post($this->dashUrl('/inventory/move'), ['type' => 'count', 'ingredient_id' => $milk->id, 'quantity' => 450]);
        $this->assertSame(450.0, $this->stock($a, $milk));
        $this->assertSame(1, $this->as($a, fn () => StockMovement::query()->where('type', 'count')->count()));

        // under its alert level: shown on the dashboard home
        $this->actingAs($owner)->get($this->dashUrl('/'))->assertSee('1 خامات قربت تخلص');
        $this->actingAs($owner)->get($this->dashUrl('/inventory/recipes'))->assertOk()->assertSee('Latte');
        $this->actingAs($owner)->get($this->dashUrl('/inventory/movements'))->assertOk()->assertSee('#' . $order->number);
    }

    public function test_without_the_pack_nothing_is_deducted_and_pages_are_closed(): void
    {
        $plain = $this->restaurant('cafe-plain', 'business');
        $latte = $this->item($plain, 'Latte', 60);
        $this->as($plain, function () use ($latte) {
            $milk = Ingredient::query()->create(['name' => 'Milk', 'unit' => 'ml']);
            RecipeLine::query()->create(['menu_item_id' => $latte->id, 'ingredient_id' => $milk->id, 'quantity' => 200]);
            app(OrderService::class)->place(['source' => 'pos', 'items' => [['item_id' => $latte->id, 'qty' => 1]]], Branch::query()->first());
        });
        $this->assertSame(0, StockMovement::query()->withoutGlobalScopes()->count());
        $this->actingAs($this->owner($plain))->get($this->dashUrl('/inventory'))->assertForbidden();
    }

    public function test_other_restaurants_ingredients_cannot_be_used(): void
    {
        $a = $this->withInventory('cafe-a');
        $b = $this->withInventory('cafe-b');
        $latte = $this->item($a, 'Latte', 60);
        $foreign = $this->as($b, fn () => Ingredient::query()->create(['name' => 'B milk', 'unit' => 'ml']));
        $owner = $this->owner($a);

        $this->actingAs($owner)->post($this->dashUrl('/inventory/move'), ['type' => 'purchase', 'ingredient_id' => $foreign->id, 'quantity' => 5])
            ->assertSessionHasErrors('ingredient_id');
        $this->actingAs($owner)->post($this->dashUrl("/inventory/recipes/{$latte->id}"), ['lines' => [['ingredient_id' => $foreign->id, 'quantity' => 5]]])
            ->assertSessionHasErrors('lines.0.ingredient_id');
        $this->actingAs($owner)->post($this->dashUrl("/inventory/ingredients/{$foreign->id}"), ['name' => 'X', 'unit' => 'g'])->assertNotFound();
        $bItem = $this->item($b, 'B item', 5);
        $this->actingAs($owner)->get($this->dashUrl("/inventory/recipes/{$bItem->id}"))->assertNotFound();
        $this->assertSame(0, StockMovement::query()->withoutGlobalScopes()->count());
    }
}
