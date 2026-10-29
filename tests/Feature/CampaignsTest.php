<?php

namespace Tests\Feature;

use App\Billing\Credits;
use App\Models\Branch;
use App\Models\Campaign;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\LoyaltyCard;
use App\Models\LoyaltyProgram;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\WhatsAppMessage;
use App\Services\Marketing\Campaigns;
use App\Services\Orders\OrderException;
use App\Services\Orders\OrderService;
use App\Services\Settings;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/** The campaigns page: who gets it, cost preview, EgHub WhatsApp sending with opt-outs, one list of every channel, and coupons on orders. */
class CampaignsTest extends TenantTestCase
{
    private function customer(Tenant $t, string $phone, array $attrs = []): Customer
    {
        return $this->as($t, fn () => Customer::query()->create(['phone' => $phone, 'name' => 'Guest '.substr($phone, -3)] + $attrs));
    }

    private function preview(Tenant $t, string $segment, ?float $value = null): array
    {
        return $this->as($t, fn () => app(Campaigns::class)->preview($segment, $value));
    }

    public function test_segments_count_the_right_guests(): void
    {
        $a = $this->restaurant('cafe-a', 'business');
        $this->customer($a, '01000000001', ['last_visit_at' => now()->subDays(40), 'visits_count' => 1, 'total_spent' => 100]);
        $this->customer($a, '01000000002', ['last_visit_at' => now()->subDays(5), 'visits_count' => 6, 'total_spent' => 900, 'birthday' => now()->addDays(3)->subYears(30)]);
        $this->customer($a, '01000000003', ['last_visit_at' => now()->subDays(90), 'visits_count' => 3, 'total_spent' => 500,
            'birthday' => now()->addDays(20)->subYears(25), 'whatsapp_opt_out' => true]);
        $loyal = $this->customer($a, '01000000004', ['last_visit_at' => now(), 'visits_count' => 10, 'total_spent' => 2000]);
        $this->as($a, function () use ($loyal) {
            $program = LoyaltyProgram::query()->create(['type' => 'visits', 'title' => 'Card', 'stamps_required' => 5, 'reward_label' => 'Coffee']);
            LoyaltyCard::query()->create(['loyalty_program_id' => $program->id, 'customer_id' => $loyal->id]);
        });
        // another restaurant's guests never count
        $b = $this->restaurant('cafe-b', 'business');
        $this->customer($b, '01000000001', ['last_visit_at' => now()->subDays(400)]);

        $this->assertSame(['audience' => 4, 'opted_out' => 1, 'recipients' => 3, 'cost' => 3, 'balance' => 0], $this->preview($a, 'all'));
        $this->assertSame(2, $this->preview($a, 'inactive', 30)['audience']);
        $this->assertSame(1, $this->preview($a, 'inactive', 30)['opted_out']);
        $this->assertSame(3, $this->preview($a, 'visits', 3)['audience']);
        $this->assertSame(1, $this->preview($a, 'birthday')['audience']);
        $this->assertSame(1, $this->preview($a, 'loyalty')['audience']);
        $this->assertSame(2, $this->preview($a, 'spent', 900)['audience']);
        $this->assertSame(1, $this->preview($b, 'all')['audience']);
    }

    public function test_the_page_shows_the_audience_and_cost_before_sending(): void
    {
        $a = $this->restaurant('cafe-a', 'business');
        $this->customer($a, '01000000001');
        $this->customer($a, '01000000002', ['whatsapp_opt_out' => true]);
        app(Credits::class)->add($a, 7, 'adjustment');
        $owner = $this->owner($a);

        $this->actingAs($owner)->get($this->dashUrl('/campaigns'))->assertOk()->assertSee(__('New campaign'));
        $this->actingAs($owner)->getJson($this->dashUrl('/campaigns/preview?segment=all'))
            ->assertOk()->assertExactJson(['audience' => 2, 'opted_out' => 1, 'recipients' => 1, 'cost' => 1, 'balance' => 7]);
    }

    public function test_sending_takes_credits_in_batches_and_skips_opted_out_guests(): void
    {
        $a = $this->restaurant('cafe-a', 'business');
        for ($i = 1; $i <= 120; $i++) {
            $this->customer($a, sprintf('0101%07d', $i), $i % 10 === 0 ? ['whatsapp_opt_out' => true] : []);
        }
        app(Credits::class)->add($a, 500, 'adjustment');

        $this->actingAs($this->owner($a))->post($this->dashUrl('/campaigns'), [
            'name' => 'We miss you', 'segment' => 'all', 'message' => 'Hi {name}! {restaurant} {link}', 'when' => 'now',
            'coupon_code' => 'back20', 'coupon_type' => 'percent', 'coupon_value' => 20,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $campaign = $this->as($a, fn () => Campaign::query()->with('coupon')->sole());
        $this->assertSame('sent', $campaign->status);
        $this->assertSame(108, $campaign->sent_count);
        $this->assertSame(12, $campaign->skipped_count);
        $this->assertSame(0, $campaign->failed_count);
        $this->assertSame('BACK20', $campaign->coupon->code);
        $this->assertSame(392, app(Credits::class)->balance($a));

        $messages = $this->as($a, fn () => WhatsAppMessage::query()->where('campaign_id', $campaign->id)->get());
        $this->assertCount(108, $messages);
        $optedOut = $this->as($a, fn () => Customer::query()->where('whatsapp_opt_out', true)->pluck('id'));
        $this->assertFalse($messages->pluck('customer_id')->intersect($optedOut)->isNotEmpty());
        $this->assertStringContainsString('/cafe-a?coupon=BACK20', $messages[0]->body);
        $this->assertStringStartsWith('Hi Guest 001!', $messages[0]->body);
    }

    public function test_a_campaign_stops_when_credits_run_out_and_resumes_after_a_top_up(): void
    {
        $a = $this->restaurant('cafe-a', 'business');
        for ($i = 1; $i <= 5; $i++) {
            $this->customer($a, sprintf('0101%07d', $i));
        }
        app(Credits::class)->add($a, 3, 'adjustment');
        $owner = $this->owner($a);
        $this->actingAs($owner)->post($this->dashUrl('/campaigns'), ['name' => 'Hi', 'segment' => 'all', 'message' => 'Hello {name}', 'when' => 'now'])
            ->assertSessionHasNoErrors();

        $campaign = $this->as($a, fn () => Campaign::query()->sole());
        $this->assertSame('stopped', $campaign->status);
        $this->assertSame(3, $campaign->sent_count);
        $this->actingAs($owner)->get($this->dashUrl('/'))->assertSee('رصيد الواتساب خلص');

        app(Credits::class)->add($a, 10, 'adjustment');
        $this->actingAs($owner)->post($this->dashUrl("/campaigns/{$campaign->id}/resume"))->assertSessionHasNoErrors();
        $campaign->refresh();
        $this->assertSame('sent', $campaign->status);
        $this->assertSame(5, $campaign->sent_count);
        $this->assertSame(8, app(Credits::class)->balance($a));
    }

    public function test_scheduled_campaigns_start_from_the_scheduler(): void
    {
        $a = $this->restaurant('cafe-a', 'business');
        $this->customer($a, '01000000001');
        app(Credits::class)->add($a, 5, 'adjustment');
        $this->actingAs($this->owner($a))->post($this->dashUrl('/campaigns'), [
            'name' => 'Friday', 'segment' => 'all', 'message' => 'Hi', 'when' => 'later', 'scheduled_at' => now()->addHour()->format('Y-m-d H:i'),
        ])->assertSessionHasNoErrors();
        $this->assertSame('scheduled', $this->as($a, fn () => Campaign::query()->sole()->status));

        Artisan::call('egmenu:campaigns');
        $this->assertSame('scheduled', $this->as($a, fn () => Campaign::query()->sole()->status));
        $this->travel(2)->hours();
        Artisan::call('egmenu:campaigns');
        $this->assertSame('sent', $this->as($a, fn () => Campaign::query()->sole()->status));
        $this->assertSame(4, app(Credits::class)->balance($a));
    }

    public function test_without_the_campaigns_feature_the_page_sends_from_the_own_phone_only(): void
    {
        $pro = $this->restaurant('cafe-a', 'pro');
        $this->customer($pro, '01000000001');
        $owner = $this->owner($pro);
        $page = $this->actingAs($owner)->get($this->dashUrl('/campaigns'))->assertOk()->assertSee('data-via-locked', false)->getContent();
        $this->assertMatchesRegularExpression('/value="whatsapp"[^>]*disabled/', $page);
        $this->assertMatchesRegularExpression('/value="manual"[^>]*checked/', $page);

        // EgHub WhatsApp (also the old form, which sent no channel) and its automations stay locked
        $send = ['name' => 'Hi', 'segment' => 'all', 'message' => 'Hi {name}', 'when' => 'now'];
        $this->actingAs($owner)->post($this->dashUrl('/campaigns'), $send + ['channel' => 'whatsapp'])->assertForbidden();
        $this->actingAs($owner)->post($this->dashUrl('/campaigns'), $send)->assertForbidden();
        $this->actingAs($owner)->get($this->dashUrl('/campaigns?tab=automations'))->assertForbidden();
        // from the own phone is free
        $this->actingAs($owner)->post($this->dashUrl('/campaigns'), $send + ['channel' => 'manual'])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(['manual'], $this->as($pro, fn () => Campaign::query()->pluck('channel')->all()));

        $biz = $this->restaurant('cafe-b', 'business');
        $cashier = $this->staff($biz, 'cashier', 'cashier@cafe-b.test');
        $this->actingAs($cashier)->get($this->dashUrl('/campaigns'))->assertForbidden();
        // neither the customer list nor campaigns in the plan
        $this->actingAs($this->owner($this->restaurant('cafe-c', 'starter')))->get($this->dashUrl('/campaigns'))->assertForbidden();
    }

    public function test_one_list_shows_every_campaign_with_how_it_went_out(): void
    {
        $a = $this->restaurant('cafe-a', 'business');
        $this->customer($a, '01000000001', ['source' => 'import']);
        app(Credits::class)->add($a, 5, 'adjustment');
        $owner = $this->owner($a);

        $this->actingAs($owner)->post($this->dashUrl('/campaigns'), ['name' => 'By phone', 'channel' => 'manual', 'segment' => 'imported', 'message' => 'Hi {name}'])
            ->assertSessionHasNoErrors();
        $this->actingAs($owner)->post($this->dashUrl('/campaigns'), ['name' => 'By EgHub', 'channel' => 'whatsapp', 'segment' => 'imported', 'message' => 'Hi {name} {code}',
            'when' => 'now', 'coupon_code' => 'HI10', 'coupon_type' => 'percent', 'coupon_value' => 10])->assertSessionHasNoErrors();

        $manual = $this->as($a, fn () => Campaign::query()->where('channel', 'manual')->sole());
        $this->actingAs($owner)->get($this->dashUrl('/campaigns'))->assertOk()
            ->assertSeeInOrder(['data-campaign-via="whatsapp"', 'By EgHub', 'واتساب EgHub', 'data-campaign-via="manual"', 'By phone', 'من موبايلي'], false)
            ->assertSee($this->dashUrl('/customers/outreach/'.$manual->id), false);
        // the suggested messages write {code}: EgHub WhatsApp fills it in too
        $this->assertStringStartsWith('Hi Guest 001 HI10', $this->as($a, fn () => WhatsAppMessage::query()->sole()->body));
        $this->assertSame(['audience' => 1, 'opted_out' => 0, 'recipients' => 1, 'cost' => 0, 'balance' => 4],
            $this->actingAs($owner)->getJson($this->dashUrl('/campaigns/preview?segment=imported&channel=manual'))->assertOk()->json());
    }

    public function test_a_coupon_applies_once_per_guest_and_respects_its_limits(): void
    {
        $a = $this->restaurant('cafe-a', 'business');
        $item = $this->item($a, 'Latte', 100);
        $this->as($a, fn () => Coupon::query()->create(['code' => 'BACK20', 'type' => 'percent', 'value' => 20, 'max_discount' => 30,
            'min_order' => 150, 'max_uses' => 2, 'expires_at' => now()->addDays(3)]));
        $place = fn (int $qty, string $phone, string $code = 'back20') => $this->as($a, fn () => app(OrderService::class)->place([
            'items' => [['item_id' => $item->id, 'qty' => $qty]], 'customer_phone' => $phone, 'coupon' => $code, 'source' => 'online',
        ], Branch::query()->first()));
        $error = function (\Closure $fn) {
            try {
                $fn();
            } catch (OrderException $e) {
                return $e->getMessage();
            }

            return null;
        };

        $this->assertNotNull($error(fn () => $place(1, '01000000001')));                      // under the minimum order
        $order = $place(2, '01000000001');
        $this->assertSame(30.0, $order->discount);                                           // 20% of 200 = 40, capped at 30
        $this->assertSame(170.0, $order->total);
        $this->assertSame('BACK20', $order->coupon_code);
        $this->assertNotNull($error(fn () => $place(2, '01000000001')));                      // once per guest
        $this->assertNotNull($error(fn () => $place(2, '')));                                 // needs a phone to check that
        $place(3, '01000000002');
        $this->assertNotNull($error(fn () => $place(2, '01000000003')));                      // max_uses reached
        $this->assertSame(2, $this->as($a, fn () => Coupon::query()->sole()->uses));

        // a cancelled order gives its use back
        $this->as($a, fn () => app(OrderService::class)->transition(Order::query()->find($order->id), 'cancelled'));
        $this->assertSame(1, $this->as($a, fn () => Coupon::query()->sole()->uses));
        $this->assertSame(170.0, $place(2, '01000000003')->total);                            // its use is free again

        // expired, unknown, or another restaurant's code
        $this->travel(5)->days();
        $this->assertNotNull($error(fn () => $place(2, '01000000004')));
        $b = $this->restaurant('cafe-b', 'business');
        $itemB = $this->item($b, 'Tea', 100);
        $this->assertNotNull($error(fn () => $this->as($b, fn () => app(OrderService::class)->place([
            'items' => [['item_id' => $itemB->id, 'qty' => 2]], 'customer_phone' => '01000000009', 'coupon' => 'BACK20', 'source' => 'online',
        ], Branch::query()->first()))));
    }

    public function test_an_amount_coupon_never_makes_the_order_negative_and_works_from_the_menu(): void
    {
        $a = $this->restaurant('cafe-a', 'business');
        $item = $this->item($a, 'Water', 15);
        $this->as($a, fn () => Coupon::query()->create(['code' => 'FREE50', 'type' => 'amount', 'value' => 50, 'once_per_customer' => false]));
        $order = $this->as($a, fn () => app(OrderService::class)->place([
            'items' => [['item_id' => $item->id, 'qty' => 1]], 'coupon' => 'free50', 'source' => 'pos',
        ], Branch::query()->first()));
        $this->assertSame(15.0, $order->discount);
        $this->assertSame(0.0, $order->total);

        // the guest's cart sends the code as "coupon"
        $this->get($this->pathUrl('cafe-a'))->assertOk()->assertSee('data-coupon', false);
        $this->as($a, fn () => Settings::put(['online_takeaway' => '1']));
        $this->postJson($this->pathUrl('cafe-a', 'order'), [
            'client_uuid' => (string) Str::uuid(), 'items' => [['item_id' => $item->id, 'qty' => 2]],
            'customer_phone' => '01012345678', 'type' => 'takeaway', 'coupon' => 'FREE50',
        ])->assertOk();
        $this->assertSame('FREE50', $this->as($a, fn () => Order::query()->latest('id')->first()->coupon_code));
    }

    public function test_the_cart_previews_the_coupon_discount_before_sending_without_using_it(): void
    {
        $a = $this->restaurant('cafe-a', 'business');
        $item = $this->item($a, 'Latte', 100);
        $this->as($a, fn () => Coupon::query()->create(['code' => 'BACK20', 'type' => 'percent', 'value' => 20, 'max_discount' => 30, 'min_order' => 150]));
        $this->get($this->pathUrl('cafe-a'))->assertOk()->assertSee('data-coupon-apply', false)->assertSee('data-coupon-status', false);

        $quote = fn (int $qty, string $code = 'back20') => $this->postJson($this->pathUrl('cafe-a', 'coupon'), [
            'coupon' => $code, 'items' => [['item_id' => $item->id, 'qty' => $qty]], 'customer_phone' => '01000000001',
        ]);
        // priced like the order: 20% of 200 = 40, capped at 30
        $quote(2)->assertOk()->assertJson(['code' => 'BACK20', 'discount' => 30]);
        // under the minimum, or an unknown code: the reason, nothing taken off
        $quote(1)->assertStatus(422)->assertJsonStructure(['message']);
        $quote(2, 'NOPE')->assertStatus(422);
        // a preview never counts as a use
        $this->assertSame(0, $this->as($a, fn () => Coupon::query()->sole()->uses));
    }

    public function test_the_customer_page_sets_the_whatsapp_opt_out(): void
    {
        $a = $this->restaurant('cafe-a', 'business');
        $c = $this->customer($a, '01000000001');
        $this->actingAs($this->owner($a))->post($this->dashUrl("/customers/{$c->id}"), ['name' => 'Mona', 'whatsapp_opt_out' => '1'])->assertSessionHasNoErrors();
        $this->assertTrue($this->as($a, fn () => Customer::query()->find($c->id)->whatsapp_opt_out));
        $this->assertSame(0, $this->preview($a, 'all')['recipients']);
    }

    public function test_cancelling_the_same_order_twice_gives_the_coupon_use_back_once(): void
    {
        $a = $this->restaurant('cafe-a', 'business');
        $item = $this->item($a, 'Water', 15);
        $this->as($a, fn () => Coupon::query()->create(['code' => 'TWICE', 'type' => 'amount', 'value' => 5, 'once_per_customer' => false, 'max_uses' => 10]));
        $place = fn () => $this->as($a, fn () => app(OrderService::class)->place([
            'items' => [['item_id' => $item->id, 'qty' => 1]], 'coupon' => 'twice', 'source' => 'pos',
        ], Branch::query()->first()));
        $place();
        $order = $place();
        $this->assertSame(2, $this->as($a, fn () => Coupon::query()->first()->uses));

        // two screens hold the same order; both press "cancel"
        $this->as($a, function () use ($order) {
            $stale = Order::query()->find($order->id);
            app(OrderService::class)->transition($order, 'cancelled');
            try {
                app(OrderService::class)->transition($stale, 'cancelled');
                $this->fail('A cancelled order was cancelled again.');
            } catch (OrderException) {
            }
        });
        $this->assertSame(1, $this->as($a, fn () => Coupon::query()->first()->uses));
    }

    public function test_coupons_and_campaigns_never_cross_restaurants(): void
    {
        $a = $this->restaurant('cafe-a', 'business');
        $b = $this->restaurant('cafe-b', 'business');
        $item = $this->item($a, 'Water', 15);
        $this->as($b, fn () => Coupon::query()->create(['code' => 'ONLYB', 'type' => 'amount', 'value' => 5, 'once_per_customer' => false]));
        $campaign = $this->as($b, fn () => Campaign::query()->create(['name' => 'B', 'segment' => 'all', 'message' => 'Hi', 'status' => 'stopped']));

        $this->expectExceptionObject(new OrderException(__('This coupon code is not valid.')));
        try {
            $owner = $this->owner($a);
            $this->actingAs($owner)->post($this->dashUrl("/campaigns/{$campaign->id}/cancel"))->assertNotFound();
            $this->actingAs($owner)->post($this->dashUrl("/campaigns/{$campaign->id}/resume"))->assertNotFound();
            $this->assertSame('stopped', $this->as($b, fn () => $campaign->fresh()->status));
            // B's code typed on A's menu
            $this->as($a, fn () => app(OrderService::class)->place([
                'items' => [['item_id' => $item->id, 'qty' => 1]], 'coupon' => 'onlyb', 'source' => 'pos',
            ], Branch::query()->first()));
        } finally {
            $this->assertSame(0, $this->as($b, fn () => Coupon::query()->first()->uses));
        }
    }
}
