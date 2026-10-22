<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Order;
use App\Models\Reservation;
use App\Models\Tenant;
use App\Support\DashboardApps;
use Tests\TenantTestCase;

/** The apps screen on the dashboard home: one tile per area the user may open, locked picks, counters, search. */
class HomeAppsTest extends TenantTestCase
{
    /** @return list<string> the routes of the tiles on the home page, in order */
    private function tiles(string $html): array
    {
        preg_match_all('/data-app="([a-z.]+)"/', $html, $m);

        return $m[1];
    }

    private function order(Tenant $tenant, string $status, array $attrs = []): Order
    {
        return $this->as($tenant, fn () => Order::query()->create([
            'branch_id' => Branch::query()->value('id'), 'number' => (int) (Order::query()->max('number') ?? 0) + 1, 'status' => $status, 'total' => 50,
        ] + $attrs));
    }

    public function test_the_grid_shows_only_the_apps_the_user_may_open_and_the_sidebar_reads_the_same_list(): void
    {
        $a = $this->restaurant('cafe-a', 'pro');
        $owner = $this->owner($a);

        $html = $this->actingAs($owner)->get($this->dashUrl('/'))->assertOk()
            ->assertSee('data-home-apps', false)->assertSee('data-apps-search', false)->assertSee('data-apps-empty', false)
            ->assertSee('data-header-apps', false)->assertSee(__('Apps'))
            ->assertSee('data-apps-group="orders"', false)->assertSee('data-apps-group="management"', false)
            ->assertDontSee('data-app="dash.pos"', false)   // the POS is not in the Pro plan
            ->assertDontSee('data-app="dash.home"', false)  // the screen itself is no tile
            ->getContent();
        $tiles = $this->tiles($html);
        $this->assertContains('dash.orders', $tiles);
        $this->assertContains('dash.menu', $tiles);
        $this->assertContains('dash.users', $tiles);
        $this->assertContains('dash.billing', $tiles);
        $this->assertContains('dash.settings', $tiles);
        $this->assertSame($tiles, array_unique($tiles), 'one tile per app');

        // the grid and the sidebar come from the same list
        $expected = $this->as($a, function () use ($owner) {
            $this->actingAs($owner);
            $apps = DashboardApps::forUser($owner);

            return array_values(array_filter(array_merge(...array_map(fn (array $g) => array_column($g['apps'], 'route'), $apps['groups'])), fn ($r) => $r !== 'dash.home'));
        });
        $this->assertSame($expected, $tiles);

        // a waiter: orders yes, the management apps no
        $waiter = $this->staff($a, 'waiter', 'w@cafe-a.test');
        $tiles = $this->tiles($this->actingAs($waiter)->get($this->dashUrl('/'))->assertOk()->getContent());
        $this->assertContains('dash.orders', $tiles);
        $this->assertNotContains('dash.settings', $tiles);
        $this->assertNotContains('dash.users', $tiles);
        $this->assertNotContains('dash.billing', $tiles);
    }

    public function test_a_picked_module_outside_the_plan_is_a_locked_tile_only_for_whoever_manages_billing(): void
    {
        $a = $this->restaurant('cafe-a', 'pro');   // Pro: table ordering and the menu import yes, the POS no
        $owner = $this->owner($a);
        $manager = $this->staff($a, 'manager', 'm@cafe-a.test');

        $this->actingAs($manager)->post($this->dashUrl('/modules'), ['modules' => ['pos', 'table-ordering', 'menu-import']])->assertRedirect(route('dash.modules'));

        $this->actingAs($owner)->get($this->dashUrl('/'))->assertOk()
            ->assertSee('data-home-modules', false)
            ->assertSee('href="'.route('dash.billing').'" data-home-module="pos"', false)
            ->assertSee('data-app-locked', false)
            ->assertSee('href="'.route('dash.orders').'" data-home-module="table-ordering"', false)
            // a picked module whose page is no app of its own gets a tile in its group
            ->assertSee('href="'.route('dash.import').'" data-home-module="menu-import"', false);

        // the manager picks the modules but does not pay: no locked tile, no billing link
        $html = $this->actingAs($manager)->get($this->dashUrl('/'))->assertOk()
            ->assertDontSee('data-home-module="pos"', false)->assertDontSee('data-app-locked', false)
            ->assertSee('data-home-module="table-ordering"', false)->getContent();
        $this->assertStringNotContainsString(route('dash.billing'), $html);
    }

    public function test_the_counters_show_what_is_waiting_in_this_restaurant_only(): void
    {
        $a = $this->restaurant('cafe-a', 'pro');
        $b = $this->restaurant('cafe-b', 'pro');

        $this->order($a, 'new');
        $this->order($a, 'preparing');
        $this->order($a, 'served');
        $this->order($a, 'cancelled');
        $this->order($b, 'new');
        $this->as($a, fn () => Reservation::query()->create([
            'branch_id' => Branch::query()->value('id'), 'name' => 'Omar', 'phone' => '01055556666', 'party_size' => 4,
            'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHours(2), 'status' => 'pending', 'source' => 'guest', 'token' => random_token(12),
        ]));

        $this->actingAs($this->owner($a))->get($this->dashUrl('/'))->assertOk()
            ->assertSee('data-app-badge="dash.orders">2<', false)
            ->assertSee('data-app-badge="dash.reservations">1<', false);

        // the other restaurant counts its own orders, and a zero is no badge at all
        $this->actingAs($this->owner($b))->get($this->dashUrl('/'))->assertOk()
            ->assertSee('data-app-badge="dash.orders">1<', false)
            ->assertDontSee('data-app-badge="dash.reservations"', false);
    }

    public function test_apps_outside_the_pick_wait_under_more_apps_and_still_open(): void
    {
        $a = $this->restaurant('cafe-a', 'pro', true);
        $owner = $this->owner($a);

        $this->actingAs($owner)->get($this->dashUrl('/'))->assertOk()->assertDontSee('data-apps-more', false)->assertSee('data-modules-invite', false);

        $this->actingAs($owner)->post($this->dashUrl('/modules'), ['modules' => ['table-ordering']]);
        $html = $this->actingAs($owner)->get($this->dashUrl('/'))->assertOk()
            ->assertSee('data-apps-more', false)->assertDontSee('data-modules-invite', false)->getContent();
        // reservations are not picked: their tile is inside the folded "more apps", after the groups
        $this->assertMatchesRegularExpression('/data-apps-more.*data-app="dash\.reservations"/s', $html);
        $this->assertDoesNotMatchRegularExpression('/data-app="dash\.reservations".*data-apps-more/s', $html);
        $this->actingAs($owner)->get($this->dashUrl('/reservations'))->assertOk();
    }
}
