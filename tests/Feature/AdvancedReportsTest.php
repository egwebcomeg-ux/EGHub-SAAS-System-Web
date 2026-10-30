<?php

namespace Tests\Feature;

use App\Billing\Billing;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\MenuItem;
use App\Models\MenuItemView;
use App\Models\Order;
use App\Models\Tenant;
use App\Notifications\WeeklyReport;
use App\Services\Reports\AdvancedReport;
use App\Services\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Notification;
use Tests\TenantTestCase;

class AdvancedReportsTest extends TenantTestCase
{
    private int $n = 0;

    private function sale(Tenant $t, float $total, string $at, array $extra = [], ?MenuItem $item = null, int $qty = 1): Order
    {
        return $this->as($t, function () use ($total, $at, $extra, $item, $qty) {
            $order = Order::query()->create($extra + [
                'branch_id' => Branch::query()->value('id'), 'number' => ++$this->n, 'status' => 'served', 'type' => 'dine_in',
                'source' => 'qr', 'subtotal' => $total, 'total' => $total, 'payment_status' => 'paid', 'payment_method' => 'cash',
            ]);
            $order->forceFill(['created_at' => local_input($at)])->save(); // $at is restaurant time
            $order->items()->create(['menu_item_id' => $item?->id, 'name' => $item->name ?? 'Latte', 'unit_price' => $total / $qty,
                'quantity' => $qty, 'line_total' => $total]);

            return $order;
        });
    }

    public function test_each_item_beacon_adds_one_view_for_today(): void
    {
        $a = $this->restaurant('cafe-a');
        $latte = $this->item($a, 'Latte');
        $hidden = $this->item($a, 'Hidden');
        $this->as($a, fn () => $hidden->update(['is_active' => false]));
        $other = $this->item($this->restaurant('cafe-b'), 'Other');

        $this->post($this->pathUrl('cafe-a', "i/{$latte->id}/view"))->assertNoContent();
        $this->post($this->subUrl('cafe-a', "i/{$latte->id}/view"))->assertNoContent();
        $this->post($this->pathUrl('cafe-a', "i/{$hidden->id}/view"))->assertNoContent();
        $this->post($this->pathUrl('cafe-a', "i/{$other->id}/view"))->assertNoContent(); // another restaurant's item
        $this->post($this->pathUrl('cafe-a', 'i/999999/view'))->assertNoContent();

        $rows = MenuItemView::query()->withoutGlobalScopes()->get();
        $this->assertCount(1, $rows);
        $this->assertSame([$latte->id, 2, 'cafe-a'], [$rows[0]->menu_item_id, $rows[0]->views, $rows[0]->tenant_id]);
        $this->assertSame(local_today(), $rows[0]->day->toDateString());

        // the menu page tells the script where to send it
        $this->get($this->pathUrl('cafe-a'))->assertOk()->assertSee('i\/0\/view', false);
    }

    public function test_advanced_reports_need_the_feature_or_the_add_on(): void
    {
        $pro = $this->restaurant('cafe-pro', 'pro');
        $owner = $this->owner($pro);
        $res = $this->actingAs($owner)->get($this->dashUrl('/reports'))->assertOk()->assertSee('data-advanced-locked', false)->assertDontSee('data-heatmap', false);
        $this->assertNull($res->viewData('extra'));
        $this->actingAs($owner)->get($this->dashUrl('/reports/export/items'))->assertForbidden();

        app(Billing::class)->setAddon($pro, 'reports_pack', 1);
        $res = $this->actingAs($owner)->get($this->dashUrl('/reports'))->assertOk()->assertSee('data-heatmap', false)->assertSee('data-comparison', false);
        $this->assertNotNull($res->viewData('extra'));
        $this->actingAs($owner)->get($this->dashUrl('/reports/export/items'))->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
    }

    public function test_period_comparison_and_item_performance(): void
    {
        $a = $this->restaurant('cafe-a', 'business');
        $latte = $this->item($a, 'Latte');
        $cake = $this->item($a, 'Cake', 40);
        $now = local_at()->setTime(13, 0);
        // this week (last 7 days): 3 sales = 600; the 7 days before: 2 sales = 200; one cancelled is ignored
        $this->sale($a, 100, $now->toDateTimeString(), [], $latte);
        $this->sale($a, 200, $now->copy()->subDays(1)->toDateTimeString(), ['source' => 'pos', 'type' => 'takeaway'], $latte, 2);
        $this->sale($a, 300, $now->copy()->subDays(6)->toDateTimeString(), [], $cake);
        $this->sale($a, 999, $now->toDateTimeString(), ['status' => 'cancelled'], $cake);
        $this->sale($a, 120, $now->copy()->subDays(7)->toDateTimeString());
        $this->sale($a, 80, $now->copy()->subDays(13)->toDateTimeString());
        $this->sale($a, 5000, $now->copy()->subDays(14)->toDateTimeString()); // outside both periods
        $this->as($a, function () use ($latte, $cake) {
            MenuItemView::query()->create(['menu_item_id' => $latte->id, 'day' => local_today(), 'views' => 4]);
            MenuItemView::query()->create(['menu_item_id' => $cake->id, 'day' => local_at()->subDay()->toDateString(), 'views' => 20]);
            MenuItemView::query()->create(['menu_item_id' => $cake->id, 'day' => local_at()->subDays(30)->toDateString(), 'views' => 500]); // outside
        });

        $x = $this->actingAs($this->owner($a))->get($this->dashUrl('/reports?range=7d'))->assertOk()->viewData('extra');
        $c = $x['comparison'];
        $this->assertSame([600.0, 200.0, 200.0], [$c['revenue']['current'], $c['revenue']['previous'], $c['revenue']['delta']]);
        $this->assertSame([3.0, 2.0, 50.0], [$c['orders']['current'], $c['orders']['previous'], $c['orders']['delta']]);
        $this->assertSame([200.0, 100.0, 100.0], [$c['average']['current'], $c['average']['previous'], $c['average']['delta']]);
        $this->assertNull(AdvancedReport::delta(10, 0));
        $this->assertSame(-25.0, AdvancedReport::delta(75, 100));

        // Cake: opened 20 times, ordered once → 5%; Latte opened 4 times (below the minimum) is not in the "low" list
        $this->assertSame([['Cake', 20, 1, 1, 5.0]], array_map(fn ($r) => [$r['name'], $r['views'], $r['qty'], $r['orders'], $r['conversion']], $x['items']['low']));
        $latteRow = collect($x['items']['rows'])->firstWhere('name', 'Latte');
        $this->assertSame([4, 3, 2], [$latteRow['views'], $latteRow['qty'], $latteRow['orders']]);

        // heat map: 3 orders in the 13:00 column; sources and types shares
        $this->assertSame(3, array_sum(array_column($x['heatmap']['cells'], 13)));
        $this->assertSame(1, $x['heatmap']['cells'][$now->dayOfWeek][13]);
        $this->assertSame(['qr' => 2, 'pos' => 1], collect($x['sources'])->pluck('orders', 'key')->all());
        $this->assertSame(66.7, collect($x['sources'])->firstWhere('key', 'qr')['share']);
        $this->assertSame(['dine_in', 'takeaway'], array_column($x['types'], 'key'));
        $this->assertSame([], $x['branches']); // one branch only

        $csv = $this->actingAs($this->owner($a))->get($this->dashUrl('/reports/export/comparison?range=7d'))->assertOk()->streamedContent();
        $this->assertStringContainsString('600', $csv);
        $this->assertStringContainsString('200', $csv);
        foreach (['items', 'heatmap', 'branches', 'sources', 'types', 'guests'] as $table) {
            $this->actingAs($this->owner($a))->get($this->dashUrl("/reports/export/$table?range=7d"))->assertOk();
        }
        $this->actingAs($this->owner($a))->get($this->dashUrl('/reports/export/secrets'))->assertNotFound();
    }

    public function test_branches_loyalty_and_new_versus_returning_guests(): void
    {
        $a = $this->restaurant('cafe-a', 'business');
        [$main, $second, $old, $fresh] = $this->as($a, function () {
            $second = Branch::query()->create(['name' => 'فرع ٢', 'slug' => 'second', 'is_active' => true]);

            return [Branch::query()->orderBy('id')->first(), $second,
                Customer::query()->create(['phone' => '01000000001', 'name' => 'Old']),
                Customer::query()->create(['phone' => '01000000002', 'name' => 'New'])];
        });
        $now = local_at()->setTime(12, 0);
        $this->sale($a, 50, $now->copy()->subDays(40)->toDateTimeString(), ['customer_id' => $old->id]); // first visit long ago
        $this->sale($a, 100, $now->toDateTimeString(), ['customer_id' => $old->id, 'branch_id' => $main->id]);
        $this->sale($a, 300, $now->toDateTimeString(), ['customer_id' => $fresh->id, 'branch_id' => $second->id]);
        $this->sale($a, 100, $now->toDateTimeString(), ['branch_id' => $second->id]);

        $x = $this->actingAs($this->owner($a))->get($this->dashUrl('/reports?range=today'))->assertOk()->assertSee('data-branch-comparison', false)->viewData('extra');
        $this->assertSame([['فرع ٢', 400.0, 2, 200.0], [$main->name, 100.0, 1, 100.0]],
            array_map(fn ($b) => [$b['name'], $b['revenue'], $b['orders'], $b['average']], $x['branches']));
        $this->assertSame([['new' => 1, 'returning' => 1]], array_map(fn ($w) => ['new' => $w['new'], 'returning' => $w['returning']], $x['weeks']));
        $this->assertNotNull($x['loyalty']);   // Business has the loyalty card
        $this->assertSame(0, $x['loyalty']['cards']);

        // a branch filter hides the comparison table
        $this->assertSame([], $this->actingAs($this->owner($a))->get($this->dashUrl('/reports?range=today&branch='.$second->id))->viewData('extra')['branches']);
    }

    public function test_a_restaurant_never_sees_another_restaurants_numbers(): void
    {
        $a = $this->restaurant('cafe-a', 'business');
        $b = $this->restaurant('cafe-b', 'business');
        $bLatte = $this->item($b, 'B Latte');
        $this->sale($b, 777, local_at()->toDateTimeString(), [], $bLatte);
        $this->as($b, fn () => MenuItemView::query()->create(['menu_item_id' => $bLatte->id, 'day' => local_today(), 'views' => 50]));

        $x = $this->actingAs($this->owner($a))->get($this->dashUrl('/reports'))->assertOk()->assertDontSee('B Latte')->viewData('extra');
        $this->assertSame(0.0, $x['comparison']['revenue']['current']);
        $this->assertSame([], $x['items']['rows']);
        $this->assertSame(0, $x['heatmap']['max']);
        $csv = $this->actingAs($this->owner($a))->get($this->dashUrl('/reports/export/items'))->streamedContent();
        $this->assertStringNotContainsString('B Latte', $csv);
    }

    public function test_the_weekly_email_goes_only_to_restaurants_that_turned_it_on(): void
    {
        Notification::fake();
        $on = $this->restaurant('cafe-on', 'business');
        $off = $this->restaurant('cafe-off', 'business');
        $noFeature = $this->restaurant('cafe-pro', 'pro');
        $this->as($on, fn () => Settings::put(['weekly_report_email' => '1']));
        $this->as($noFeature, fn () => Settings::put(['weekly_report_email' => '1']));
        $monday = CarbonImmutable::parse('next monday')->setTime(8, 0);
        $this->sale($on, 250, $monday->subDays(3)->toDateTimeString());   // last week (Friday)
        $this->sale($on, 100, $monday->subDays(10)->toDateTimeString());  // the week before
        $this->sale($on, 999, $monday->toDateTimeString());               // this week: not in the email
        $this->travelTo($monday);

        $this->artisan('egmenu:weekly-report')->expectsOutputToContain('weekly reports sent: 1')->assertSuccessful();

        Notification::assertSentTo($this->owner($on), WeeklyReport::class, function (WeeklyReport $n) use ($monday) {
            $mail = $n->toMail($this->owner(Tenant::find('cafe-on')));

            return $n->report['comparison']['revenue']['current'] === 250.0
                && $n->report['comparison']['revenue']['previous'] === 100.0
                && $n->report['comparison']['revenue']['delta'] === 150.0
                && $n->report['from'] === $monday->subWeek()->format('Y/m/d')
                && str_contains(implode("\n", $mail->introLines), '+150%');
        });
        Notification::assertNotSentTo($this->owner($off), WeeklyReport::class);
        Notification::assertNotSentTo($this->owner($noFeature), WeeklyReport::class);
        Notification::assertCount(1);

        // the command run again the same morning (manual rerun, overlapping cron) sends nothing new
        $this->artisan('egmenu:weekly-report')->expectsOutputToContain('weekly reports sent: 0')->assertSuccessful();
        Notification::assertCount(1);
    }

    public function test_the_weekly_email_is_switched_on_from_settings_and_scheduled_on_monday(): void
    {
        $a = $this->restaurant('cafe-a', 'business');
        $form = ['brand_name' => 'X', 'currency' => 'ج.م', 'currency_en' => 'EGP', 'color_primary' => '#F26522', 'color_secondary' => '#0A2540',
            'color_bg' => '#FFFFFF', 'font' => 'Cairo', 'default_locale' => 'ar'];
        $this->actingAs($this->owner($a))->get($this->dashUrl('/settings'))->assertOk()->assertSee('name="weekly_report_email"', false);
        $this->actingAs($this->owner($a))->post($this->dashUrl('/settings'), $form + ['weekly_report_email' => '1'])->assertSessionHasNoErrors();
        $this->as($a, fn () => $this->assertSame('1', Settings::get('weekly_report_email')));
        $this->actingAs($this->owner($a))->post($this->dashUrl('/settings'), $form + ['weekly_report_email' => '0'])->assertSessionHasNoErrors();
        $this->as($a, fn () => $this->assertSame('0', Settings::get('weekly_report_email')));

        $event = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains((string) $e->command, 'egmenu:weekly-report'));
        $this->assertNotNull($event);
        $this->assertSame('0 8 * * 1', $event->expression);
    }
}
