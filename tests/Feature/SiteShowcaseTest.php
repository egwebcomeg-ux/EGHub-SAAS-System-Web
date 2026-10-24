<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\PlatformAdmin;
use App\Models\Tenant;
use App\Services\Seo\SiteShowcase;
use App\Services\Settings;
use Tests\TenantTestCase;

/** The moving logo strip on the eghub.app home page: opted in by EgWeb per restaurant, shown from MIN_LOGOS logos. */
class SiteShowcaseTest extends TenantTestCase
{
    private function admin(string $role = 'support'): PlatformAdmin
    {
        return PlatformAdmin::query()->firstOrCreate(['email' => "$role@egweb.test"], ['name' => 'Admin', 'password' => 'Secret-pass-123', 'role' => $role]);
    }

    /** A restaurant with a brand name, optionally a logo, optionally in the strip. */
    private function place(string $slug, bool $logo = true, bool $showcase = true, ?int $sort = null): Tenant
    {
        $tenant = $this->restaurant($slug);
        $this->as($tenant, fn () => Settings::put(['brand_name' => "Brand $slug"] + ($logo ? ['logo' => "logos/$slug.png"] : [])));
        $tenant->update(['showcase_on_site' => $showcase, 'showcase_sort' => $sort]);

        return $tenant;
    }

    public function test_a_few_places_join_the_egweb_clients_in_one_strip(): void
    {
        for ($i = 1; $i < SiteShowcase::MIN_LOGOS; $i++) {
            $this->place("cafe-$i");
        }
        $this->assertSame([], SiteShowcase::logos()); // alone they are too few for a strip of their own

        // ...but in front of EgWeb's clients they show, under a title that names both
        $this->get($this->menuUrl())->assertOk()->assertSee('data-site-showcase', false)->assertSee('data-egweb-clients', false)
            ->assertSee('مطاعم وكافيهات بتثق فينا')->assertSee('مشتركين EgHub، وعملاء EgWeb الشركة اللي ورا EgHub.')
            ->assertSeeInOrder(['/storage/logos/cafe-1.png', '/img/egweb-clients/gold-sushi-club.webp'], false);
    }

    public function test_only_opted_in_active_places_with_a_logo_show_in_order(): void
    {
        $this->place('cafe-d', sort: 2);
        $this->place('cafe-a', sort: 1);
        $this->place('cafe-c');
        $this->place('cafe-b');
        $this->place('not-asked', showcase: false);
        $this->place('no-logo', logo: false);
        $this->place('paused')->update(['status' => 'suspended']);
        $this->place('gone')->delete();

        $this->assertSame(['cafe-a', 'cafe-d', 'cafe-b', 'cafe-c'], array_column(SiteShowcase::logos(), 'slug'));

        $page = $this->get($this->menuUrl())->assertOk()->assertSee('data-site-showcase', false)
            ->assertSee('مطاعم وكافيهات بتثق فينا')
            ->assertSee('href="http://egmenu.test/cafe-a" target="_blank" rel="noopener"', false)
            ->assertSee('src="/storage/logos/cafe-a.png" alt="Brand cafe-a" width="160" height="64" loading="lazy"', false)
            ->assertSee('data-showcase-copy', false);
        foreach (['not-asked', 'no-logo', 'paused', 'gone'] as $hidden) {
            $page->assertDontSee("/storage/logos/$hidden.png");
        }
    }

    public function test_the_cache_follows_the_admin_toggle_and_logo_changes(): void
    {
        foreach (['cafe-a', 'cafe-b', 'cafe-c'] as $slug) {
            $this->place($slug);
        }
        $fourth = $this->place('cafe-d', logo: false);
        $this->assertSame([], SiteShowcase::logos()); // cached: three logos

        $this->as($fourth, fn () => Settings::put(['logo' => 'logos/cafe-d.png']));
        $this->assertCount(4, SiteShowcase::logos());

        $this->actingAs($this->admin(), 'admin')->post($this->adminUrl('/tenants/cafe-a/showcase'), ['showcase_on_site' => '0'])->assertSessionHas('ok');
        $this->assertSame([], SiteShowcase::logos());
    }

    public function test_the_admin_toggle_needs_tenants_manage_and_is_audited(): void
    {
        $tenant = $this->place('cafe-a', showcase: false);

        $this->actingAs($this->admin(), 'admin')->get($this->adminUrl('/tenants/cafe-a'))->assertOk()
            ->assertSee('data-tenant-showcase', false)->assertSee('اعرض اللوجو على eghub.app')->assertSee('بس بعد ما صاحب المطعم يوافق');

        $this->actingAs($this->admin('finance'), 'admin')->post($this->adminUrl('/tenants/cafe-a/showcase'), ['showcase_on_site' => '1'])->assertForbidden();
        $this->assertFalse($tenant->fresh()->showcase_on_site);

        $this->actingAs($this->admin(), 'admin')->post($this->adminUrl('/tenants/cafe-a/showcase'), ['showcase_on_site' => '1', 'showcase_sort' => '3'])
            ->assertSessionHasNoErrors()->assertSessionHas('ok');
        $tenant->refresh();
        $this->assertTrue($tenant->showcase_on_site);
        $this->assertSame(3, $tenant->showcase_sort);

        $log = $this->as($tenant, fn () => AuditLog::query()->where('action', 'restaurant.showcase_by_platform')->sole());
        $this->assertSame(['admin' => 'support@egweb.test', 'on' => true, 'sort' => 3], $log->meta);
    }
}
