<?php

namespace Tests\Feature;

use App\Models\MenuFunnelCount;
use App\Models\MenuItem;
use App\Models\Tenant;
use App\Services\Orders\BusyMode;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/** The guest's way to an order on the menu (advanced reports): steps reached, what stopped guests, counters only. */
class MenuFunnelTest extends TenantTestCase
{
    private function onlineOrder(Tenant $tenant)
    {
        $item = $this->as($tenant, fn () => MenuItem::query()->firstOrFail());

        return $this->postJson($this->subUrl($tenant->id, 'order'), [
            'client_uuid' => (string) Str::uuid(), 'items' => [['item_id' => $item->id, 'qty' => 1]],
            'type' => 'takeaway', 'customer_name' => 'Mona', 'customer_phone' => '01012345678',
        ]);
    }

    /** @return array<string, int> "event|detail" => count of the restaurant's rows */
    private function counts(Tenant $tenant): array
    {
        return $this->as($tenant, fn () => MenuFunnelCount::query()->get()
            ->mapWithKeys(fn ($r) => [$r->event.($r->detail !== '' ? '|'.$r->detail : '') => $r->count])->sort()->all());
    }

    public function test_the_page_counts_known_steps_and_stops_only_for_its_restaurant(): void
    {
        $a = $this->restaurant('cafe-a');
        $this->restaurant('cafe-b');

        $this->post($this->pathUrl('cafe-a', 'f/visit'))->assertNoContent();
        $this->post($this->subUrl('cafe-a', 'f/visit'))->assertNoContent();
        $this->post($this->pathUrl('cafe-a', 'f/add'))->assertNoContent();
        $this->post($this->pathUrl('cafe-a', 'f/stop?why=phone'))->assertNoContent();
        // not counted: an unknown step, an unknown stop, and "placed" (only a real order counts it)
        $this->post($this->pathUrl('cafe-a', 'f/hello'))->assertNoContent();
        $this->post($this->pathUrl('cafe-a', 'f/stop?why=anything'))->assertNoContent();
        $this->post($this->pathUrl('cafe-a', 'f/placed'))->assertNoContent();

        $this->assertSame(['add' => 1, 'stop|phone' => 1, 'visit' => 2], $this->counts($a));
        $this->assertSame(3, MenuFunnelCount::query()->withoutGlobalScopes()->where('tenant_id', 'cafe-a')->count());
        $this->assertSame(0, MenuFunnelCount::query()->withoutGlobalScopes()->where('tenant_id', 'cafe-b')->count());

        // the menu page tells the script where to send them
        $this->get($this->pathUrl('cafe-a'))->assertOk()->assertSee('f\/xxx', false);
    }

    public function test_a_placed_order_and_a_refused_one_are_counted_by_the_server(): void
    {
        $a = $this->restaurant('cafe-a', 'pro');
        $this->item($a, 'Latte', 60);

        $this->onlineOrder($a)->assertOk();
        $this->as($a, fn () => BusyMode::start(now()->addMinutes(30), 'الكاشير زحمة، نص ساعة ونرجع'));
        $this->onlineOrder($a)->assertStatus(422);
        $this->onlineOrder($a)->assertStatus(422);
        $this->postJson($this->subUrl('cafe-a', 'order'), ['client_uuid' => (string) Str::uuid(), 'items' => []])->assertStatus(422);

        $counts = $this->counts($a);
        $this->assertSame(1, $counts['placed']);
        $this->assertSame(2, $counts['refused|الكاشير زحمة، نص ساعة ونرجع']);
        $this->assertSame(1, collect($counts)->filter(fn ($n, $k) => str_starts_with($k, 'refused|') && ! str_contains($k, 'زحمة'))->count());
    }

    public function test_the_report_shows_where_visits_stop_and_why(): void
    {
        $a = $this->restaurant('cafe-a', 'business');
        $this->as($a, function () {
            $today = local_today();
            foreach (['visit' => 100, 'item' => 60, 'add' => 30, 'cart' => 25, 'send' => 12, 'placed' => 9] as $event => $n) {
                MenuFunnelCount::query()->create(['day' => $today, 'event' => $event, 'count' => $n]);
            }
            MenuFunnelCount::query()->create(['day' => $today, 'event' => 'stop', 'detail' => 'zone', 'count' => 2]);
            MenuFunnelCount::query()->create(['day' => $today, 'event' => 'refused', 'detail' => 'المطعم مقفول دلوقتي', 'count' => 5]);
            MenuFunnelCount::query()->create(['day' => local_at()->subDays(40)->toDateString(), 'event' => 'visit', 'count' => 999]); // outside
        });

        $res = $this->actingAs($this->owner($a))->get($this->dashUrl('/reports?range=7d'))->assertOk()->assertSee('data-funnel', false);
        $funnel = $res->viewData('extra')['funnel'];
        $this->assertSame(
            [['visit', 100, 100.0, 0], ['item', 60, 60.0, 40], ['add', 30, 30.0, 30], ['cart', 25, 25.0, 5], ['send', 12, 12.0, 13], ['placed', 9, 9.0, 3]],
            array_map(fn ($s) => [$s['key'], $s['count'], $s['share'], $s['lost']], $funnel['steps']),
        );
        $this->assertSame([['المطعم مقفول دلوقتي', 5, true], [__('Did not choose the delivery area'), 2, false]],
            array_map(fn ($s) => [$s['label'], $s['count'], $s['refused']], $funnel['stops']));

        $this->actingAs($this->owner($a))->get($this->dashUrl('/reports/export/funnel?range=7d'))->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
    }
}
