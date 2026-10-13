<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\DiningTable;
use App\Models\Feedback;
use App\Models\LoyaltyCard;
use App\Models\LoyaltyProgram;
use App\Models\LoyaltyReward;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Tenant;
use App\Services\Loyalty\LoyaltyService;
use App\Services\Orders\OrderService;
use App\Services\Settings;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/** Customers, the digital stamp card and guest ratings. */
class LoyaltyTest extends TenantTestCase
{
    private function program(Tenant $t, array $attrs = []): LoyaltyProgram
    {
        return $this->as($t, fn () => LoyaltyProgram::query()->create($attrs + [
            'type' => 'visits', 'title' => 'Coffee card', 'stamps_required' => 5, 'reward_label' => 'Free coffee',
            'card_design' => ['bg' => '#B5451B', 'stamp_bg' => '#7A1F2B', 'icon' => 'cup'],
        ]));
    }

    /** A served QR order for $phone with $qty × $item. */
    private function servedOrder(Tenant $t, MenuItem $item, string $phone, int $qty = 1): Order
    {
        $table = $this->as($t, fn () => DiningTable::query()->first());
        $this->get($this->menuUrl("{$t->id}/t/{$table->qr_token}"));
        $this->postJson($this->menuUrl("{$t->id}/order"), [
            'client_uuid' => (string) Str::uuid(), 'items' => [['item_id' => $item->id, 'qty' => $qty]], 'customer_phone' => $phone,
        ])->assertOk();
        return $this->as($t, function () {
            $order = Order::query()->latest('id')->first();
            $svc = app(OrderService::class);
            foreach (['preparing', 'ready', 'served'] as $s) {
                $svc->transition($order->fresh(), $s);
            }
            return $order->fresh();
        });
    }

    private function card(Tenant $t, string $phone): ?LoyaltyCard
    {
        return $this->as($t, fn () => LoyaltyCard::query()->whereHas('customer', fn ($q) => $q->where('phone', $phone))->with('customer')->first());
    }

    public function test_a_served_order_counts_a_visit_and_one_stamp_per_day(): void
    {
        $a = $this->restaurant('cafe-a');
        $this->program($a);
        $latte = $this->item($a, 'Latte', 60);

        $this->servedOrder($a, $latte, '01011111111', 2);
        $card = $this->card($a, '01011111111');
        $this->assertSame(1, $card->stamps);
        $this->assertSame(1, $card->customer->visits_count);
        $this->assertSame(120.0, $card->customer->total_spent);

        $this->servedOrder($a, $latte, '01011111111');            // same day: no second stamp
        $this->assertSame(1, $this->card($a, '01011111111')->stamps);
        $this->assertSame(2, $this->card($a, '01011111111')->customer->visits_count);

        $this->travel(1)->days();
        $this->servedOrder($a, $latte, '01011111111');
        $this->assertSame(2, $this->card($a, '01011111111')->stamps);
    }

    public function test_item_stamps_count_only_qualifying_items(): void
    {
        $a = $this->restaurant('cafe-a');
        [$latte, $cake] = $this->as($a, function () {
            $coffee = Category::query()->create(['name' => 'Coffee']);
            $sweets = Category::query()->create(['name' => 'Sweets']);
            return [MenuItem::query()->create(['category_id' => $coffee->id, 'name' => 'Latte', 'price' => 60]),
                MenuItem::query()->create(['category_id' => $sweets->id, 'name' => 'Cake', 'price' => 80])];
        });
        $this->program($a, ['type' => 'items', 'qualifying_items' => ['categories' => [$latte->category_id], 'items' => []]]);

        $this->servedOrder($a, $latte, '01022222222', 3);
        $this->servedOrder($a, $cake, '01022222222', 2);
        $this->assertSame(3, $this->card($a, '01022222222')->stamps);
    }

    public function test_milestones_rewards_overflow_and_redeem_once(): void
    {
        $a = $this->restaurant('cafe-a');
        $this->program($a, ['milestones' => [['at' => 3, 'type' => 'discount_percent', 'label' => '30% off']]]);
        $cashier = $this->staff($a, 'cashier', 'c@a.test');
        $this->actingAs($cashier)->post($this->dashUrl('/loyalty/enroll'), ['phone' => '01033333333', 'name' => 'Mona'])->assertRedirect();
        $card = $this->card($a, '01033333333');

        $this->actingAs($cashier)->post($this->dashUrl("/loyalty/cards/{$card->id}/stamp"), ['count' => 6])->assertSessionHasNoErrors();
        $card = $this->card($a, '01033333333');
        $this->assertSame(1, $card->stamps);                                // 6 on a 5-stamp card: full card + 1
        $labels = $this->as($a, fn () => LoyaltyReward::query()->orderBy('id')->pluck('label')->all());
        $this->assertSame(['30% off', 'Free coffee'], $labels);

        $reward = $this->as($a, fn () => LoyaltyReward::query()->first());
        $this->actingAs($cashier)->post($this->dashUrl("/loyalty/rewards/{$reward->id}/redeem"))->assertSessionHasNoErrors();
        $this->actingAs($cashier)->post($this->dashUrl("/loyalty/rewards/{$reward->id}/redeem"))->assertSessionHasErrors('reward');
        $this->assertSame('redeemed', $reward->fresh()->status);
    }

    public function test_cancelling_a_counted_order_takes_the_stamp_back(): void
    {
        $a = $this->restaurant('cafe-a');
        $this->program($a);
        $item = $this->item($a, 'Latte', 60);
        $this->servedOrder($a, $item, '01044444444');
        $this->assertSame(1, $this->card($a, '01044444444')->stamps);

        // a served order can't be cancelled through the board, so reverse it directly as a refund would
        $this->as($a, fn () => app(LoyaltyService::class)->orderReversed(Order::query()->first()));
        $card = $this->card($a, '01044444444');
        $this->assertSame(0, $card->stamps);
        $this->assertSame(0, $card->customer->visits_count);
    }

    public function test_paid_then_served_counts_once(): void
    {
        $a = $this->restaurant('cafe-a');
        app(\App\Billing\Billing::class)->setAddon($a, 'pos_pack', 1);
        $this->program($a);
        $item = $this->item($a, 'Latte', 60);
        $owner = $this->owner($a);
        $this->actingAs($owner)->post($this->dashUrl('/shifts/open'), ['opening_cash' => 0]);
        $id = $this->actingAs($owner)->postJson($this->dashUrl('/pos/orders'), [
            'client_uuid' => (string) Str::uuid(), 'type' => 'takeaway', 'items' => [['item_id' => $item->id, 'qty' => 1]],
            'customer_phone' => '01055555555', 'pay' => 'cash',
        ])->assertOk()->json('id');
        $this->as($a, function () use ($id) {
            foreach (['preparing', 'ready', 'served'] as $s) {
                app(OrderService::class)->transition(Order::query()->find($id), $s);
            }
        });
        $card = $this->card($a, '01055555555');
        $this->assertSame(1, $card->stamps);
        $this->assertSame(1, $card->customer->visits_count);
    }

    public function test_guest_joins_and_sees_the_card_but_cannot_stamp_it(): void
    {
        $a = $this->restaurant('cafe-a');
        $this->program($a);
        $this->get($this->menuUrl('cafe-a/card'))->assertOk();
        $this->post($this->menuUrl('cafe-a/card'), ['phone' => '123'])->assertSessionHasErrors('phone');

        $res = $this->post($this->menuUrl('cafe-a/card'), ['phone' => '01066666666', 'name' => 'Sara', 'consent' => '1']);
        $customer = $this->as($a, fn () => Customer::query()->where('phone', '01066666666')->first());
        $res->assertRedirect($this->menuUrl("cafe-a/card/{$customer->card_token}"));
        $this->assertTrue($customer->marketing_opt_in);
        $this->get($this->menuUrl("cafe-a/card/{$customer->card_token}"))->assertOk()->assertSee('Coffee card')->assertSee('<svg', false);

        // the guest's own browser has no way to add stamps
        $card = $this->card($a, '01066666666');
        $this->post($this->dashUrl("/loyalty/cards/{$card->id}/stamp"), ['count' => 5])->assertRedirect($this->dashUrl('/login'));
        $this->assertSame(0, $card->fresh()->stamps);
    }

    public function test_cards_and_customers_stay_inside_their_restaurant(): void
    {
        $a = $this->restaurant('cafe-a');
        $b = $this->restaurant('cafe-b');
        $this->program($a);
        $this->program($b);
        $this->post($this->menuUrl('cafe-b/card'), ['phone' => '01077777777']);
        $customerOfB = $this->as($b, fn () => Customer::query()->first());
        $cardOfB = $this->card($b, '01077777777');

        $this->get($this->menuUrl("cafe-a/card/{$customerOfB->card_token}"))->assertNotFound();
        $owner = $this->owner($a);
        $this->actingAs($owner)->get($this->dashUrl("/customers/{$customerOfB->id}"))->assertNotFound();
        $this->actingAs($owner)->post($this->dashUrl("/loyalty/cards/{$cardOfB->id}/stamp"), ['count' => 3])->assertNotFound();
        $this->actingAs($owner)->get($this->dashUrl('/loyalty?q=01077777777'))->assertOk()->assertSee(__('No card for this number. Create one?'));
        $this->assertStringNotContainsString('01077777777', $this->actingAs($owner)->get($this->dashUrl('/customers/export'))->streamedContent());
    }

    public function test_basic_plan_has_no_loyalty_or_customer_list(): void
    {
        $a = $this->restaurant('cafe-a', 'basic');
        $this->program($a);
        $this->get($this->menuUrl('cafe-a/card'))->assertNotFound();
        $this->actingAs($this->owner($a))->get($this->dashUrl('/loyalty'))->assertForbidden();
        $this->actingAs($this->owner($a))->get($this->dashUrl('/customers'))->assertForbidden();
    }

    public function test_card_rules_lock_once_guests_hold_cards(): void
    {
        $a = $this->restaurant('cafe-a');
        $this->program($a);
        $this->post($this->menuUrl('cafe-a/card'), ['phone' => '01088888888']);
        $form = ['type' => 'visits', 'title' => 'Coffee card', 'stamps_required' => 8, 'reward_label' => 'Free coffee', 'is_active' => '1',
            'design' => ['bg' => '#B5451B', 'stamp_bg' => '#7A1F2B', 'icon' => 'cup']];

        $this->actingAs($this->owner($a))->post($this->dashUrl('/loyalty/setup'), $form)->assertSessionHasErrors('locked');
        $this->actingAs($this->owner($a))->post($this->dashUrl('/loyalty/setup'), ['stamps_required' => 5, 'title' => 'New name'] + $form)->assertSessionHasNoErrors();
        $this->assertSame('New name', $this->as($a, fn () => LoyaltyProgram::query()->value('title')));
    }

    public function test_stamps_expire(): void
    {
        $a = $this->restaurant('cafe-a');
        $this->program($a, ['stamps_expire_days' => 30]);
        $this->post($this->menuUrl('cafe-a/card'), ['phone' => '01099999999']);
        $card = $this->card($a, '01099999999');
        $this->as($a, fn () => app(LoyaltyService::class)->stamp($card, 2, $this->owner($a)));

        $this->travel(31)->days();
        $this->artisan('egmenu:loyalty-expire')->assertSuccessful();
        $this->assertSame(0, $card->fresh()->stamps);
    }

    public function test_guest_rating_with_google_link_and_rate_limit(): void
    {
        $a = $this->restaurant('cafe-a');
        $this->as($a, fn () => Settings::put(['google_review_url' => 'https://g.page/r/x/review']));

        $this->postJson($this->menuUrl('cafe-a/feedback'), ['rating' => 5, 'comment' => 'Great latte'])
            ->assertOk()->assertJsonPath('google', 'https://g.page/r/x/review');
        $this->postJson($this->menuUrl('cafe-a/feedback'), ['rating' => 2])->assertOk()->assertJsonPath('google', null);
        $this->postJson($this->menuUrl('cafe-a/feedback'), ['rating' => 3])->assertOk();
        $this->postJson($this->menuUrl('cafe-a/feedback'), ['rating' => 4])->assertStatus(429);

        $this->assertSame(3, $this->as($a, fn () => Feedback::query()->count()));
        $this->actingAs($this->owner($a))->get($this->dashUrl('/feedback'))->assertOk()->assertSee('Great latte')->assertSee('3.3');
    }
}
