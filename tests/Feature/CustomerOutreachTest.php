<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\LoyaltyCard;
use App\Models\LoyaltyProgram;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Tenant;
use App\Services\Customers\Segments;
use App\Services\Loyalty\LoyaltyService;
use App\Services\Settings;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * What the restaurant does with its guest list: the groups on the customers page, the free campaign sent from the
 * staff's own phone (send list, «وقف», results), the Meta Ads export, SMS when a provider is set, and the welcome gift.
 */
class CustomerOutreachTest extends TenantTestCase
{
    private function customer(Tenant $t, string $phone, array $attrs = [], array $orders = []): Customer
    {
        return $this->as($t, function () use ($phone, $attrs, $orders) {
            $c = Customer::query()->create($attrs + ['phone' => $phone, 'name' => 'Guest '.substr($phone, -3)]);
            foreach ($orders as $order) {
                Order::query()->create($order + [
                    'branch_id' => Branch::query()->value('id'), 'customer_id' => $c->id, 'customer_phone' => $phone,
                    'number' => (int) (Order::query()->max('number') ?? 0) + 1, 'status' => 'served', 'source' => 'pos', 'total' => 100,
                    'tracking_token' => random_token(12),
                ]);
            }

            return $c;
        });
    }

    /** One guest per group at cafe-a, and lookalikes at cafe-b that must never count there. */
    private function guestList(Tenant $a): array
    {
        $recent = [['created_at' => now()->subDays(2)], ['created_at' => now()->subDays(20)], ['created_at' => now()->subDays(50)]];
        $g = [
            'new' => $this->customer($a, '01000000001', ['visits_count' => 1, 'last_visit_at' => now()->subDays(3), 'total_spent' => 100], [['created_at' => now()->subDays(3)]]),
            'regular' => $this->customer($a, '01000000002', ['visits_count' => 5, 'last_visit_at' => now()->subDays(2), 'total_spent' => 500], $recent),
            'lost' => $this->customer($a, '01000000003', ['visits_count' => 2, 'last_visit_at' => now()->subDays(75), 'total_spent' => 200], [['created_at' => now()->subDays(75)]]),
            'vip' => $this->customer($a, '01000000004', ['visits_count' => 2, 'last_visit_at' => now()->subDays(10), 'total_spent' => 9000], [['created_at' => now()->subDays(40)]]),
            'imported' => $this->customer($a, '01000000005', ['source' => 'import', 'total_spent' => 300]),
            'birthday' => $this->customer($a, '01000000006', ['birthday' => now()->subYears(30)->addDays(2)]),
        ];
        // an imported guest who already ordered online is not "never ordered online"
        $this->customer($a, '01000000007', ['source' => 'import'], [['source' => 'online', 'created_at' => now()->subDays(50)]]);
        for ($i = 8; $i <= 12; $i++) {
            $this->customer($a, sprintf('010000000%02d', $i), ['total_spent' => 10 * $i]);
        }
        $b = $this->restaurant('cafe-b');
        $this->customer($b, '01000000005', ['source' => 'import']);
        $this->customer($b, '01000000002', ['visits_count' => 9]);

        return $g;
    }

    public function test_the_groups_count_guests_and_spend_of_this_restaurant_only(): void
    {
        $a = $this->restaurant('cafe-a');
        $g = $this->guestList($a);
        $summary = collect($this->as($a, fn () => app(Segments::class)->summary()))->keyBy('key');

        foreach (['new', 'regular', 'lost', 'vip', 'imported', 'birthday'] as $key) {
            $this->assertSame([$g[$key]->id], $this->as($a, fn () => app(Segments::class)->query($key)->pluck('id')->all()), $key);
        }
        $this->assertSame(1, $summary['imported']['count']);
        $this->assertSame(300.0, $summary['imported']['revenue']);
        $this->assertSame(1, $summary['regular']['count']);
        $this->assertSame(0, $summary['at_risk']['count']);
        // each group says what to do about it
        $this->assertStringContainsString('أونلاين', $summary['imported']['action']);

        $this->actingAs($this->owner($a))->get($this->dashUrl('/customers?segment=imported'))->assertOk()
            ->assertSee('data-segment="imported"', false)->assertSee('data-segment-action', false)->assertSee('data-meta-guide', false)
            ->assertSee('عرّفهم إن محلك بقى أونلاين')->assertSee('01000000005')->assertDontSee('01000000002');
    }

    public function test_the_manual_campaign_builds_a_send_list_without_opted_out_guests_and_counts_results(): void
    {
        $a = $this->restaurant('cafe-a');
        $this->item($a);
        $mona = $this->customer($a, '01011111111', ['source' => 'import', 'name' => 'Mona Adel']);
        $this->customer($a, '01022222222', ['source' => 'import', 'whatsapp_opt_out' => true]);
        $sara = $this->customer($a, '01033333333', ['source' => 'import', 'name' => 'Sara']);
        $owner = $this->owner($a);

        $this->actingAs($owner)->post($this->dashUrl('/campaigns'), [
            'name' => 'We are online', 'channel' => 'manual', 'segment' => 'imported', 'message' => 'أهلاً {name} كودك {code} واطلب من {link}',
            'personal_coupon' => '1', 'personal_type' => 'percent', 'personal_value' => 15, 'personal_days' => 7,
        ])->assertRedirect();
        $campaign = $this->as($a, fn () => Campaign::query()->where('channel', 'manual')->sole());
        $rows = $this->as($a, fn () => CampaignRecipient::query()->with('coupon')->orderBy('id')->get());
        $this->assertSame(['01011111111', '01033333333'], $rows->pluck('phone')->all()); // the opted-out guest is not there
        $this->assertSame($mona->id, $rows[0]->coupon->customer_id);
        $this->assertSame(1, $rows[0]->coupon->max_uses);

        $page = $this->actingAs($owner)->get($this->dashUrl('/customers/outreach/'.$campaign->id))->assertOk();
        // the send list lives in Campaigns: that is the page the navigation marks
        $this->assertMatchesRegularExpression('#href="[^"]*/campaigns"[^>]*aria-current="page"#', $page->getContent());
        $text = rawurlencode('أهلاً Mona كودك '.$rows[0]->coupon->code.' واطلب من ');
        $page->assertSee('wa.me/201011111111?text='.$text, false)->assertSee('ref%3D'.$rows[0]->ref, false);

        $this->actingAs($owner)->postJson($this->dashUrl("/customers/outreach/{$campaign->id}/rows/{$rows[0]->id}/sent"))->assertOk()->assertJson(['sent' => 1]);
        // «وقف»: out of this list and of every later one
        $this->actingAs($owner)->post($this->dashUrl("/customers/outreach/{$campaign->id}/rows/{$rows[1]->id}/opt-out"))->assertRedirect();
        $this->assertTrue($sara->fresh()->whatsapp_opt_out);

        // results: an order with Mona's coupon, and one that came from her link (?ref=)
        $item = $this->as($a, fn () => MenuItem::query()->first());
        $order = fn (array $extra) => $this->postJson($this->subUrl('cafe-a', 'order'), $extra + ['client_uuid' => (string) Str::uuid(),
            'items' => [['item_id' => $item->id, 'qty' => 1]], 'type' => 'takeaway', 'customer_name' => 'Mona', 'customer_phone' => '01011111111']);
        $order(['coupon' => $rows[0]->coupon->code])->assertOk();
        $order(['ref' => $rows[0]->ref])->assertOk();
        $order(['ref' => 'ffffffffff'])->assertOk(); // an unknown ref counts for nothing
        $this->actingAs($owner)->get($this->dashUrl('/customers/outreach/'.$campaign->id))->assertOk()
            ->assertSeeInOrder(['data-outreach-orders', '>2<'], false);

        // a later campaign leaves Sara out («وقف») and Mona too: she has ordered online now
        $this->customer($a, '01044444444', ['source' => 'import', 'name' => 'Omar']);
        $this->actingAs($owner)->post($this->dashUrl('/campaigns'), ['name' => 'Again', 'channel' => 'manual', 'segment' => 'imported', 'message' => 'Hi {name}']);
        $this->assertSame(['01044444444'], $this->as($a, fn () => CampaignRecipient::query()->where('campaign_id', '!=', $campaign->id)->pluck('phone')->all()));
        $this->assertSame(0, $this->as($a, fn () => Campaign::query()->where('channel', 'whatsapp')->count()));
    }

    public function test_a_campaign_row_of_another_restaurant_is_not_found(): void
    {
        $a = $this->restaurant('cafe-a');
        $b = $this->restaurant('cafe-b');
        $this->customer($b, '01011111111', ['source' => 'import']);
        $this->actingAs($this->owner($b))->post($this->dashUrl('/campaigns'), ['name' => 'B', 'channel' => 'manual', 'segment' => 'imported', 'message' => 'Hi']);
        $campaign = $this->as($b, fn () => Campaign::query()->sole());
        $row = $this->as($b, fn () => CampaignRecipient::query()->sole());

        $this->actingAs($this->owner($a))->get($this->dashUrl('/customers/outreach/'.$campaign->id))->assertNotFound();
        $this->actingAs($this->owner($a))->postJson($this->dashUrl("/customers/outreach/{$campaign->id}/rows/{$row->id}/sent"))->assertNotFound();
        $this->assertNull($row->fresh()->sent_at);
    }

    public function test_claim_links_in_a_campaign_and_their_csv(): void
    {
        $a = $this->restaurant('cafe-a');
        $mona = $this->customer($a, '01011111111', ['source' => 'import', 'name' => 'Mona', 'addresses' => [['label' => '', 'zone_id' => null, 'text' => 'Road 9']]]);
        $owner = $this->owner($a);
        $this->actingAs($owner)->post($this->dashUrl('/campaigns'), ['name' => 'Invite', 'channel' => 'manual', 'segment' => 'imported', 'message' => 'Hi {name}: {link}', 'with_claim' => '1']);
        $campaign = $this->as($a, fn () => Campaign::query()->sole());
        $row = $this->as($a, fn () => CampaignRecipient::query()->sole());
        $this->assertStringStartsWith('http://egmenu.test/cafe-a/me/claim/', $row->claim_link); // the restaurant's own address

        $csv = $this->actingAs($owner)->get($this->dashUrl("/customers/outreach/{$campaign->id}/links.csv"))->assertOk()->streamedContent();
        $this->assertStringContainsString('phone,name,link,code,message', $csv);
        $this->assertStringContainsString('01011111111,Mona,'.$row->claim_link, $csv);
        $this->assertNotNull($this->as($a, fn () => AuditLog::query()->where('action', 'campaign.links_exported')->first()));

        // the link greets Mona with her address
        $this->get($row->claim_link)->assertOk()->assertSee('Road 9');
        // a cashier can neither build campaigns nor export
        $cashier = $this->staff($a, 'cashier', 'cash@cafe-a.test');
        $this->actingAs($cashier)->get($this->dashUrl('/campaigns'))->assertForbidden();
        $this->actingAs($cashier)->post($this->dashUrl('/campaigns'), ['name' => 'X', 'channel' => 'manual', 'segment' => 'imported', 'message' => 'Hi'])->assertForbidden();
        $this->actingAs($cashier)->get($this->dashUrl('/customers/outreach'))->assertForbidden();
        $this->actingAs($cashier)->get($this->dashUrl("/customers/outreach/{$campaign->id}/links.csv"))->assertForbidden();
    }

    public function test_the_meta_audience_export_is_in_customer_list_format_for_owner_and_manager_only(): void
    {
        $a = $this->restaurant('cafe-a');
        $this->customer($a, '01011111111', ['source' => 'import', 'name' => 'Mona Adel Hassan', 'email' => 'Mona@Example.com']);
        $this->customer($a, '01022222222', ['source' => 'import', 'whatsapp_opt_out' => true]);
        $this->customer($this->restaurant('cafe-b'), '01033333333', ['source' => 'import']);

        $csv = $this->actingAs($this->owner($a))->get($this->dashUrl('/customers/audience-export?segment=imported'))->assertOk()->streamedContent();
        $lines = array_values(array_filter(explode("\n", trim($csv))));
        $this->assertSame('phone,fn,ln,email,country', $lines[0]);
        $this->assertSame('+201011111111,mona,"adel hassan",mona@example.com,eg', $lines[1]);
        $this->assertCount(2, $lines); // no opted-out guest, nobody of cafe-b
        $audit = $this->as($a, fn () => AuditLog::query()->where('action', 'customers.audience_exported')->sole());
        $this->assertSame(1, $audit->meta['rows']);

        $this->actingAs($this->staff($a, 'manager', 'm@cafe-a.test'))->get($this->dashUrl('/customers/audience-export?audience=imported'))->assertOk();
        $this->actingAs($this->staff($a, 'cashier', 'c@cafe-a.test'))->get($this->dashUrl('/customers/audience-export?audience=imported'))->assertForbidden();
        $this->actingAs($this->owner($a))->get($this->dashUrl('/customers/audience-export?audience=nope'))->assertNotFound();
    }

    public function test_bulk_sms_shows_only_with_a_provider_and_the_log_driver_records_each_send(): void
    {
        $a = $this->restaurant('cafe-a');
        $this->customer($a, '01011111111', ['source' => 'import', 'name' => 'Mona']);
        $this->customer($a, '01022222222', ['source' => 'import', 'name' => 'Sara']);
        $owner = $this->owner($a);
        $this->actingAs($owner)->post($this->dashUrl('/campaigns'), ['name' => 'SMS', 'channel' => 'manual', 'segment' => 'imported', 'message' => 'Hi {name}']);
        $campaign = $this->as($a, fn () => Campaign::query()->sole());

        config(['sms.driver' => null]);
        $this->actingAs($owner)->get($this->dashUrl('/customers/outreach/'.$campaign->id))->assertOk()->assertDontSee('data-outreach-sms', false);
        $this->actingAs($owner)->post($this->dashUrl("/customers/outreach/{$campaign->id}/sms"))->assertNotFound();

        config(['sms.driver' => 'log', 'sms.cost_per_segment' => 0.25]);
        // Sara answered «وقف» after the list was made: she is skipped when her turn comes
        $this->as($a, fn () => Customer::query()->where('phone', '01022222222')->update(['whatsapp_opt_out' => true]));
        $this->actingAs($owner)->get($this->dashUrl('/customers/outreach/'.$campaign->id))->assertOk()->assertSee('data-outreach-sms', false);
        Log::spy();
        $this->actingAs($owner)->post($this->dashUrl("/customers/outreach/{$campaign->id}/sms"))->assertRedirect();
        Log::shouldHaveReceived('info')->with('SMS', ['to' => '01011111111', 'text' => 'Hi Mona'])->once();
        Log::shouldNotHaveReceived('info', ['SMS', ['to' => '01022222222', 'text' => 'Hi Sara']]);
        $this->assertSame(['sent', 'skipped'], $this->as($a, fn () => CampaignRecipient::query()->orderBy('id')->pluck('sms_status')->all()));
    }

    public function test_sent_via_sms_the_list_goes_out_at_once_only_with_a_provider(): void
    {
        $a = $this->restaurant('cafe-a');
        $this->customer($a, '01011111111', ['source' => 'import', 'name' => 'Mona']);
        $owner = $this->owner($a);
        $send = ['name' => 'By SMS', 'channel' => 'sms', 'segment' => 'imported', 'message' => 'Hi {name}'];

        config(['sms.driver' => null]);
        $this->actingAs($owner)->get($this->dashUrl('/campaigns'))->assertOk()->assertSee('data-via="manual"', false)->assertDontSee('data-via="sms"', false);
        $this->actingAs($owner)->post($this->dashUrl('/campaigns'), $send)->assertSessionHasErrors('channel');

        config(['sms.driver' => 'log']);
        $this->actingAs($owner)->get($this->dashUrl('/campaigns'))->assertOk()->assertSee('data-via="sms"', false);
        Log::spy();
        $this->actingAs($owner)->post($this->dashUrl('/campaigns'), $send)->assertSessionHasNoErrors()->assertRedirect();
        Log::shouldHaveReceived('info')->with('SMS', ['to' => '01011111111', 'text' => 'Hi Mona'])->once();
        $campaign = $this->as($a, fn () => Campaign::query()->sole());
        $this->assertSame(['manual', 'sms', 'done'], [$campaign->channel, $campaign->via(), $campaign->options['sms']]);
        $this->actingAs($owner)->get($this->dashUrl('/campaigns'))->assertOk()->assertSee('data-campaign-via="sms"', false);
    }

    public function test_the_welcome_gift_comes_off_the_first_online_order_once_per_mobile(): void
    {
        $a = $this->restaurant('cafe-a');
        $this->item($a, 'Latte', 100);
        $owner = $this->owner($a);
        $this->actingAs($owner)->post($this->dashUrl('/loyalty/welcome'), ['enabled' => '1', 'type' => 'percent', 'value' => 10])->assertRedirect();

        // the menu tells guests the phone does not know, and the cart says it comes off by itself
        $this->get($this->subUrl('cafe-a'))->assertOk()->assertSee('data-welcome-banner', false)->assertSee('data-welcome-cart', false);

        $item = $this->as($a, fn () => MenuItem::query()->first());
        $order = fn (string $phone, array $extra = []) => $this->postJson($this->subUrl('cafe-a', 'order'), $extra + ['client_uuid' => (string) Str::uuid(),
            'items' => [['item_id' => $item->id, 'qty' => 1]], 'type' => 'takeaway', 'customer_name' => 'Mona', 'customer_phone' => $phone]);
        $order('01011111111')->assertOk();
        $order('01011111111')->assertOk();
        $orders = $this->as($a, fn () => Order::query()->orderBy('id')->get());
        $this->assertSame([10.0, 0.0], $orders->pluck('coupon_discount')->map(fn ($v) => (float) $v)->all());
        $this->assertSame([90.0, 100.0], $orders->pluck('total')->map(fn ($v) => (float) $v)->all());
        // typing the hidden code by hand does not get it twice either
        $code = $this->as($a, fn () => Coupon::query()->where('source', 'welcome')->value('code'));
        $order('01011111111', ['coupon' => $code])->assertStatus(422);
        // another mobile gets it; the gift never shows on the paid campaigns' coupon list
        $order('01099999999')->assertOk();
        $this->assertSame(10.0, (float) $this->as($a, fn () => Order::query()->latest('id')->value('coupon_discount')));

        // off: nothing comes off any more, the banner goes
        $this->actingAs($owner)->post($this->dashUrl('/loyalty/welcome'), ['type' => 'percent', 'value' => 10]);
        $order('01088888888')->assertOk();
        $this->assertSame(0.0, (float) $this->as($a, fn () => Order::query()->latest('id')->value('coupon_discount')));
        $this->get($this->subUrl('cafe-a'))->assertDontSee('data-welcome-banner', false);
    }

    public function test_the_welcome_gift_as_points_is_given_once_with_the_first_counted_online_order(): void
    {
        $a = $this->restaurant('cafe-a', 'full');
        $this->item($a, 'Latte', 100);
        $this->as($a, fn () => LoyaltyProgram::query()->create(['type' => 'points', 'title' => 'Points', 'points_per_egp' => 0, 'is_active' => true,
            'reward_type' => 'free_item', 'stamps_required' => 100, 'reward_label' => 'Free coffee']));
        $this->actingAs($this->owner($a))->post($this->dashUrl('/loyalty/welcome'), ['enabled' => '1', 'type' => 'points', 'value' => 50])->assertRedirect();
        $this->assertSame('points', $this->as($a, fn () => Settings::get('welcome_reward_type')));

        $item = $this->as($a, fn () => MenuItem::query()->first());
        foreach ([1, 2] as $n) {
            $this->postJson($this->subUrl('cafe-a', 'order'), ['client_uuid' => (string) Str::uuid(), 'items' => [['item_id' => $item->id, 'qty' => 1]],
                'type' => 'takeaway', 'customer_name' => 'Mona', 'customer_phone' => '01011111111'])->assertOk();
            $this->as($a, function () {
                $order = Order::query()->latest('id')->first();
                $order->update(['status' => 'served']);
                app(LoyaltyService::class)->orderCompleted($order);
            });
        }
        $this->assertSame(50, $this->as($a, fn () => (int) LoyaltyCard::query()->sole()->stamps));
    }
}
