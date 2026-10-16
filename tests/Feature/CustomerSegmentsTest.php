<?php

namespace Tests\Feature;

use App\Billing\Credits;
use App\Models\Branch;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\MembershipTier;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\WhatsAppMessage;
use App\Services\Customers\Segments;
use App\Services\Marketing\Campaigns;
use Tests\TenantTestCase;

/** Computed guest segments (new / regular / VIP / at risk / lost) on the customers page, the export and campaigns. */
class CustomerSegmentsTest extends TenantTestCase
{
    private function customer(Tenant $t, string $phone, array $attrs = [], int $recentOrders = 0, int $daysAgo = 5): Customer
    {
        return $this->as($t, function () use ($phone, $attrs, $recentOrders, $daysAgo) {
            $c = Customer::query()->create(['phone' => $phone, 'name' => 'Guest '.substr($phone, -3)] + $attrs);
            $branch = Branch::query()->first();
            for ($i = 0; $i < $recentOrders; $i++) {
                Order::query()->create([
                    'branch_id' => $branch->id, 'customer_id' => $c->id, 'number' => (int) (Order::query()->max('number') ?? 0) + 1,
                    'status' => 'served', 'total' => 100, 'created_at' => now()->subDays($daysAgo + $i),
                ]);
            }

            return $c;
        });
    }

    /** A guest list with one guest per segment (and one in none), plus the same phones in another restaurant. */
    private function guests(Tenant $a): array
    {
        $guests = [
            'new' => $this->customer($a, '01000000001', ['visits_count' => 1, 'last_visit_at' => now()->subDays(5)]),
            'regular' => $this->customer($a, '01000000002', ['visits_count' => 4, 'last_visit_at' => now()->subDays(3), 'total_spent' => 400], 3, 3),
            'at_risk' => $this->customer($a, '01000000003', ['visits_count' => 5, 'last_visit_at' => now()->subDays(40), 'total_spent' => 500], 3, 40),
            'lost' => $this->customer($a, '01000000004', ['visits_count' => 2, 'last_visit_at' => now()->subDays(70), 'total_spent' => 200]),
            'vip' => $this->customer($a, '01000000005', ['visits_count' => 20, 'last_visit_at' => now()->subDays(3), 'total_spent' => 9000], 1, 3),
            'none' => $this->customer($a, '01000000006', ['visits_count' => 1, 'last_visit_at' => now()->subDays(45)]),
        ];
        for ($i = 7; $i <= 12; $i++) {
            $this->customer($a, sprintf('010000000%02d', $i), ['visits_count' => 2, 'last_visit_at' => now()->subDays(10), 'total_spent' => 100 * $i]);
        }
        $b = $this->restaurant('cafe-b', 'business');
        $this->customer($b, '01000000001', ['visits_count' => 2, 'last_visit_at' => now()->subDays(70)]);
        $this->customer($b, '01000000005', ['visits_count' => 20, 'last_visit_at' => now()->subDays(3), 'total_spent' => 9000]);

        return $guests;
    }

    public function test_each_rule_picks_the_right_guests(): void
    {
        $a = $this->restaurant('cafe-a', 'business');
        $g = $this->guests($a);

        $this->as($a, function () use ($g) {
            $segments = app(Segments::class);
            // 11 spenders → the top 10% is the single top spender
            $this->assertSame(['new' => 1, 'regular' => 1, 'vip' => 1, 'at_risk' => 1, 'lost' => 1], $segments->counts());
            foreach (['new', 'regular', 'vip', 'at_risk', 'lost'] as $key) {
                $this->assertSame([$g[$key]->id], $segments->apply(Customer::query(), $key)->pluck('id')->all(), $key);
                $this->assertSame($key, $segments->of($g[$key]->fresh()), "badge of $key");
            }
            $this->assertNull($segments->of($g['none']->fresh()));
        });
    }

    public function test_the_highest_membership_tier_counts_as_vip(): void
    {
        $a = $this->restaurant('cafe-a', 'business');
        $g = $this->guests($a);
        $this->as($a, function () {
            MembershipTier::query()->create(['name' => 'فضي', 'min_visits' => 2, 'min_spent' => 0, 'discount_percent' => 5, 'sort_order' => 1]);
            MembershipTier::query()->create(['name' => 'ذهبي', 'min_visits' => 4, 'min_spent' => 300, 'discount_percent' => 10, 'sort_order' => 2]);
        });

        $vip = $this->as($a, fn () => app(Segments::class)->apply(Customer::query(), 'vip')->pluck('id')->sort()->values()->all());
        $this->assertSame([$g['regular']->id, $g['at_risk']->id, $g['vip']->id], $vip);
        $this->assertSame('vip', $this->as($a, fn () => app(Segments::class)->of($g['regular']->fresh())));
    }

    public function test_the_customers_page_filters_by_segment_and_the_export_follows(): void
    {
        $a = $this->restaurant('cafe-a', 'business');
        $g = $this->guests($a);
        $owner = $this->owner($a);

        $html = $this->actingAs($owner)->get($this->dashUrl('/customers'))->assertOk()
            ->assertSee('data-segment="lost"', false)->assertSee('راح · 1')->assertSee('VIP · 1')->assertSee('data-row-segment="vip"', false)->getContent();
        $this->assertSame(5, preg_match_all('/data-row-segment="/', $html));

        $this->actingAs($owner)->get($this->dashUrl('/customers?segment=lost'))->assertOk()
            ->assertSee($g['lost']->phone)->assertDontSee($g['vip']->phone)->assertDontSee($g['new']->phone);
        $this->actingAs($owner)->get($this->dashUrl('/customers?segment=nope'))->assertOk()->assertSee($g['vip']->phone);

        $csv = $this->actingAs($owner)->get($this->dashUrl('/customers/export?segment=at_risk'))->assertOk()->streamedContent();
        $this->assertStringContainsString($g['at_risk']->phone, $csv);
        $this->assertStringContainsString('بيبعد', $csv);
        $this->assertStringNotContainsString($g['lost']->phone, $csv);
        $this->assertStringNotContainsString($g['vip']->phone, $csv);
        $all = $this->actingAs($owner)->get($this->dashUrl('/customers/export'))->assertOk()->streamedContent();
        $this->assertSame(13, substr_count($all, "\n")); // header + 12 guests
    }

    public function test_a_campaign_can_target_a_segment(): void
    {
        $a = $this->restaurant('cafe-a', 'business');
        $g = $this->guests($a);
        app(Credits::class)->add($a, 20, 'adjustment');

        $this->assertSame(1, $this->as($a, fn () => app(Campaigns::class)->preview('lost')['audience']));
        $this->actingAs($this->owner($a))->get($this->dashUrl('/campaigns?segment=lost'))->assertOk()->assertSee('راحوا (مزاروش من أكتر من 60 يوم)');
        $this->actingAs($this->owner($a))->post($this->dashUrl('/campaigns'), ['name' => 'Come back', 'segment' => 'lost', 'message' => 'Hi {name}', 'when' => 'now'])
            ->assertSessionHasNoErrors();

        $campaign = $this->as($a, fn () => Campaign::query()->sole());
        $this->assertSame(['sent', 1, 'lost'], [$campaign->status, $campaign->sent_count, $campaign->segment]);
        $this->assertSame([$g['lost']->id], $this->as($a, fn () => WhatsAppMessage::query()->pluck('customer_id')->all()));
        $this->assertStringContainsString('راحوا', $campaign->audienceLabel());
    }
}
