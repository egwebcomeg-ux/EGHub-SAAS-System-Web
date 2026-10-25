<?php

namespace Tests\Feature;

use App\Billing\Billing;
use App\Models\Category;
use App\Models\KitchenStation;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderPrint;
use App\Models\Tenant;
use App\Services\Settings;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TenantTestCase;

/** Printing from the EgHub cashier app: the job JSON of a receipt and of kitchen tickets, and each copy printed once. */
class NativePrintingTest extends TenantTestCase
{
    /** @return array{0:Tenant, 1:KitchenStation, 2:MenuItem, 3:MenuItem, 4:MenuItem, 5:int} a POS + kitchen restaurant: grill, burger, juice, kunafa (no station), the bar's id */
    private function kitchenRestaurant(string $slug = 'cafe-a'): array
    {
        $tenant = $this->restaurant($slug, 'basic');
        app(Billing::class)->setAddon($tenant, 'pos_pack', 1);
        app(Billing::class)->setAddon($tenant, 'kitchen_pack', 1);

        return [$tenant, ...$this->as($tenant, function () {
            $grill = KitchenStation::query()->create(['name' => 'الشواية', 'name_en' => 'Grill']);
            $bar = KitchenStation::query()->create(['name' => 'البار', 'name_en' => 'Bar']);
            $food = Category::query()->create(['name' => 'أكل', 'kitchen_station_id' => $grill->id]);
            $drinks = Category::query()->create(['name' => 'مشروبات', 'kitchen_station_id' => $bar->id]);
            $sweets = Category::query()->create(['name' => 'حلو']);

            return [$grill, MenuItem::query()->create(['category_id' => $food->id, 'name' => 'برجر لحم', 'price' => 120]),
                MenuItem::query()->create(['category_id' => $drinks->id, 'name' => 'عصير مانجا', 'price' => 40]),
                MenuItem::query()->create(['category_id' => $sweets->id, 'name' => 'كنافة', 'price' => 50]), $bar->id];
        })];
    }

    private function sell(Tenant $tenant, array $items, ?string $pay = 'cash'): Order
    {
        $owner = $this->owner($tenant);
        $this->actingAs($owner)->post($this->dashUrl('/shifts/open'), ['opening_cash' => 0]);
        $id = $this->actingAs($owner)->postJson($this->dashUrl('/pos/orders'), [
            'client_uuid' => (string) Str::uuid(), 'type' => 'takeaway', 'items' => $items, 'pay' => $pay, 'customer_name' => 'منى',
        ])->assertOk()->json('id');

        return $this->as($tenant, fn () => Order::query()->findOrFail($id));
    }

    private function jobs(Tenant $tenant, Order $order, array $roles, bool $reprint = false): TestResponse
    {
        return $this->actingAs($this->owner($tenant))->postJson($this->dashUrl("/pos/orders/{$order->id}/print-jobs"), ['roles' => $roles, 'reprint' => $reprint]);
    }

    public function test_the_receipt_job_carries_the_order_in_arabic_with_totals_payment_and_the_e_receipt_qr(): void
    {
        [$a, , $burger] = $this->kitchenRestaurant();
        $this->as($a, fn () => Settings::put(['brand_name' => 'كافيه أ', 'pos_print_copies' => '2']));
        $order = $this->sell($a, [['item_id' => $burger->id, 'qty' => 2, 'notes' => 'من غير بصل']]);

        $job = $this->jobs($a, $order, ['receipt'])->assertOk()->assertJsonPath('skipped', [])->json('jobs.0');

        $this->assertSame('receipt', $job['role']);
        $this->assertSame(2, $job['copies']);
        $this->assertTrue($job['openDrawer']); // paid in cash, drawer setting on by default
        $this->assertTrue($job['cut']);
        $texts = collect($job['lines'])->where('t', 'text')->pluck('text');
        $this->assertContains('كافيه أ', $texts);
        $this->assertContains('#'.$order->number, $texts);
        $this->assertSame(3, collect($job['lines'])->firstWhere('text', '#'.$order->number)['size']);
        $this->assertContains('  ⚠ من غير بصل', $texts);
        $rows = collect($job['lines'])->where('t', 'row');
        $this->assertSame(money(240), $rows->firstWhere('left', '2× برجر لحم')['right']);
        $this->assertTrue($rows->firstWhere('left', 'الإجمالي')['bold']);
        $receiptUrl = $this->as($a, fn () => $order->receiptUrl());
        $this->assertContains($receiptUrl, collect($job['lines'])->where('t', 'qr')->pluck('data'));
        $this->assertSame('feed', collect($job['lines'])->last()['t']);
    }

    public function test_an_unpaid_or_card_order_never_opens_the_drawer(): void
    {
        [$a, , $burger] = $this->kitchenRestaurant();
        $card = $this->sell($a, [['item_id' => $burger->id, 'qty' => 1]], 'card');
        $this->assertFalse($this->jobs($a, $card, ['receipt'])->json('jobs.0.openDrawer'));
    }

    public function test_each_kitchen_station_gets_its_own_ticket_with_only_its_lines_and_no_prices(): void
    {
        [$a, $grill, $burger, $juice, $kunafa, $bar] = $this->kitchenRestaurant();
        $order = $this->sell($a, [['item_id' => $burger->id, 'qty' => 1], ['item_id' => $juice->id, 'qty' => 3], ['item_id' => $kunafa->id, 'qty' => 1]], null);

        $jobs = collect($this->jobs($a, $order, ["kitchen:{$grill->id}", "kitchen:$bar", 'kitchen:main'])->assertOk()->json('jobs'))->keyBy('role');

        $this->assertSame(["kitchen:{$grill->id}", "kitchen:$bar", 'kitchen:main'], $jobs->keys()->all());
        $grillTexts = collect($jobs["kitchen:{$grill->id}"]['lines'])->pluck('text')->filter()->values();
        $this->assertContains('تذكرة المطبخ — الشواية', $grillTexts);
        $this->assertContains('1× برجر لحم', $grillTexts);
        $this->assertNotContains('3× عصير مانجا', $grillTexts);
        $this->assertContains('3× عصير مانجا', collect($jobs["kitchen:$bar"]['lines'])->pluck('text'));
        $this->assertContains('1× كنافة', collect($jobs['kitchen:main']['lines'])->pluck('text'));
        $this->assertSame([], collect($jobs["kitchen:{$grill->id}"]['lines'])->where('t', 'row')->all()); // no prices
        $this->assertFalse($jobs["kitchen:{$grill->id}"]['openDrawer']);
    }

    public function test_an_automatic_copy_prints_once_a_reprint_always_and_a_failed_copy_can_print_again(): void
    {
        [$a, $grill, $burger] = $this->kitchenRestaurant();
        $order = $this->sell($a, [['item_id' => $burger->id, 'qty' => 1]]);

        $this->jobs($a, $order, ['receipt', "kitchen:{$grill->id}"])->assertOk()->assertJsonCount(2, 'jobs');
        $this->jobs($a, $order, ['receipt', "kitchen:{$grill->id}"])->assertOk()
            ->assertJsonCount(0, 'jobs')->assertJsonPath('skipped', ['receipt', "kitchen:{$grill->id}"]);
        $this->assertSame(2, $this->as($a, fn () => OrderPrint::query()->where('order_id', $order->id)->count()));

        $this->jobs($a, $order, ['receipt'], reprint: true)->assertOk()->assertJsonPath('jobs.0.role', 'receipt');

        $this->actingAs($this->owner($a))->postJson($this->dashUrl("/pos/orders/{$order->id}/print-jobs/release"), ['role' => 'receipt'])->assertOk();
        $this->jobs($a, $order, ['receipt'])->assertOk()->assertJsonPath('jobs.0.role', 'receipt');
    }

    public function test_a_role_the_app_does_not_know_is_refused(): void
    {
        [$a, , $burger] = $this->kitchenRestaurant();
        $order = $this->sell($a, [['item_id' => $burger->id, 'qty' => 1]]);

        $this->jobs($a, $order, ['kitchen:grill; drop'])->assertUnprocessable()->assertJsonValidationErrors('roles.0');
    }

    public function test_another_restaurants_order_has_no_print_jobs(): void
    {
        [$a, , $burger] = $this->kitchenRestaurant('cafe-a');
        [$b] = $this->kitchenRestaurant('cafe-b');
        $order = $this->sell($a, [['item_id' => $burger->id, 'qty' => 1]]);

        $this->actingAs($this->owner($b))->postJson($this->dashUrl("/pos/orders/{$order->id}/print-jobs"), ['roles' => ['receipt']])->assertNotFound();
        $this->assertSame(0, OrderPrint::query()->withoutGlobalScopes()->count());
    }

    public function test_the_kitchen_queue_lists_new_orders_after_the_setting_is_on_until_their_tickets_print(): void
    {
        [$a, $grill, $burger, $juice, , $bar] = $this->kitchenRestaurant();
        $owner = $this->owner($a);
        $before = $this->sell($a, [['item_id' => $burger->id, 'qty' => 1]], null);
        $this->actingAs($owner)->getJson($this->dashUrl('/pos/print-queue'))->assertOk()->assertJsonPath('orders', []); // setting off

        $this->travel(1)->minutes();
        $this->actingAs($owner)->post($this->dashUrl('/pos/printing'), [
            'pos_print_kitchen_auto' => '1', 'pos_print_copies' => 1, 'pos_print_kitchen_copies' => 2,
        ])->assertSessionHasNoErrors();
        $this->travel(1)->minutes();
        $order = $this->sell($a, [['item_id' => $burger->id, 'qty' => 1], ['item_id' => $juice->id, 'qty' => 1]], null);

        $queue = $this->actingAs($owner)->getJson($this->dashUrl('/pos/print-queue'))->assertOk()->json('orders');
        $this->assertSame([$order->id], array_column($queue, 'id')); // the order from before the switch never prints
        $this->assertNotContains($before->id, array_column($queue, 'id'));
        $this->assertEqualsCanonicalizing(["kitchen:{$grill->id}", "kitchen:$bar"], $queue[0]['roles']);

        $this->assertSame(2, $this->jobs($a, $order, ["kitchen:{$grill->id}"])->json('jobs.0.copies'));
        $this->assertSame(["kitchen:$bar"], $this->actingAs($owner)->getJson($this->dashUrl('/pos/print-queue'))->json('orders.0.roles'));
        $this->jobs($a, $order, ["kitchen:$bar"]);
        $this->actingAs($owner)->getJson($this->dashUrl('/pos/print-queue'))->assertJsonPath('orders', []);
    }

    public function test_only_staff_who_manage_terminals_change_the_printing_settings(): void
    {
        [$a] = $this->kitchenRestaurant();
        $cashier = $this->staff($a, 'cashier', 'c@cafe-a.test');

        $this->actingAs($cashier)->post($this->dashUrl('/pos/printing'), ['pos_print_receipt_auto' => '1', 'pos_print_copies' => 1, 'pos_print_kitchen_copies' => 1])
            ->assertForbidden();
        $this->actingAs($this->owner($a))->post($this->dashUrl('/pos/printing'), ['pos_print_receipt_auto' => '1', 'pos_print_copies' => 9, 'pos_print_kitchen_copies' => 1])
            ->assertSessionHasErrors('pos_print_copies');
        $this->actingAs($this->owner($a))->post($this->dashUrl('/pos/printing'), ['pos_print_receipt_auto' => '1', 'pos_print_copies' => 3, 'pos_print_kitchen_copies' => 1])
            ->assertSessionHasNoErrors();
        $this->assertSame(['1', '3'], $this->as($a, fn () => [Settings::get('pos_print_receipt_auto'), Settings::get('pos_print_copies')]));
        $this->actingAs($this->owner($a))->get($this->dashUrl('/pos'))->assertOk()->assertSee('data-printers-open', false);
    }

    public function test_the_z_report_comes_as_a_print_job_for_the_app(): void
    {
        [$a, , $burger] = $this->kitchenRestaurant();
        $this->sell($a, [['item_id' => $burger->id, 'qty' => 1]]);

        $job = $this->actingAs($this->owner($a))->getJson($this->dashUrl('/pos/z-report?format=job'))->assertOk()->json('job');

        $this->assertSame('receipt', $job['role']);
        $this->assertContains('تقفيل اليوم (Z)', collect($job['lines'])->pluck('text'));
        $this->assertSame(money(120), collect($job['lines'])->firstWhere('left', 'صافي المبيعات')['right'] ?? null);
    }
}
