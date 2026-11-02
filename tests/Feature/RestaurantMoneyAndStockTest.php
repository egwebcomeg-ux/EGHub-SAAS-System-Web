<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\DiningTable;
use App\Models\Expense;
use App\Models\Ingredient;
use App\Models\IngredientStock;
use App\Models\Order;
use App\Models\Shift;
use App\Models\StockMovement;
use App\Models\TableSession;
use App\Models\Tenant;
use App\Services\Inventory\StockService;
use Tests\TenantTestCase;

/** Expenses and net profit, stock moved between branches, and a table's bill shared between guests. */
class RestaurantMoneyAndStockTest extends TenantTestCase
{
    private function ingredient(Tenant $tenant, float $quantity): array
    {
        return $this->as($tenant, function () use ($quantity) {
            $main = Branch::query()->firstOrFail();
            $other = Branch::query()->create(['name' => 'Other', 'slug' => 'other']);
            $ingredient = Ingredient::query()->create(['name' => 'Flour', 'unit' => 'kg', 'cost_per_unit' => 10]);
            IngredientStock::query()->create(['ingredient_id' => $ingredient->id, 'branch_id' => $main->id, 'quantity' => $quantity]);

            return [$ingredient, $main, $other];
        });
    }

    private function stockAt(Tenant $tenant, Ingredient $ingredient, Branch $branch): float
    {
        return $this->as($tenant, fn () => (float) IngredientStock::query()->where('ingredient_id', $ingredient->id)->where('branch_id', $branch->id)->value('quantity'));
    }

    public function test_net_profit_takes_off_tax_expenses_and_the_drawer_for_the_chosen_month_only(): void
    {
        $tenant = $this->restaurant('cafe-a', 'business');
        $this->as($tenant, function () {
            $branch = Branch::query()->value('id');
            Order::query()->create(['branch_id' => $branch, 'number' => 1, 'status' => 'served', 'total' => 1000, 'tax' => 100]);
            Expense::query()->create(['category' => 'rent', 'amount' => 300, 'spent_on' => local_at()->toDateString()]);
            Expense::query()->create(['category' => 'rent', 'amount' => 999, 'spent_on' => local_at()->subYear()->toDateString()]);
        });
        $this->actingAs($this->owner($tenant));

        $profit = $this->get($this->dashUrl('/expenses'))->assertOk()->viewData('profit');

        $this->assertSame(600.0, $profit['net']);
        $this->assertSame(['rent' => 300.0], $profit['by_category']);
        $this->assertFalse($profit['costed']);
    }

    public function test_an_expense_is_saved_validated_and_deleted_within_the_restaurant(): void
    {
        $tenant = $this->restaurant('cafe-a', 'business');
        $other = $this->restaurant('cafe-b', 'business');
        $foreign = $this->as($other, fn () => Expense::query()->create(['category' => 'rent', 'amount' => 5, 'spent_on' => '2026-11-01']));
        $this->actingAs($this->owner($tenant));

        $this->post($this->dashUrl('/expenses'), ['category' => 'nope', 'amount' => 10, 'spent_on' => '2026-11-01'])->assertSessionHasErrors('category');
        $this->post($this->dashUrl('/expenses'), ['category' => 'bills', 'amount' => 0, 'spent_on' => '2026-11-01'])->assertSessionHasErrors('amount');
        $this->post($this->dashUrl('/expenses'), ['category' => 'bills', 'amount' => 50, 'spent_on' => '2026-11-01'])->assertSessionHasNoErrors();

        $mine = $this->as($tenant, fn () => Expense::query()->sole());
        $this->assertSame(50.0, $mine->amount);

        $this->post($this->dashUrl("/expenses/{$foreign->id}/delete"))->assertNotFound();
        $this->post($this->dashUrl("/expenses/{$mine->id}/delete"))->assertRedirect();
        $this->assertSame(0, $this->as($tenant, fn () => Expense::query()->count()));
    }

    public function test_only_who_may_see_money_opens_expenses_and_a_branch_user_sees_their_branch(): void
    {
        $tenant = $this->restaurant('cafe-a', 'business');
        $this->actingAs($this->staff($tenant, 'cashier', 'cashier@cafe.test'))->get($this->dashUrl('/expenses'))->assertForbidden();

        [$branch, $other] = $this->as($tenant, fn () => [Branch::query()->firstOrFail(), Branch::query()->create(['name' => 'Other', 'slug' => 'other'])]);
        $this->as($tenant, function () use ($branch) {
            Expense::query()->create(['category' => 'rent', 'amount' => 70, 'spent_on' => local_at()->toDateString(), 'branch_id' => $branch->id]);
            Expense::query()->create(['category' => 'rent', 'amount' => 80, 'spent_on' => local_at()->toDateString()]);
        });
        $manager = $this->staff($tenant, 'manager', 'manager@cafe.test');
        $manager->forceFill(['branch_id' => $other->id])->save();
        $this->actingAs($manager);

        $this->assertSame([], $this->get($this->dashUrl('/expenses'))->assertOk()->viewData('profit')['by_category']);

        $this->post($this->dashUrl('/expenses'), ['category' => 'bills', 'amount' => 5, 'spent_on' => local_at()->toDateString(), 'branch_id' => $branch->id])->assertSessionHasNoErrors();
        $this->assertSame($other->id, $this->as($tenant, fn () => Expense::query()->latest('id')->first()->branch_id));
    }

    public function test_a_transfer_moves_stock_between_the_restaurants_branches_at_the_same_cost(): void
    {
        $tenant = $this->restaurant('cafe-a', 'enterprise');
        [$ingredient, $main, $other] = $this->ingredient($tenant, 10);
        $this->actingAs($this->owner($tenant));

        $this->post($this->dashUrl('/inventory/transfer'), ['branch' => $main->id, 'ingredient_id' => $ingredient->id, 'to_branch' => $other->id, 'quantity' => 4])->assertSessionHasNoErrors();

        $this->assertSame(6.0, $this->stockAt($tenant, $ingredient, $main));
        $this->assertSame(4.0, $this->stockAt($tenant, $ingredient, $other));
        $this->assertSame(0.0, $this->as($tenant, fn () => (float) StockMovement::query()->where('type', 'transfer')->sum('quantity')));
        $this->assertSame(10.0, (float) $ingredient->fresh()->cost_per_unit);
    }

    public function test_a_transfer_is_refused_beyond_the_stock_to_the_same_branch_or_to_another_restaurant(): void
    {
        $tenant = $this->restaurant('cafe-a', 'enterprise');
        [$ingredient, $main, $other] = $this->ingredient($tenant, 3);
        $foreign = $this->as($this->restaurant('cafe-b', 'enterprise'), fn () => Branch::query()->value('id'));
        $this->actingAs($this->owner($tenant));
        $send = fn (int $to, float $quantity) => $this->post($this->dashUrl('/inventory/transfer'), ['branch' => $main->id, 'ingredient_id' => $ingredient->id, 'to_branch' => $to, 'quantity' => $quantity]);

        $send($other->id, 4)->assertSessionHasErrors('transfer');
        $send($main->id, 1)->assertSessionHasErrors('to_branch');
        $send($foreign, 1)->assertSessionHasErrors('to_branch');

        $this->assertSame(3.0, $this->stockAt($tenant, $ingredient, $main));
    }

    public function test_a_transfer_needs_a_manager_and_the_variance_report_is_not_thrown_off(): void
    {
        $tenant = $this->restaurant('cafe-a', 'enterprise');
        [$ingredient, $main, $other] = $this->ingredient($tenant, 10);
        $worker = $this->staff($tenant, 'kitchen', 'kitchen@cafe.test');
        $worker->givePermissionTo('inventory.manage');
        $this->actingAs($worker)->post($this->dashUrl('/inventory/transfer'), ['branch' => $main->id, 'ingredient_id' => $ingredient->id, 'to_branch' => $other->id, 'quantity' => 1])->assertSessionHasErrors('transfer');

        $this->as($tenant, fn () => app(StockService::class)->transfer($ingredient, $main, $other, 4, null));
        $this->actingAs($this->owner($tenant));
        $rows = $this->get($this->dashUrl('/inventory/variance?branch='.$other->id))->assertOk()->viewData('rows');

        $this->assertSame(0.0, collect($rows)->firstWhere('ingredient.id', $ingredient->id)['unexplained']);
    }

    /** @return array{0:int, 1:list<int>} the session and its orders */
    private function tableWithOrders(Tenant $tenant, array $totals): array
    {
        return $this->as($tenant, function () use ($totals) {
            $branch = Branch::query()->value('id');
            $table = DiningTable::query()->create(['branch_id' => $branch, 'name' => 'T-99', 'slug' => 't99', 'qr_token' => str_repeat('a', 24)]);
            $session = TableSession::query()->create(['branch_id' => $branch, 'dining_table_id' => $table->id, 'opened_at' => now()]);
            $ids = [];
            foreach ($totals as $i => $total) {
                $ids[] = Order::query()->create(['branch_id' => $branch, 'number' => $i + 1, 'status' => 'served', 'total' => $total, 'table_session_id' => $session->id, 'dining_table_id' => $table->id])->id;
            }

            return [$session->id, $ids];
        });
    }

    public function test_guests_share_a_tables_bill_across_its_orders_and_the_parts_must_add_up(): void
    {
        $tenant = $this->restaurant('cafe-a', 'full');
        [$session, [$first, $second]] = $this->tableWithOrders($tenant, [100, 50]);
        $owner = $this->owner($tenant);
        $this->as($tenant, fn () => Shift::query()->create(['branch_id' => Branch::query()->value('id'), 'user_id' => $owner->id, 'opening_cash' => 0, 'opened_at' => now()]));
        $this->actingAs($owner);
        $url = $this->dashUrl("/orders/tables/sessions/{$session}/pay");

        $this->postJson($url, ['parts' => [['method' => 'cash', 'amount' => 70], ['method' => 'card', 'amount' => 70]]])->assertStatus(422);
        $this->assertSame('unpaid', $this->as($tenant, fn () => Order::query()->findOrFail($first)->payment_status));

        $this->postJson($url, ['parts' => [['method' => 'cash', 'amount' => 75], ['method' => 'card', 'amount' => 75]]])->assertOk()->assertJsonPath('paid_orders', 2);

        [$a, $b] = $this->as($tenant, fn () => [Order::query()->findOrFail($first), Order::query()->findOrFail($second)]);
        $this->assertSame('split', $a->payment_method);
        $this->assertSame([['method' => 'cash', 'amount' => 75], ['method' => 'card', 'amount' => 25]], $a->payments);
        $this->assertSame('card', $b->payment_method);
        $this->assertSame('paid', $b->payment_status);
    }

    public function test_one_payment_for_the_table_still_works_and_a_balance_cannot_be_a_part(): void
    {
        $tenant = $this->restaurant('cafe-a', 'full');
        [$session] = $this->tableWithOrders($tenant, [100]);
        $owner = $this->owner($tenant);
        $this->as($tenant, fn () => Shift::query()->create(['branch_id' => Branch::query()->value('id'), 'user_id' => $owner->id, 'opening_cash' => 0, 'opened_at' => now()]));
        $this->actingAs($owner);
        $url = $this->dashUrl("/orders/tables/sessions/{$session}/pay");

        $this->postJson($url, ['parts' => [['method' => 'credit', 'amount' => 100]]])->assertStatus(422);
        $this->postJson($url, ['method' => 'cash'])->assertOk()->assertJsonPath('paid_orders', 1);
    }
}
