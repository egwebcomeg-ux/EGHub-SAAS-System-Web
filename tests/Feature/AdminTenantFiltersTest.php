<?php

namespace Tests\Feature;

use App\Models\Domain;
use App\Models\PlatformAdmin;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Settings;
use Illuminate\Support\Facades\DB;
use Tests\TenantTestCase;

/** The admin restaurants list: search, filters, business type chips, and a bounded number of queries. */
class AdminTenantFiltersTest extends TenantTestCase
{
    private PlatformAdmin $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = PlatformAdmin::query()->create(['name' => 'Support', 'email' => 'support@egweb.test', 'password' => 'Secret-pass-123', 'role' => 'support']);
    }

    private function place(string $slug, string $plan = 'pro', bool $trial = false, ?string $type = null): Tenant
    {
        $tenant = $this->restaurant($slug, $plan, $trial);
        if ($type !== null) {
            $this->as($tenant, fn () => Settings::put(['business_type' => $type]));
        }

        return $tenant;
    }

    /** @return list<string> the slugs listed for these filters */
    private function listed(array $query = []): array
    {
        $response = $this->actingAs($this->admin, 'admin')->get($this->adminUrl('/tenants?'.http_build_query($query)))->assertOk();

        return collect($response->viewData('tenants')->items())->pluck('id')->sort()->values()->all();
    }

    public function test_filters_by_business_type_with_counts_per_type(): void
    {
        $this->place('cafe-a', type: 'cafe');
        $this->place('cafe-b');
        $this->place('grill-a', type: 'restaurant');
        $this->place('cake-a', type: 'pastry');
        $this->place('odd-a', type: 'unknown');

        $this->assertSame(['grill-a'], $this->listed(['type' => 'restaurant']));
        $this->assertSame(['cake-a'], $this->listed(['type' => 'pastry']));
        // no setting or an unknown one is a cafe, as the dashboard reads it
        $this->assertSame(['cafe-a', 'cafe-b', 'odd-a'], $this->listed(['type' => 'cafe']));
        $this->assertSame([], $this->listed(['type' => 'shop']));

        $page = $this->actingAs($this->admin, 'admin')->get($this->adminUrl('/tenants'));
        $this->assertSame(['cafe' => 3, 'restaurant' => 1, 'both' => 0, 'pastry' => 1, 'shop' => 0], $page->viewData('typeCounts'));
        $page->assertSee('data-type-chip="pastry"', false)->assertSee('data-tenant-type="restaurant"', false);
        // the counts follow the other filters
        $this->restaurant('grill-b', 'basic');
        $this->assertSame(1, $this->actingAs($this->admin, 'admin')->get($this->adminUrl('/tenants?plan=basic'))->viewData('typeCounts')['cafe']);
    }

    public function test_filters_by_plan_status_paid_hidden_and_date(): void
    {
        $this->place('paid-a', 'pro');
        $this->place('trial-a', 'pro', trial: true);
        $this->place('free-a', 'free');
        $hidden = $this->place('hidden-a', 'basic');
        $hidden->update(['hidden_from_directory' => true]);
        $old = $this->place('old-a', 'basic');
        Tenant::query()->whereKey($old->id)->update(['created_at' => now()->subMonths(3)]);

        $this->assertSame(['hidden-a', 'old-a'], $this->listed(['plan' => 'basic']));
        $this->assertSame(['trial-a'], $this->listed(['status' => 'trialing']));
        // paying = an active subscription on a plan other than Free (the "by EgWeb" capsule rule)
        $this->assertSame(['hidden-a', 'old-a', 'paid-a'], $this->listed(['paid' => 'paid']));
        $this->assertSame(['free-a', 'trial-a'], $this->listed(['paid' => 'unpaid']));
        $this->assertSame(['hidden-a'], $this->listed(['hidden' => '1']));
        $this->assertNotContains('hidden-a', $this->listed(['hidden' => '0']));
        $this->assertNotContains('old-a', $this->listed(['created' => 'month']));
        $this->assertSame(['old-a'], $this->listed(['from' => now()->subMonths(4)->toDateString(), 'to' => now()->subMonths(2)->toDateString()]));
        // combined, and anything unknown is ignored
        $this->assertSame(['old-a'], $this->listed(['plan' => 'basic', 'hidden' => '0', 'paid' => 'paid']));
        $this->assertCount(5, $this->listed(['status' => 'nope', 'paid' => 'maybe', 'type' => 'boat', 'sort' => 'drop table']));
    }

    public function test_search_matches_link_name_owner_email_mobile_and_custom_domain(): void
    {
        $a = $this->place('alpha');
        $b = $this->place('bravo');
        $this->place('charlie');
        User::query()->where('tenant_id', $b->id)->update(['phone' => '01012345678']);
        Domain::query()->create(['domain' => 'menu.charlie-food.com', 'tenant_id' => 'charlie', 'source' => 'own', 'status' => 'active', 'verified_at' => now()]);
        $a->update(['name' => 'Koshary Corner']);

        $this->assertSame(['alpha'], $this->listed(['q' => 'koshary']));
        $this->assertSame(['alpha'], $this->listed(['q' => 'alph']));
        $this->assertSame(['bravo'], $this->listed(['q' => 'owner@bravo.test']));
        $this->assertSame(['bravo'], $this->listed(['q' => '01012345678']));
        // the same mobile typed with the country code
        $this->assertSame(['bravo'], $this->listed(['q' => '+20 101 234 5678']));
        $this->assertSame(['charlie'], $this->listed(['q' => 'charlie-food.com']));
        $this->assertSame([], $this->listed(['q' => 'nothing-like-this']));
        $this->actingAs($this->admin, 'admin')->get($this->adminUrl('/tenants?q=nothing-like-this'))->assertOk()
            ->assertSee('مفيش مطعم مطابق للبحث')->assertDontSee('مفيش مطاعم لسه');
    }

    public function test_sorting_and_filters_survive_pagination_with_a_reset_link(): void
    {
        foreach (range(1, 27) as $i) {
            $this->place(sprintf('place-%02d', $i), 'basic');
        }
        $this->place('zeta-pro', 'pro');

        $page = $this->actingAs($this->admin, 'admin')->get($this->adminUrl('/tenants?plan=basic&sort=name&page=2'))->assertOk()
            ->assertSee('data-filters-reset', false);
        $this->assertSame(['place-26', 'place-27'], collect($page->viewData('tenants')->items())->pluck('id')->all());
        $page->assertSee('plan=basic', false)->assertSee('sort=name', false);

        $this->actingAs($this->admin, 'admin')->get($this->adminUrl('/tenants'))->assertDontSee('data-filters-reset', false);
        // last activity: the place with the latest order first
        DB::table('orders')->insert([
            'tenant_id' => 'place-03', 'branch_id' => DB::table('branches')->where('tenant_id', 'place-03')->value('id'),
            'number' => 1, 'type' => 'takeaway', 'total' => 10, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $first = $this->actingAs($this->admin, 'admin')->get($this->adminUrl('/tenants?sort=activity'))->viewData('tenants')->items()[0];
        $this->assertSame('place-03', $first->id);
        $this->assertNotNull($first->last_order_at);
    }

    public function test_the_list_runs_a_bounded_number_of_queries(): void
    {
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($this->admin, 'admin')->get($this->adminUrl('/tenants?q=place'))->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };
        foreach (range(1, 3) as $i) {
            $this->place("place-$i", type: 'restaurant');
        }
        $count(); // warms the per-process caches (platform settings, brand)
        $few = $count();
        foreach (range(4, 20) as $i) {
            $this->place("place-$i", type: $i % 2 ? 'cafe' : 'pastry');
        }
        $count();

        $this->assertSame($few, $count());
        $this->assertLessThan(20, $few);
    }
}
