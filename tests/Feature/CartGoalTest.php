<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\DiningTable;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Tenant;
use App\Services\Settings;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/** "Add X and get Y free": the menu shows the progress, the server alone decides the reward with its own prices. */
class CartGoalTest extends TenantTestCase
{
    private MenuItem $latte;

    private MenuItem $croissant;

    private function setUpGoal(string $plan = 'pro', array $goal = []): Tenant
    {
        $a = $this->restaurant('cafe-a', $plan);
        $this->latte = $this->item($a, 'Latte', 60);
        $this->croissant = $this->item($a, 'Croissant', 35);
        $this->as($a, fn () => Settings::put($goal + [
            'cart_goal_enabled' => '1', 'cart_goal_amount' => '150', 'cart_goal_reward' => 'item',
            'cart_goal_item' => (string) $this->croissant->id, 'online_takeaway' => '1',
        ]));

        return $a;
    }

    private function orderOnline(int $qty, array $extra = [])
    {
        return $this->postJson($this->subUrl('cafe-a', 'order'), $extra + [
            'client_uuid' => (string) Str::uuid(), 'type' => 'takeaway', 'customer_phone' => '01012345678',
            'items' => [['item_id' => $this->latte->id, 'qty' => $qty]],
        ]);
    }

    private function lastOrder(Tenant $a): Order
    {
        return $this->as($a, fn () => Order::query()->with('items')->latest('id')->first());
    }

    public function test_the_menu_shows_the_goal_with_a_generated_label(): void
    {
        $this->setUpGoal();
        $this->get($this->subUrl('cafe-a'))->assertOk()
            ->assertSee('data-ticker-line="goal"', false)->assertSee('data-goal-box', false)
            ->assertSee('اطلب بـ 150 ج.م وخد Croissant ببلاش')
            ->assertSee('"goal":{"amount":150', false)->assertSee('"reward":"item"', false);
    }

    public function test_reaching_the_goal_adds_the_free_item_at_zero(): void
    {
        $a = $this->setUpGoal();
        $this->orderOnline(3)->assertOk(); // 3 × 60 = 180 ≥ 150

        $order = $this->lastOrder($a);
        $this->assertCount(2, $order->items);
        $gift = $order->items->firstWhere('menu_item_id', $this->croissant->id);
        $this->assertSame(0.0, (float) $gift->unit_price);
        $this->assertSame(1, $gift->quantity);
        $this->assertStringContainsString('🎁', $gift->notes);
        $this->assertSame(180.0, $order->subtotal);
        $this->assertSame(180.0, $order->total);
        $this->assertNotNull($order->goal_reward);
        $this->get($this->subUrl('cafe-a', "o/{$order->tracking_token}"))->assertOk()->assertSee('data-goal-reward', false);
    }

    public function test_below_the_goal_nothing_is_given_and_client_tampering_is_ignored(): void
    {
        $a = $this->setUpGoal();
        // 2 × 60 = 120 < 150, whatever the client claims about prices or the reward
        $this->orderOnline(2, ['goal' => true, 'discount' => 50, 'items' => [
            ['item_id' => $this->latte->id, 'qty' => 2, 'price' => 100],
        ]])->assertOk();
        $order = $this->lastOrder($a);
        $this->assertCount(1, $order->items);
        $this->assertSame(0.0, $order->discount);
        $this->assertSame(0.0, $order->goal_discount);
        $this->assertNull($order->goal_reward);
        $this->assertSame(120.0, $order->total);
    }

    public function test_a_percent_reward_comes_off_and_does_not_stack_with_a_coupon_by_default(): void
    {
        $a = $this->setUpGoal('pro', ['cart_goal_reward' => 'percent', 'cart_goal_value' => '10']);
        $this->orderOnline(3)->assertOk();
        $order = $this->lastOrder($a);
        $this->assertSame(18.0, $order->goal_discount);
        $this->assertSame(162.0, $order->total);

        $this->as($a, fn () => Coupon::query()->create(['code' => 'SAVE20', 'source' => 'voucher', 'type' => 'amount', 'value' => 20, 'once_per_customer' => false]));
        $this->orderOnline(3, ['coupon' => 'SAVE20'])->assertOk();
        $withCoupon = $this->lastOrder($a);
        $this->assertSame(20.0, $withCoupon->coupon_discount);
        $this->assertSame(0.0, $withCoupon->goal_discount);
        $this->assertSame(160.0, $withCoupon->total);

        // the owner lets both apply: the goal is measured on the food before the coupon
        $this->as($a, fn () => Settings::put(['cart_goal_with_coupon' => '1']));
        $this->orderOnline(3, ['coupon' => 'SAVE20'])->assertOk();
        $both = $this->lastOrder($a);
        $this->assertSame(20.0, $both->coupon_discount);
        $this->assertSame(18.0, $both->goal_discount);
        $this->assertSame(142.0, $both->total);
    }

    public function test_table_orders_get_it_and_the_feature_gates_it(): void
    {
        $a = $this->setUpGoal('pro', ['cart_goal_reward' => 'amount', 'cart_goal_value' => '25']);
        $table = $this->as($a, fn () => DiningTable::query()->first());
        $this->get($this->subUrl('cafe-a', "t/{$table->qr_token}"));
        $this->postJson($this->subUrl('cafe-a', 'order'), ['client_uuid' => (string) Str::uuid(), 'items' => [['item_id' => $this->latte->id, 'qty' => 3]]])->assertOk();
        $this->assertSame(25.0, $this->lastOrder($a)->goal_discount);

        // basic has no offers: no goal on the menu and none on the order
        $b = $this->restaurant('cafe-b', 'basic');
        $tea = $this->item($b, 'Tea', 100);
        $this->as($b, fn () => Settings::put(['cart_goal_enabled' => '1', 'cart_goal_amount' => '50', 'cart_goal_reward' => 'amount', 'cart_goal_value' => '10']));
        $this->get($this->subUrl('cafe-b'))->assertOk()->assertDontSee('data-goal-box', false);
        $bTable = $this->as($b, fn () => DiningTable::query()->first());
        $this->get($this->subUrl('cafe-b', "t/{$bTable->qr_token}"));
        $this->postJson($this->subUrl('cafe-b', 'order'), ['client_uuid' => (string) Str::uuid(), 'items' => [['item_id' => $tea->id, 'qty' => 1]]])->assertOk();
        $this->assertSame(0.0, $this->lastOrder($b)->goal_discount);
    }

    public function test_the_owner_saves_the_goal_from_the_offers_page(): void
    {
        $a = $this->restaurant('cafe-a', 'pro');
        $item = $this->item($a, 'Croissant', 35);
        $owner = $this->owner($a);
        $this->actingAs($owner)->get($this->dashUrl('/marketing'))->assertOk()->assertSee('data-goal-form', false);
        $this->actingAs($owner)->post($this->dashUrl('/marketing/cart-goal'), [
            'cart_goal_enabled' => '1', 'cart_goal_amount' => '200', 'cart_goal_reward' => 'item', 'cart_goal_item' => $item->id,
        ])->assertSessionHasNoErrors();
        $this->assertSame('1', $this->as($a, fn () => Settings::get('cart_goal_enabled')));
        $this->assertSame((string) $item->id, $this->as($a, fn () => Settings::get('cart_goal_item')));

        $this->actingAs($owner)->post($this->dashUrl('/marketing/cart-goal'), ['cart_goal_enabled' => '1', 'cart_goal_amount' => '200', 'cart_goal_reward' => 'percent', 'cart_goal_value' => '150'])
            ->assertSessionHasErrors('cart_goal_value');
    }
}
