<?php

namespace Tests\Feature;

use App\Billing\Credits;
use App\Models\Branch;
use App\Models\CampaignAutomation;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\WhatsAppMessage;
use App\Services\Loyalty\LoyaltyService;
use App\Services\Marketing\Automations;
use App\Services\Orders\OrderService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Tests\TenantTestCase;

/** Marketing automations: welcome, winback and birthday WhatsApp messages sent by the scheduler, once per guest. */
class AutomationsTest extends TenantTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // noon, inside every default send window
        $this->travelTo(now()->setTime(12, 0));
    }

    private function customer(Tenant $t, string $phone, array $attrs = []): Customer
    {
        return $this->as($t, fn () => Customer::query()->create(['phone' => $phone, 'name' => 'Guest '.substr($phone, -3)] + $attrs));
    }

    private function enable(Tenant $t, string $kind, array $attrs = []): CampaignAutomation
    {
        return $this->as($t, function () use ($kind, $attrs) {
            $a = app(Automations::class)->all()->get($kind);
            $a->update(['is_enabled' => true] + $attrs);

            return $a->fresh();
        });
    }

    /** @return Collection<int, WhatsAppMessage> */
    private function messages(Tenant $t, ?string $kind = null)
    {
        return $this->as($t, function () use ($kind) {
            $q = WhatsAppMessage::query()->orderBy('id');
            if ($kind) {
                $q->where('automation_id', CampaignAutomation::query()->where('kind', $kind)->value('id'));
            }

            return $q->get();
        });
    }

    public function test_welcome_goes_once_to_guests_after_their_first_order_and_takes_one_credit(): void
    {
        $a = $this->restaurant('cafe-a', 'business');
        $first = $this->customer($a, '01000000001', ['visits_count' => 1, 'last_visit_at' => now()->subHour()]);
        $this->customer($a, '01000000002', ['visits_count' => 1, 'last_visit_at' => now()->subDays(10)]); // too old to welcome
        $this->customer($a, '01000000003', ['visits_count' => 3, 'last_visit_at' => now()->subHour()]);   // not a first order
        $this->customer($a, '01000000004', ['visits_count' => 1, 'last_visit_at' => now()->subHour(), 'whatsapp_opt_out' => true]);
        $this->customer($a, '01000000005', ['visits_count' => 0, 'last_visit_at' => null]);
        app(Credits::class)->add($a, 10, 'adjustment');
        $coupon = $this->as($a, fn () => Coupon::query()->create(['code' => 'HELLO10', 'type' => 'percent', 'value' => 10]));
        $this->enable($a, 'welcome', ['coupon_id' => $coupon->id, 'message' => 'Thanks {name} from {restaurant}! Coupon {coupon}: {link}']);
        // the same guest at another restaurant, whose welcome is off: never messaged
        $b = $this->restaurant('cafe-b', 'business');
        $this->customer($b, '01000000001', ['visits_count' => 1, 'last_visit_at' => now()->subHour()]);
        app(Credits::class)->add($b, 10, 'adjustment');

        Artisan::call('egmenu:automations');
        Artisan::call('egmenu:automations');

        $messages = $this->messages($a);
        $this->assertCount(1, $messages);
        $this->assertSame($first->id, $messages[0]->customer_id);
        $this->assertSame('sent', $messages[0]->status);
        $this->assertSame('automation', $messages[0]->template);
        $this->assertStringContainsString('Thanks Guest 001 from Restaurant cafe-a! Coupon HELLO10: http://egmenu.test/cafe-a?coupon=HELLO10', $messages[0]->body);
        $this->assertSame(9, app(Credits::class)->balance($a));
        $this->assertCount(0, $this->messages($b));
        $this->assertSame(10, app(Credits::class)->balance($b));
    }

    public function test_welcome_follows_a_real_first_counted_order(): void
    {
        $a = $this->restaurant('cafe-a', 'business');
        app(Credits::class)->add($a, 5, 'adjustment');
        $this->enable($a, 'welcome');
        $item = $this->item($a, 'Latte', 50);

        Artisan::call('egmenu:automations');
        $this->assertCount(0, $this->messages($a));

        $this->as($a, function () use ($item) {
            $order = app(OrderService::class)->place([
                'items' => [['item_id' => $item->id, 'qty' => 1]], 'customer_phone' => '01099999999', 'customer_name' => 'Mona', 'source' => 'pos', 'type' => 'takeaway',
            ], Branch::query()->first());
            app(LoyaltyService::class)->orderCompleted($order);
        });
        Artisan::call('egmenu:automations');
        $messages = $this->messages($a, 'welcome');
        $this->assertCount(1, $messages);
        $this->assertStringStartsWith('أهلاً Mona', $messages[0]->body);
    }

    public function test_winback_reaches_guests_away_for_n_days_and_waits_90_days_before_repeating(): void
    {
        $a = $this->restaurant('cafe-a', 'business');
        $away = $this->customer($a, '01000000001', ['visits_count' => 2, 'last_visit_at' => now()->subDays(40)]);
        $this->customer($a, '01000000002', ['visits_count' => 2, 'last_visit_at' => now()->subDays(20)]);
        $this->customer($a, '01000000003', ['visits_count' => 0, 'last_visit_at' => null]);
        app(Credits::class)->add($a, 10, 'adjustment');
        $this->enable($a, 'winback', ['days' => 30]);

        Artisan::call('egmenu:automations');
        Artisan::call('egmenu:automations');
        $this->assertSame([$away->id], $this->messages($a)->pluck('customer_id')->all());

        // 60 days later guest 2 is 80 days away and gets their message; guest 1 was messaged 60 days ago and waits
        // for the 90-day cooldown
        $this->travel(60)->days();
        Artisan::call('egmenu:automations');
        $this->assertSame([$away->id, $away->id + 1], $this->messages($a)->pluck('customer_id')->sort()->values()->all());
    }

    public function test_winback_cooldown_and_eligibility_timeline(): void
    {
        $a = $this->restaurant('cafe-a', 'business');
        $away = $this->customer($a, '01000000001', ['visits_count' => 2, 'last_visit_at' => now()->subDays(40)]);
        app(Credits::class)->add($a, 10, 'adjustment');
        $this->enable($a, 'winback', ['days' => 30]);

        Artisan::call('egmenu:automations');
        $this->assertCount(1, $this->messages($a));
        $this->travel(89)->days();
        Artisan::call('egmenu:automations');
        $this->assertCount(1, $this->messages($a));
        $this->travel(2)->days();
        Artisan::call('egmenu:automations');
        $this->assertCount(2, $this->messages($a));
        $this->assertSame([$away->id, $away->id], $this->messages($a)->pluck('customer_id')->all());
        $this->assertSame(8, app(Credits::class)->balance($a));
    }

    public function test_birthday_greetings_go_out_on_the_day_inside_the_morning_window_once_a_year(): void
    {
        $this->travelTo(now()->setDate(2026, 6, 15)->setTime(10, 0));
        $a = $this->restaurant('cafe-a', 'business');
        $today = $this->customer($a, '01000000001', ['birthday' => '1990-06-15']);
        $this->customer($a, '01000000002', ['birthday' => '1992-06-16']);
        $this->customer($a, '01000000003', ['birthday' => null]);
        app(Credits::class)->add($a, 10, 'adjustment');
        $this->enable($a, 'birthday'); // 9:00 – 13:00 by default

        $this->travelTo(now()->setTime(15, 0));
        Artisan::call('egmenu:automations');
        $this->assertCount(0, $this->messages($a));

        $this->travelTo(now()->setTime(10, 30));
        Artisan::call('egmenu:automations');
        Artisan::call('egmenu:automations');
        $this->assertSame([$today->id], $this->messages($a)->pluck('customer_id')->all());
        $this->assertStringContainsString('كل سنة وإنت طيب يا Guest 001', $this->messages($a)[0]->body);

        $this->travelTo(now()->addYear());
        Artisan::call('egmenu:automations');
        $this->assertCount(2, $this->messages($a));
    }

    public function test_nothing_is_sent_without_the_feature_or_without_credits(): void
    {
        $pro = $this->restaurant('cafe-pro', 'pro'); // no campaigns feature
        $this->customer($pro, '01000000001', ['visits_count' => 1, 'last_visit_at' => now()->subHour()]);
        app(Credits::class)->add($pro, 10, 'adjustment');
        $this->as($pro, fn () => CampaignAutomation::query()->create(['kind' => 'welcome', 'is_enabled' => true, 'message' => 'Hi {name}', 'send_from' => 0, 'send_to' => 24]));

        $broke = $this->restaurant('cafe-broke', 'business');
        $g1 = $this->customer($broke, '01000000001', ['visits_count' => 1, 'last_visit_at' => now()->subHour()]);
        $g2 = $this->customer($broke, '01000000002', ['visits_count' => 1, 'last_visit_at' => now()->subHour()]);
        $this->enable($broke, 'welcome');

        Artisan::call('egmenu:automations');
        $this->assertCount(0, $this->messages($pro));
        $this->assertSame(10, app(Credits::class)->balance($pro));
        $this->assertCount(0, $this->messages($broke));

        // one credit for two due guests: one goes out, the other waits for the next run after a top-up
        app(Credits::class)->add($broke, 1, 'adjustment');
        Artisan::call('egmenu:automations');
        $this->assertSame(['sent'], $this->messages($broke)->pluck('status')->all());
        app(Credits::class)->add($broke, 5, 'adjustment');
        Artisan::call('egmenu:automations');
        $this->assertSame([$g1->id, $g2->id], $this->messages($broke)->where('status', 'sent')->pluck('customer_id')->sort()->values()->all());
        $this->assertSame(4, app(Credits::class)->balance($broke));
    }

    public function test_the_automations_tab_saves_each_automation_and_shows_its_results(): void
    {
        $a = $this->restaurant('cafe-a', 'business');
        $owner = $this->owner($a);
        app(Credits::class)->add($a, 10, 'adjustment');

        $this->actingAs($owner)->get($this->dashUrl('/campaigns?tab=automations'))->assertOk()
            ->assertSee('الأتمتة')->assertSee('ترحيب بعد أول طلب')->assertSee('وحشتنا')->assertSee('عيد ميلاد');
        $this->assertCount(3, $this->as($a, fn () => CampaignAutomation::query()->get()));

        $this->actingAs($owner)->post($this->dashUrl('/campaigns/automations/winback'), [
            'message' => 'We miss you {name}! {coupon} {link}', 'days' => 45, 'send_from' => 12, 'send_to' => 20, 'is_enabled' => 1,
            'coupon_code' => 'back10', 'coupon_type' => 'percent', 'coupon_value' => 10,
        ])->assertSessionHasNoErrors()->assertRedirect($this->dashUrl('/campaigns?tab=automations'));
        $winback = $this->as($a, fn () => CampaignAutomation::query()->with('coupon')->where('kind', 'winback')->sole());
        $this->assertTrue($winback->is_enabled);
        $this->assertSame([45, 12, 20, 'BACK10'], [$winback->days, $winback->send_from, $winback->send_to, $winback->coupon->code]);

        // the window must make sense, and winback needs its days
        $this->actingAs($owner)->post($this->dashUrl('/campaigns/automations/winback'), ['message' => 'x', 'days' => 45, 'send_from' => 12, 'send_to' => 12])->assertSessionHasErrors('send_to');
        $this->actingAs($owner)->post($this->dashUrl('/campaigns/automations/winback'), ['message' => 'x', 'send_from' => 12, 'send_to' => 13])->assertSessionHasErrors('days');
        $this->actingAs($owner)->post($this->dashUrl('/campaigns/automations/party'), ['message' => 'x', 'send_from' => 12, 'send_to' => 13])->assertNotFound();

        // a guest gets the message and comes back with the coupon: the box counts the send, the order and its revenue
        $this->customer($a, '01000000001', ['visits_count' => 2, 'last_visit_at' => now()->subDays(50)]);
        Artisan::call('egmenu:automations');
        $this->assertCount(1, $this->messages($a));
        $item = $this->item($a, 'Latte', 100);
        $this->as($a, fn () => app(OrderService::class)->place([
            'items' => [['item_id' => $item->id, 'qty' => 2]], 'customer_phone' => '01000000001', 'coupon' => 'BACK10', 'source' => 'pos', 'type' => 'takeaway',
        ], Branch::query()->first()));
        $this->assertSame(180.0, $this->as($a, fn () => Order::query()->sole()->total));
        $this->assertSame(['sent' => 1, 'redeemed' => 1, 'revenue' => 180.0], $this->as($a, fn () => app(Automations::class)->results($winback)));
        $this->actingAs($owner)->get($this->dashUrl('/campaigns?tab=automations'))->assertOk()->assertSee('180');

        $this->actingAs($owner)->post($this->dashUrl('/campaigns/automations/winback/toggle'))->assertRedirect();
        $this->assertFalse($winback->fresh()->is_enabled);
    }

    public function test_automations_need_the_campaigns_feature_in_the_dashboard(): void
    {
        $pro = $this->restaurant('cafe-pro', 'pro');
        $this->actingAs($this->owner($pro))->get($this->dashUrl('/campaigns?tab=automations'))->assertForbidden();
        $this->actingAs($this->owner($pro))->post($this->dashUrl('/campaigns/automations/welcome/toggle'))->assertForbidden();
        $this->assertCount(0, $this->as($pro, fn () => CampaignAutomation::query()->get()));
    }
}
