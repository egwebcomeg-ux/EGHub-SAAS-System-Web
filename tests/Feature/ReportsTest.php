<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\DiningTable;
use App\Models\MenuVisit;
use App\Models\Order;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Tests\TenantTestCase;

class ReportsTest extends TenantTestCase
{
    private int $n = 0;

    private function sale(Tenant $t, float $total, string $at, array $extra = [], string $item = 'Latte', int $qty = 1): Order
    {
        return $this->as($t, function () use ($total, $at, $extra, $item, $qty) {
            $order = Order::query()->create($extra + [
                'branch_id' => Branch::query()->value('id'), 'number' => ++$this->n, 'status' => 'served', 'type' => 'dine_in',
                'source' => 'qr', 'subtotal' => $total, 'total' => $total, 'payment_status' => 'paid', 'payment_method' => 'cash',
            ]);
            $order->forceFill(['created_at' => Carbon::parse($at)])->save();
            $order->items()->create(['name' => $item, 'unit_price' => $total / $qty, 'quantity' => $qty, 'line_total' => $total]);

            return $order;
        });
    }

    public function test_daily_report_numbers(): void
    {
        $a = $this->restaurant('cafe-a');
        $today = now()->setTime(13, 15)->toDateTimeString();
        $this->sale($a, 100, $today, [], 'Latte', 2);
        $this->sale($a, 60, $today, ['payment_method' => 'card', 'source' => 'pos'], 'Tea', 3);
        $this->sale($a, 500, $today, ['status' => 'cancelled']);
        $this->sale($a, 999, now()->subDays(3)->toDateTimeString());

        $res = $this->actingAs($this->owner($a))->get($this->dashUrl('/reports'))->assertOk();
        $report = $res->viewData('report');
        $this->assertSame(160.0, $report['revenue']);
        $this->assertSame(2, $report['orders']);
        $this->assertSame(80.0, $report['average']);
        $this->assertSame(1, $report['cancelled']);
        $this->assertSame(2, $report['hours'][13]);
        $this->assertSame(['Tea', 'Latte'], array_column($report['top_items'], 'name'));   // by quantity
        $this->assertSame('cash', $report['by_method'][0]['key']);

        $week = $this->actingAs($this->owner($a))->get($this->dashUrl('/reports?range=7d'))->viewData('report');
        $this->assertSame(1159.0, $week['revenue']);
        $this->assertCount(7, $week['days']);
    }

    public function test_custom_range_and_export_are_business_only(): void
    {
        $pro = $this->restaurant('cafe-pro', 'pro');
        $this->sale($pro, 50, now()->subDays(20)->toDateTimeString());
        $q = '?range=custom&from='.now()->subDays(25)->toDateString().'&to='.now()->toDateString();
        $this->assertSame(0.0, $this->actingAs($this->owner($pro))->get($this->dashUrl('/reports'.$q))->viewData('report')['revenue']); // falls back to today
        $this->actingAs($this->owner($pro))->get($this->dashUrl('/reports/export'))->assertForbidden();

        $biz = $this->restaurant('cafe-biz', 'business');
        $this->sale($biz, 50, now()->subDays(20)->toDateTimeString(), [], 'Shawarma');
        $other = $this->restaurant('cafe-other', 'business');
        $this->sale($other, 777, now()->subDays(20)->toDateTimeString());

        $this->assertSame(50.0, $this->actingAs($this->owner($biz))->get($this->dashUrl('/reports'.$q))->viewData('report')['revenue']);
        $csv = $this->actingAs($this->owner($biz))->get($this->dashUrl('/reports/export'.$q))->assertOk()->streamedContent();
        $this->assertStringContainsString('50', $csv);
        $this->assertStringNotContainsString('777', $csv);
    }

    public function test_access_by_plan_role_and_branch(): void
    {
        $basic = $this->restaurant('cafe-basic', 'basic');
        $this->actingAs($this->owner($basic))->get($this->dashUrl('/reports'))->assertForbidden();

        $a = $this->restaurant('cafe-a', 'business');
        $this->actingAs($this->staff($a, 'waiter', 'w@a.test'))->get($this->dashUrl('/reports'))->assertForbidden();

        $second = $this->as($a, fn () => Branch::query()->create(['name' => 'Second', 'slug' => 'second']));
        $this->sale($a, 100, now()->toDateTimeString());
        $this->sale($a, 40, now()->toDateTimeString(), ['branch_id' => $second->id]);
        $manager = $this->staff($a, 'manager', 'm@a.test');
        $manager->forceFill(['branch_id' => $second->id])->save();
        $this->assertSame(40.0, $this->actingAs($manager)->get($this->dashUrl('/reports'))->viewData('report')['revenue']);
        $this->assertSame(140.0, $this->actingAs($this->owner($a))->get($this->dashUrl('/reports'))->viewData('report')['revenue']);
    }

    public function test_menu_visits_and_table_scans_are_counted(): void
    {
        $a = $this->restaurant('cafe-a', 'pro');
        $table = $this->as($a, fn () => DiningTable::query()->where('name', '2')->first());

        $this->get($this->menuUrl('cafe-a'));
        $this->get($this->menuUrl('cafe-a')); // same session: still one visit
        $this->get($this->menuUrl("cafe-a/t/{$table->qr_token}"));
        $this->get($this->menuUrl("cafe-a/t/{$table->qr_token}"));

        $day = $this->as($a, fn () => MenuVisit::query()->first());
        $this->assertSame([1, 2], [$day->views, $day->scans]);
        $this->assertSame(2, $this->as($a, fn () => $table->fresh()->scans_count));
        $this->actingAs($this->owner($a))->get($this->dashUrl('/reports'))->assertOk()->assertSee(__('Menu visits'))->assertSee(__('Table :name', ['name' => '2']))
            ->assertViewHas('visits', fn ($v) => (int) $v->views === 1 && (int) $v->scans === 2);
    }
}
