<?php

namespace Tests\Feature;

use App\Models\PlatformAdmin;
use App\Models\PlatformSetting;
use App\Models\Tenant;
use App\Services\Settings;
use Tests\TenantTestCase;

/**
 * Marketing site for the launch: refund policy page, company legal details only when EgWeb filled them, the launch
 * price end date, structured data and hreflang, and demo places kept out of the directory and the sitemap.
 */
class LaunchSiteTest extends TenantTestCase
{
    private function place(string $slug): Tenant
    {
        $tenant = $this->restaurant($slug);
        for ($i = 1; $i <= 3; $i++) {
            $this->item($tenant, "Item $i of $slug", 10 * $i);
        }
        $this->as($tenant, fn () => Settings::put(['brand_name' => "Brand $slug"]));

        return $tenant;
    }

    public function test_the_refund_policy_page_is_linked_and_shows_the_support_contacts(): void
    {
        PlatformSetting::put('support_email', 'help@egmenu.test');
        PlatformSetting::put('support_whatsapp', '01012345678');

        $this->get($this->menuUrl('refund-policy'))->assertOk()
            ->assertSee('سياسة الاسترداد')->assertSee('خلال ١٤ يوم')->assertSee('help@egmenu.test')
            ->assertSee('https://wa.me/201012345678', false);
        $this->get($this->menuUrl('refund-policy?lang=en'))->assertOk()
            ->assertSee('within 14 days to the same payment method')->assertSee('Installments');
        $this->get($this->menuUrl('terms'))->assertOk()->assertSee(route('refund'), false);

        $sitemap = $this->get($this->menuUrl('sitemap.xml'))->assertOk()->getContent();
        foreach (['terms', 'privacy', 'refund-policy'] as $path) {
            $this->assertStringContainsString("<loc>http://egmenu.test/$path</loc>", $sitemap);
        }
    }

    public function test_company_legal_details_show_only_once_filled(): void
    {
        $this->get($this->menuUrl('terms'))->assertOk()->assertDontSee('data-legal-details', false)->assertDontSee('data-footer-legal', false);

        $admin = PlatformAdmin::query()->create(['name' => 'Admin', 'email' => 'admin@egmenu.test', 'password' => 'Secret-pass-123']);
        $this->actingAs($admin, 'admin')->post($this->adminUrl('/settings'), ['legal_entity' => 'EgWeb Test LLC', 'commercial_register' => '12345'])
            ->assertSessionHasNoErrors();

        $this->get($this->menuUrl('refund-policy'))->assertOk()
            ->assertSee('data-legal-details', false)->assertSee('EgWeb Test LLC')->assertSee('12345')
            ->assertSee('data-footer-legal', false)->assertDontSee('الرقم الضريبي');
    }

    public function test_the_launch_price_end_date_shows_and_hides_the_crossed_out_price_after_it(): void
    {
        $this->get($this->menuUrl('pricing'))->assertOk()->assertSee('data-orig', false)->assertDontSee('data-offer-ends', false);

        PlatformSetting::put('launch_offer_ends_at', today()->addDays(10)->toDateString());
        $this->get($this->menuUrl('pricing'))->assertOk()->assertSee('data-orig', false)
            ->assertSee('العرض ساري لحد '.today()->addDays(10)->format('d/m/Y'));
        $this->get($this->menuUrl('/'))->assertOk()->assertSee('data-offer-ends', false);

        PlatformSetting::put('launch_offer_ends_at', today()->subDay()->toDateString());
        $this->get($this->menuUrl('pricing'))->assertOk()->assertDontSee('data-orig', false)->assertDontSee('data-offer-ends', false);
        $this->get($this->menuUrl('/'))->assertOk()->assertDontSee('line-through" dir="ltr"', false);
    }

    public function test_structured_data_and_language_alternates(): void
    {
        $home = $this->get($this->menuUrl('/'))->assertOk()->getContent();
        $this->assertStringContainsString('"@type":"Organization"', $home);
        $this->assertStringContainsString('"@type":"WebSite"', $home);
        $this->assertStringContainsString('<link rel="alternate" hreflang="en" href="http://egmenu.test?lang=en">', $home);
        $this->assertStringContainsString('<link rel="alternate" hreflang="x-default" href="http://egmenu.test">', $home);

        $pricing = $this->get($this->menuUrl('pricing?lang=en'))->assertOk()
            ->assertSee('<link rel="canonical" href="http://egmenu.test/pricing?lang=en">', false)->getContent();
        preg_match('#<script type="application/ld\+json">(\{"@context":"https://schema.org","@type":"SoftwareApplication".*?)</script>#s', $pricing, $m);
        $schema = json_decode($m[1] ?? '', true);
        $offers = collect($schema['offers'] ?? [])->keyBy('name');
        $this->assertSame('1250.00', $offers['Complete']['price']);
        $this->assertSame('EGP', $offers['Complete']['priceCurrency']);
        $this->assertSame('0.00', $offers['Free']['price']);
        $this->assertArrayNotHasKey('price', $offers['Enterprise']);

        $this->place('cafe-a');
        $menu = $this->get($this->pathUrl('cafe-a'))->assertOk()->getContent();
        $this->assertStringContainsString('<link rel="alternate" hreflang="ar" href="http://egmenu.test/cafe-a">', $menu);
        $this->assertStringContainsString('<link rel="alternate" hreflang="en" href="http://egmenu.test/cafe-a?lang=en">', $menu);
    }

    public function test_a_place_hidden_by_the_admin_is_out_of_the_directory_and_the_sitemap(): void
    {
        $this->place('real-cafe');
        $demo = $this->place('demo-cafe');
        $admin = PlatformAdmin::query()->create(['name' => 'Admin', 'email' => 'admin@egmenu.test', 'password' => 'Secret-pass-123']);

        $this->get($this->menuUrl('restaurants'))->assertOk()->assertSee('Brand demo-cafe');

        $this->actingAs($admin, 'admin')->get($this->adminUrl('/tenants/demo-cafe'))->assertOk()->assertSee('data-tenant-directory', false);
        $this->actingAs($admin, 'admin')->post($this->adminUrl('/tenants/demo-cafe/directory'), ['hidden_from_directory' => '1'])
            ->assertSessionHasNoErrors();
        $this->assertTrue($demo->fresh()->hidden_from_directory);

        $this->get($this->menuUrl('restaurants'))->assertOk()->assertSee('Brand real-cafe')->assertDontSee('Brand demo-cafe');
        $sitemap = $this->get($this->menuUrl('sitemap.xml'))->assertOk()->getContent();
        $this->assertStringContainsString('http://egmenu.test/real-cafe', $sitemap);
        $this->assertStringNotContainsString('demo-cafe', $sitemap);
        // its menu still opens by its link
        $this->get($this->pathUrl('demo-cafe'))->assertOk();
    }
}
