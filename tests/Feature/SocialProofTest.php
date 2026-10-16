<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Services\Seo\SocialProof;
use App\Services\Settings;
use Illuminate\Support\Facades\DB;
use Tests\TenantTestCase;

/** The home page's social proof: the strip of listed places and the real counters, both hidden under their floors. */
class SocialProofTest extends TenantTestCase
{
    /** A listed place with three active items and a brand name (plus any settings). */
    private function place(string $slug, array $settings = []): Tenant
    {
        $tenant = $this->restaurant($slug);
        for ($i = 1; $i <= 3; $i++) {
            $this->item($tenant, "Item $i of $slug", 10 * $i);
        }
        $this->as($tenant, fn () => Settings::put($settings + ['brand_name' => "Brand $slug"]));

        return $tenant;
    }

    public function test_neither_section_renders_with_few_places_and_small_numbers(): void
    {
        $this->place('cafe-a', ['logo' => 'logos/a.png']);
        $this->place('cafe-b');

        $this->get($this->menuUrl())->assertOk()
            ->assertDontSee('data-social-proof', false)->assertDontSee('data-real-numbers', false)
            ->assertDontSee('Brand cafe-a')->assertDontSee('أرقام حقيقية');
        $this->assertSame([], SocialProof::places());
        $this->assertNull(SocialProof::counters());
    }

    public function test_strip_shows_listed_active_places_and_counters_need_every_floor(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $this->place("place-$i", $i === 1 ? ['logo' => 'logos/one.png', 'brand_name_en' => 'Place One'] : []);
        }
        $this->place('opted-out', ['listed_in_directory' => '0']);
        $this->place('paused')->update(['status' => 'suspended']);
        $this->restaurant('empty-signup'); // no items: not listed, but an active tenant

        // enough places for the strip, but no views or orders yet: the counters stay hidden
        $page = $this->get($this->menuUrl())->assertOk()->assertSee('data-social-proof', false)->assertDontSee('data-real-numbers', false);
        $page->assertSee('مطاعم ومحلات بتستخدم EgMenu')->assertSee('Brand place-1')->assertSee('Brand place-10')
            ->assertSee('href="http://egmenu.test/place-1"', false)->assertSee('/storage/logos/one.png')
            ->assertDontSee('Brand opted-out')->assertDontSee('Brand paused')->assertDontSee('Restaurant empty-signup');
        $this->assertCount(10, SocialProof::places());

        // real traffic: 1430 menu views and 205 orders (one cancelled, not counted)
        DB::table('menu_visits')->insert([
            ['tenant_id' => 'place-1', 'day' => today()->toDateString(), 'views' => 1000, 'scans' => 0],
            ['tenant_id' => 'place-2', 'day' => today()->toDateString(), 'views' => 430, 'scans' => 0],
        ]);
        $branch = DB::table('branches')->where('tenant_id', 'place-1')->value('id');
        $orders = [];
        for ($n = 1; $n <= 206; $n++) {
            $orders[] = ['tenant_id' => 'place-1', 'branch_id' => $branch, 'number' => $n, 'status' => $n === 206 ? 'cancelled' : 'served',
                'created_at' => now(), 'updated_at' => now()];
        }
        DB::table('orders')->insert($orders);
        SocialProof::flush();

        // active tenants: the ten places, the opted-out one and the empty sign-up; the suspended one is not live
        $this->assertSame(['restaurants' => 12, 'views' => 1430, 'orders' => 205], SocialProof::counters());
        $this->get($this->menuUrl())->assertOk()->assertSee('data-real-numbers', false)->assertSee('أرقام حقيقية')
            ->assertSee('data-counter="restaurants">12<', false)->assertSee('data-counter="views">1.4K+<', false)
            ->assertSee('data-counter="orders">200+<', false)->assertSee('بتتحدث كل 10 دقايق');
        $this->get($this->menuUrl('?lang=en'))->assertOk()->assertSee('Place One')->assertSee('Real numbers');
    }

    public function test_counters_are_cached_and_never_round_up(): void
    {
        $this->assertSame('37', SocialProof::compact(37));
        $this->assertSame('240+', SocialProof::compact(248));
        $this->assertSame('1K+', SocialProof::compact(1000));
        $this->assertSame('1.4K+', SocialProof::compact(1499));
        $this->assertSame('25K+', SocialProof::compact(25800));
        $this->assertSame('2.3M+', SocialProof::compact(2_390_000));

        $this->assertNull(SocialProof::counters());
        for ($i = 1; $i <= 10; $i++) {
            $this->restaurant("t-$i");
        }
        DB::table('menu_visits')->insert(['tenant_id' => 't-1', 'day' => today()->toDateString(), 'views' => 5000, 'scans' => 0]);
        $branch = DB::table('branches')->where('tenant_id', 't-1')->value('id');
        DB::table('orders')->insert(array_map(fn (int $n) => ['tenant_id' => 't-1', 'branch_id' => $branch, 'number' => $n, 'status' => 'served',
            'created_at' => now(), 'updated_at' => now()], range(1, 200)));

        $this->assertNull(SocialProof::counters(), 'the "not enough" answer is cached for ten minutes');
        SocialProof::flush();
        $this->assertSame(['restaurants' => 10, 'views' => 5000, 'orders' => 200], SocialProof::counters());
    }
}
