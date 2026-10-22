<?php

namespace Tests\Feature;

use App\Billing\Billing;
use App\Models\Branch;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\PosTerminal;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Orders\ShiftService;
use App\Services\Orders\Terminals;
use App\Services\Reports\SalesReport;
use App\Services\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TenantTestCase;

/**
 * POS terminals and offline sales: terminals per branch (managed by owner / manager, claimed by a device, one open
 * shift each), the sync of sales made without internet (once per client_uuid, the real number, the offline
 * reference and the time of the sale in the shift and the reports, the price as sold with a review flag), refusals
 * (shift closed, over the offline limit, another terminal / tenant) and the offline pieces of the POS page.
 */
class PosOfflineTest extends TenantTestCase
{
    private Tenant $tenant;

    private MenuItem $latte;

    private User $cashier;

    private User $manager;

    private function shop(string $slug = 'cafe-a'): void
    {
        // mid-afternoon: shifts open and offline sales happen a few hours earlier and must stay on "today" when the suite runs around midnight
        $this->travelTo(now()->setTime(15, 0));
        $this->tenant = $this->restaurant($slug, 'basic');
        app(Billing::class)->setAddon($this->tenant, 'pos_pack', 1);
        $this->latte = $this->item($this->tenant, 'Latte', 60);
        $this->cashier = $this->staff($this->tenant, 'cashier', "cashier@$slug.test");
        $this->manager = $this->staff($this->tenant, 'manager', "manager@$slug.test");
    }

    private function branch(?Tenant $tenant = null): Branch
    {
        return $this->as($tenant ?? $this->tenant, fn () => Branch::query()->firstOrFail());
    }

    private function terminal(string $name = 'Cashier 1', ?Tenant $tenant = null, ?User $by = null): PosTerminal
    {
        $tenant ??= $this->tenant;
        $this->actingAs($by ?? $this->owner($tenant))->post($this->dashUrl('/pos/terminals'), ['branch_id' => $this->branch($tenant)->id, 'name' => $name])
            ->assertSessionHasNoErrors();

        return $this->as($tenant, fn () => PosTerminal::query()->where('name', $name)->firstOrFail());
    }

    /** @return array{id:int, token:string, code:string} */
    private function claim(PosTerminal $terminal, User $by, bool $takeover = false): array
    {
        return $this->actingAs($by)->postJson($this->dashUrl("/pos/terminals/{$terminal->id}/claim"), ['takeover' => $takeover, 'device' => 'Chrome'])
            ->assertOk()->json();
    }

    /** @param array{id:int, token:string} $device */
    private function headers(array $device): array
    {
        return ['X-POS-Terminal' => (string) $device['id'], 'X-POS-Terminal-Token' => $device['token']];
    }

    /** Opens $by's shift ($hoursAgo hours ago: the offline sales below happened during it). */
    private function openShift(User $by, ?array $device = null, int $hoursAgo = 2): TestResponse
    {
        $now = now();
        $this->travelTo($now->copy()->subHours($hoursAgo));
        try {
            return $this->actingAs($by)->post($this->dashUrl('/shifts/open'), ['opening_cash' => 100]
                + ($device ? ['terminal_id' => $device['id'], 'terminal_token' => $device['token']] : []));
        } finally {
            $this->travelTo($now);
        }
    }

    private function shift(User $by): Shift
    {
        return $this->as($this->tenant, fn () => Shift::query()->where('user_id', $by->id)->latest('id')->firstOrFail());
    }

    /** A sale as the device keeps it offline: $qty lattes at the price the device knew. */
    private function sale(Shift $shift, array $device, int $n, array $overrides = []): array
    {
        $unit = $overrides['unit_price'] ?? 60;
        $qty = $overrides['qty'] ?? 2;
        $at = $overrides['at'] ?? now()->subMinutes(30)->toIso8601String();
        unset($overrides['unit_price'], $overrides['qty'], $overrides['at']);

        return $overrides + [
            'client_uuid' => (string) Str::uuid(), 'offline_ref' => 'OFF-'.$device['code'].'-'.$n, 'created_offline_at' => $at,
            'offline_since' => now()->subHour()->toIso8601String(), 'shift_id' => $shift->id, 'type' => 'takeaway',
            'items' => [['item_id' => $this->latte->id, 'qty' => $qty, 'name' => 'Latte', 'unit_price' => $unit, 'options' => []]],
            'discount' => 0, 'subtotal' => $unit * $qty, 'service' => 0, 'tax' => 0, 'total' => $unit * $qty, 'method' => 'cash', 'tendered' => 200,
        ];
    }

    private function sync(array $sale, array $device, ?User $by = null): TestResponse
    {
        $response = $this->actingAs($by ?? $this->cashier)->withHeaders($this->headers($device))->postJson($this->dashUrl('/pos/sync'), $sale);
        $this->flushHeaders();

        return $response;
    }

    public function test_the_owner_or_a_manager_names_terminals_and_a_cashier_cannot(): void
    {
        $this->shop();
        $k1 = $this->terminal('Cashier 1');
        $k2 = $this->terminal('Cashier 2', by: $this->manager);
        $this->assertSame(['K1', 'K2'], [$k1->code, $k2->code]);

        $this->actingAs($this->cashier)->get($this->dashUrl('/pos/terminals'))->assertForbidden();
        $this->actingAs($this->cashier)->post($this->dashUrl('/pos/terminals'), ['branch_id' => $this->branch()->id, 'name' => 'X'])->assertForbidden();
        // a code is unique in the restaurant
        $this->actingAs($this->manager)->post($this->dashUrl('/pos/terminals'), ['branch_id' => $this->branch()->id, 'name' => 'Bar', 'code' => 'k1'])
            ->assertSessionHasErrors('code');

        $this->actingAs($this->manager)->get($this->dashUrl('/pos/terminals'))->assertOk()
            ->assertSee('data-terminal="K1"', false)->assertSee('Cashier 2')->assertSee('name="pos_offline_hours"', false);
        $this->actingAs($this->manager)->post($this->dashUrl('/pos/terminals/settings'), ['pos_offline_hours' => 6])->assertSessionHasNoErrors();
        $this->assertSame('6', $this->as($this->tenant, fn () => Settings::get('pos_offline_hours')));
    }

    public function test_a_device_claims_a_terminal_and_a_terminal_has_one_open_shift(): void
    {
        $this->shop();
        $k1 = $this->terminal();
        $device = $this->claim($k1, $this->cashier);
        $this->assertSame('K1', $device['code']);
        $this->assertSame(48, strlen($device['token']));
        $stored = $this->as($this->tenant, fn () => PosTerminal::query()->find($k1->id));
        $this->assertNotSame($device['token'], $stored->device_token); // only the hash is kept
        $this->assertTrue($stored->holds($device['token']));

        // another device asks for it: told it is taken, then takes it over (the first token stops working)
        $this->actingAs($this->manager)->postJson($this->dashUrl("/pos/terminals/{$k1->id}/claim"), [])->assertStatus(422)->assertJsonPath('claimed', true);

        $this->openShift($this->cashier, $device)->assertSessionHasNoErrors();
        $shift = $this->shift($this->cashier);
        $this->assertSame($k1->id, $shift->terminal_id);

        // one open shift per terminal: a second cashier on the same terminal is refused
        $second = $this->staff($this->tenant, 'cashier', 'second@cafe-a.test');
        $this->openShift($second, $device)->assertSessionHasErrors('shift');
        $this->assertSame(1, $this->as($this->tenant, fn () => Shift::query()->whereNull('closed_at')->count()));
        // the cashier who is not the shift's cannot take the terminal from its device while that shift is open
        $this->actingAs($second)->postJson($this->dashUrl("/pos/terminals/{$k1->id}/claim"), ['takeover' => true])->assertStatus(422);

        // a POS sale made on this device carries the terminal; the shifts page shows it
        $this->actingAs($this->cashier)->withHeaders($this->headers($device))->postJson($this->dashUrl('/pos/orders'), [
            'client_uuid' => (string) Str::uuid(), 'type' => 'takeaway', 'items' => [['item_id' => $this->latte->id, 'qty' => 1]], 'pay' => 'cash',
        ])->assertOk();
        $this->flushHeaders();
        $this->assertSame($k1->id, $this->as($this->tenant, fn () => Order::query()->latest('id')->value('terminal_id')));
        $this->actingAs($this->cashier)->get($this->dashUrl('/shifts'))->assertOk()->assertSee('data-shift-terminal', false)->assertSee('Cashier 1 · K1');

        // closed: the manager lists the shifts of that terminal
        $this->actingAs($this->cashier)->post($this->dashUrl('/shifts/close'), ['closing_cash' => 160]);
        $this->openShift($this->manager)->assertSessionHasNoErrors(); // without a terminal: an ordinary shift
        $this->actingAs($this->manager)->get($this->dashUrl('/shifts?terminal='.$k1->id))->assertOk()
            ->assertSee('data-shifts-by-terminal', false)->assertSee('Cashier 1 · K1');

        // the manager frees the terminal: the old token no longer identifies the device
        $this->actingAs($this->manager)->post($this->dashUrl("/pos/terminals/{$k1->id}/release"))->assertSessionHasNoErrors();
        $this->assertFalse($this->as($this->tenant, fn () => PosTerminal::query()->find($k1->id))->holds($device['token']));
    }

    public function test_terminals_stay_inside_their_restaurant(): void
    {
        $this->shop();
        $k1 = $this->terminal();
        $device = $this->claim($k1, $this->cashier);
        $b = $this->restaurant('cafe-b', 'basic');
        app(Billing::class)->setAddon($b, 'pos_pack', 1);
        $other = $this->staff($b, 'cashier', 'cashier@cafe-b.test');

        $this->actingAs($other)->postJson($this->dashUrl("/pos/terminals/{$k1->id}/claim"), ['takeover' => true])->assertNotFound();
        $this->actingAs($this->owner($b))->post($this->dashUrl("/pos/terminals/{$k1->id}"), ['name' => 'Hacked'])->assertNotFound();
        // the stolen id and token are simply ignored: the shift opens without a terminal
        $this->openShift($other, $device)->assertSessionHasNoErrors();
        $this->assertNull($this->as($b, fn () => Shift::query()->firstOrFail()->terminal_id));
        $this->actingAs($this->owner($b))->get($this->dashUrl('/pos/terminals'))->assertOk()->assertDontSee('data-terminal="K1"', false);
    }

    public function test_a_manager_tied_to_a_branch_only_touches_the_terminals_of_that_branch(): void
    {
        $this->shop();
        $k1 = $this->terminal();
        $device = $this->claim($k1, $this->cashier);
        $second = $this->as($this->tenant, fn () => Branch::query()->create(['name' => 'Second', 'slug' => 'second']));
        $this->as($this->tenant, fn () => $this->manager->update(['branch_id' => $second->id]));

        $this->actingAs($this->manager)->post($this->dashUrl("/pos/terminals/{$k1->id}/release"))->assertNotFound();
        $this->actingAs($this->manager)->post($this->dashUrl("/pos/terminals/{$k1->id}"), ['name' => 'Renamed'])->assertNotFound();
        $fresh = $this->as($this->tenant, fn () => PosTerminal::query()->findOrFail($k1->id));
        $this->assertSame('Cashier 1', $fresh->name);
        $this->assertTrue($fresh->holds($device['token']));

        // their own branch's terminal is theirs to manage
        $own = $this->as($this->tenant, fn () => app(Terminals::class)->create($second, 'Bar', 'B1'));
        $this->actingAs($this->manager)->post($this->dashUrl("/pos/terminals/{$own->id}/release"))->assertSessionHasNoErrors()->assertRedirect();
    }

    public function test_an_offline_sale_syncs_once_with_its_real_number_reference_and_time(): void
    {
        $this->shop();
        $device = $this->claim($this->terminal(), $this->cashier);
        $this->openShift($this->cashier, $device)->assertSessionHasNoErrors();
        $shift = $this->shift($this->cashier);
        // an order placed online meanwhile took number 1
        $this->actingAs($this->cashier)->postJson($this->dashUrl('/pos/orders'), [
            'client_uuid' => (string) Str::uuid(), 'type' => 'takeaway', 'items' => [['item_id' => $this->latte->id, 'qty' => 1]], 'pay' => 'card',
        ])->assertOk()->assertJsonPath('number', 1);

        $soldAt = now()->subMinutes(40)->startOfMinute();
        $sale = $this->sale($shift, $device, 3, ['at' => $soldAt->toIso8601String()]);
        $this->sync($sale, $device)->assertOk()->assertJsonPath('number', 2)->assertJsonPath('offline_ref', 'OFF-K1-3')
            ->assertJsonPath('duplicate', false)->assertJsonPath('review', false);
        // sent again (the answer was lost): the same order, nothing doubled
        $this->sync($sale, $device)->assertOk()->assertJsonPath('number', 2)->assertJsonPath('duplicate', true);

        $order = $this->as($this->tenant, fn () => Order::query()->where('offline_ref', 'OFF-K1-3')->firstOrFail());
        $this->assertSame(1, $this->as($this->tenant, fn () => Order::query()->where('client_uuid', $sale['client_uuid'])->count()));
        $this->assertSame(['paid', 'cash', 'served', 'pos'], [$order->payment_status, $order->payment_method, $order->status, $order->source]);
        $this->assertSame($soldAt->toIso8601String(), $order->created_offline_at->toIso8601String());
        $this->assertSame($soldAt->toIso8601String(), $order->created_at->toIso8601String()); // counted when it was sold
        $this->assertNotNull($order->synced_at);
        $this->assertSame($shift->id, $order->shift_id);
        $this->assertSame('#2 (كان OFF-K1-3)', $this->as($this->tenant, fn () => $order->reference()));
        $this->assertSame(3, $this->as($this->tenant, fn () => PosTerminal::query()->firstOrFail()->offline_seq));

        // the shift and the day's report count it
        $summary = $this->as($this->tenant, fn () => app(ShiftService::class)->summary($shift->fresh()));
        $this->assertSame(120.0, $summary['by_method']['cash']);
        $this->assertSame(60.0, $summary['by_method']['card']);
        $this->assertSame(220.0, $summary['expected_cash']);
        $report = $this->as($this->tenant, fn () => (new SalesReport(CarbonImmutable::today(), CarbonImmutable::today()))->build());
        $this->assertSame(2, $report['orders']);
        $this->assertSame(180.0, $report['revenue']);

        // receipts and the order board show both references
        $this->actingAs($this->cashier)->get($this->dashUrl("/orders/{$order->id}/receipt"))->assertOk()->assertSee('data-receipt-offline', false)->assertSee('كان OFF-K1-3');
        $this->actingAs($this->owner($this->tenant))->getJson($this->dashUrl('/orders/feed'))->assertOk()
            ->assertJsonFragment(['number' => 2, 'offline_ref' => 'OFF-K1-3']);
    }

    public function test_a_sale_made_yesterday_offline_lands_in_yesterdays_report(): void
    {
        $this->shop();
        $device = $this->claim($this->terminal(), $this->cashier);
        $this->travelTo(now()->subDay()->setTime(22, 0));
        $this->openShift($this->cashier, $device, 0)->assertSessionHasNoErrors();
        $shift = $this->shift($this->cashier);
        $soldAt = now()->addMinutes(30);
        $this->travelTo(now()->addDay()->setTime(15, 0)); // the next afternoon, not the real clock
        $this->sync($this->sale($shift, $device, 1, ['at' => $soldAt->toIso8601String(), 'offline_since' => $soldAt->copy()->subMinutes(10)->toIso8601String()]), $device)->assertOk();

        $day = CarbonImmutable::parse($soldAt)->startOfDay();
        $this->assertSame(120.0, $this->as($this->tenant, fn () => (new SalesReport($day, $day))->build())['revenue']);
        $this->assertSame(0.0, $this->as($this->tenant, fn () => (new SalesReport(CarbonImmutable::today(), CarbonImmutable::today()))->build())['revenue']);
    }

    public function test_the_price_as_sold_is_kept_and_a_changed_menu_flags_the_order_for_review(): void
    {
        $this->shop();
        $device = $this->claim($this->terminal(), $this->cashier);
        $this->openShift($this->cashier, $device);
        $shift = $this->shift($this->cashier);
        $muffin = $this->item($this->tenant, 'Muffin', 30);
        // while the device was offline the latte went up to 70 and the muffin was deleted
        $this->as($this->tenant, function () use ($muffin) {
            MenuItem::query()->whereKey($this->latte->id)->update(['price' => 70]);
            MenuItem::query()->whereKey($muffin->id)->delete();
        });
        $sale = $this->sale($shift, $device, 1);
        $sale['items'][] = ['item_id' => $muffin->id, 'qty' => 1, 'name' => 'Muffin', 'unit_price' => 30, 'options' => []];
        $sale['subtotal'] = $sale['total'] = 150;
        $this->sync($sale, $device)->assertOk()->assertJsonPath('review', true);

        $order = $this->as($this->tenant, fn () => Order::query()->with('items')->where('offline_ref', 'OFF-K1-1')->firstOrFail());
        $this->assertSame(150.0, $order->total); // what the guest paid
        $this->assertSame([60.0, 30.0], $order->items->pluck('unit_price')->map(fn ($p) => (float) $p)->all());
        $this->assertNull($order->items[1]->menu_item_id);
        $this->assertTrue($order->needs_review);
        $this->assertCount(2, $order->review_notes);
        $this->assertStringContainsString('Latte', $order->review_notes[0]);
        $this->assertStringContainsString('Muffin', $order->review_notes[1]);
    }

    public function test_refused_sales_shift_closed_over_the_limit_wrong_terminal_or_amounts(): void
    {
        $this->shop();
        $device = $this->claim($this->terminal(), $this->cashier);
        $this->openShift($this->cashier, $device);
        $shift = $this->shift($this->cashier);

        // amounts that do not add up, a method that needs the internet, a reference of another terminal
        $this->sync(['total' => 100] + $this->sale($shift, $device, 1), $device)->assertStatus(422)->assertJsonPath('refused', true);
        $this->sync(['method' => 'credit'] + $this->sale($shift, $device, 1), $device)->assertStatus(422);
        $this->sync(['offline_ref' => 'OFF-K9-1'] + $this->sale($shift, $device, 1), $device)->assertStatus(422);
        // sold after the offline limit (12 hours by default)
        $this->sync($this->sale($shift, $device, 2, ['offline_since' => now()->subHours(14)->toIso8601String(), 'at' => now()->subMinute()->toIso8601String()]), $device)
            ->assertStatus(422)->assertJsonFragment(['refused' => true]);
        // without the terminal's token
        $this->actingAs($this->cashier)->postJson($this->dashUrl('/pos/sync'), $this->sale($shift, $device, 3))->assertStatus(422);
        $this->assertSame(0, $this->as($this->tenant, fn () => Order::query()->count()));

        // the shift was closed before the sale synced
        $late = $this->sale($shift, $device, 4);
        $this->actingAs($this->cashier)->post($this->dashUrl('/shifts/close'), ['closing_cash' => 100]);
        $this->sync($late, $device)->assertStatus(422)->assertJsonPath('refused', true)
            ->assertJsonPath('message', 'وردية البيعة دي اتقفلت قبل ما تتبعت: سوّيها بإيدك وعلّم إنها اتحلّت.');
        $this->assertSame(0, $this->as($this->tenant, fn () => Order::query()->count()));
    }

    public function test_an_offline_sale_paid_by_two_methods_keeps_both_parts(): void
    {
        $this->shop();
        $device = $this->claim($this->terminal(), $this->cashier);
        $this->openShift($this->cashier, $device);
        $shift = $this->shift($this->cashier);
        $sale = $this->sale($shift, $device, 1, ['method' => 'split', 'payments' => [['method' => 'cash', 'amount' => 20, 'tendered' => 50], ['method' => 'instapay', 'amount' => 100]]]);
        $this->sync($sale, $device)->assertOk();
        $summary = $this->as($this->tenant, fn () => app(ShiftService::class)->summary($shift->fresh()));
        $this->assertSame([20.0, 100.0, 120.0], [$summary['by_method']['cash'], $summary['by_method']['instapay'], $summary['expected_cash']]);
        $this->assertSame('split', $this->as($this->tenant, fn () => Order::query()->value('payment_method')));
        // parts that do not cover the sale are refused
        $this->sync($this->sale($shift, $device, 2, ['payments' => [['method' => 'cash', 'amount' => 20]]]), $device)->assertStatus(422);
    }

    public function test_another_restaurants_shift_or_terminal_cannot_take_a_sale(): void
    {
        $this->shop();
        $device = $this->claim($this->terminal(), $this->cashier);
        $this->openShift($this->cashier, $device);
        $shift = $this->shift($this->cashier);
        $b = $this->restaurant('cafe-b', 'basic');
        app(Billing::class)->setAddon($b, 'pos_pack', 1);
        $other = $this->staff($b, 'cashier', 'cashier@cafe-b.test');

        $this->sync($this->sale($shift, $device, 1), $device, $other)->assertStatus(422);
        $this->assertSame(0, $this->as($b, fn () => Order::query()->count()));
        $this->assertSame(0, $this->as($this->tenant, fn () => Order::query()->count()));
    }

    public function test_the_pos_page_has_the_offline_pieces_and_a_catalog_for_the_device(): void
    {
        $this->shop();
        $this->terminal();
        $this->openShift($this->cashier);
        $this->actingAs($this->cashier)->get($this->dashUrl('/pos'))->assertOk()
            ->assertSee('data-net', false)->assertSee('data-sync-open', false)->assertSee('data-terminal-open', false)
            ->assertSee('data-sync-dialog', false)->assertSee('data-help-dialog', false)->assertSee('data-pay-offline', false)
            ->assertSee('"offlineHours":12', false)->assertSee('"offlineMethods":["cash","card","instapay","wallet"]', false);

        $catalog = $this->actingAs($this->cashier)->getJson($this->dashUrl('/pos/catalog'))->assertOk();
        $this->assertSame('Latte', $catalog->json('items.0.name'));
        $this->assertSame('K1', $catalog->json('terminals.0.code'));
        $this->assertNotEmpty($catalog->json('csrf'));
        $this->assertNotNull($catalog->json('shift.id'));
        $this->actingAs($this->cashier)->getJson($this->dashUrl('/pos/ping'))->assertOk()->assertJsonPath('ok', true);

        // the service worker opens this page when the internet is down
        $this->get($this->dashUrl('/sw.js'))->assertOk()->assertSee("url.pathname === '/pos'", false);
    }
}
