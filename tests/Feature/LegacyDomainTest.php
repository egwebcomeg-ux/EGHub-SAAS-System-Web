<?php

namespace Tests\Feature;

use App\Models\Domain;
use Tests\TenantTestCase;

/**
 * After the move from egmenu.app to eghub.app (CENTRAL_DOMAINS=eghub.app,egmenu.app): every old address, printed QR
 * codes included, redirects permanently to the same one on the new domain, and the old domain keeps the directory.
 */
class LegacyDomainTest extends TenantTestCase
{
    public function createApplication()
    {
        $this->useDomains('eghub.test,egmenu.test');

        return parent::createApplication();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->useDomains('egmenu.test');
    }

    private function useDomains(string $domains): void
    {
        putenv("CENTRAL_DOMAINS=$domains");
        $_ENV['CENTRAL_DOMAINS'] = $_SERVER['CENTRAL_DOMAINS'] = $domains;
    }

    public function test_old_menu_addresses_redirect_to_the_same_page_on_the_new_domain(): void
    {
        $this->restaurant('cafe-a');

        $this->get('http://egmenu.test/cafe-a/t/abc?lang=en')->assertStatus(301)->assertRedirect('http://eghub.test/cafe-a/t/abc?lang=en');
        $this->get('http://cafe-a.egmenu.test/t/abc')->assertStatus(301)->assertRedirect('http://cafe-a.eghub.test/t/abc');
        $this->get('http://cafe-a.egmenu.test')->assertRedirect('http://cafe-a.eghub.test/');
        $this->get('http://cafe-a.eghub.test')->assertOk();
    }

    public function test_dashboard_admin_and_platform_pages_follow_and_forms_keep_their_method(): void
    {
        $this->get('http://app.egmenu.test/login')->assertStatus(301)->assertRedirect('http://app.eghub.test/login');
        $this->get('http://admin.egmenu.test/')->assertRedirect('http://admin.eghub.test/');
        $this->get('http://egmenu.test/pricing')->assertRedirect('http://eghub.test/pricing');
        $this->post('http://app.egmenu.test/login')->assertStatus(308)->assertRedirect('http://app.eghub.test/login');
        $this->get('http://www.egmenu.test/restaurants')->assertRedirect('http://egmenu.test/restaurants');
        $this->get('http://eghub.test/')->assertOk();
    }

    public function test_the_old_domain_is_the_restaurant_directory(): void
    {
        $this->get('http://egmenu.test/')->assertOk()->assertSee('http://eghub.test/register', false);
        $this->get('http://egmenu.test/restaurants?q=x')->assertStatus(301)->assertRedirect('http://egmenu.test?q=x');
        $this->get('http://eghub.test/restaurants/cairo')->assertStatus(301)->assertRedirect('http://egmenu.test/restaurants/cairo');

        $this->get('http://egmenu.test/sitemap.xml')->assertOk()->assertSee('<loc>http://egmenu.test</loc>', false)
            ->assertDontSee('eghub.test', false);
        $this->get('http://eghub.test/sitemap.xml')->assertOk()->assertDontSee('egmenu.test', false);
        $this->get('http://egmenu.test/robots.txt')->assertOk()->assertSee('Sitemap: http://egmenu.test/sitemap.xml');
    }

    public function test_payment_webhook_is_still_served_on_the_old_dashboard_host(): void
    {
        $status = $this->postJson('http://app.egmenu.test/billing/kashier/webhook', [])->status();

        $this->assertFalse($status >= 300 && $status < 400, "webhook was redirected ($status)");
        $this->assertNotSame(404, $status);
    }

    /** Security regression: the redirects rebuild the address from our own domain; a crafted path never becomes another host. */
    public function test_legacy_redirects_never_leave_our_domains(): void
    {
        foreach ([
            'http://egmenu.test//evil.test/x' => 'http://eghub.test/evil.test/x',
            'http://egmenu.test/@evil.test' => 'http://eghub.test/@evil.test',
            'http://app.egmenu.test//evil.test' => 'http://app.eghub.test/evil.test',
            'http://www.egmenu.test//evil.test/menu' => 'http://egmenu.test/evil.test/menu',
            'http://eghub.test/restaurants//evil.test' => 'http://egmenu.test/restaurants//evil.test',
        ] as $from => $to) {
            $location = (string) $this->get($from)->assertRedirect()->headers->get('Location');
            $this->assertSame($to, $location, $from);
            $this->assertMatchesRegularExpression('~^http://(app\.|www\.)?(eghub|egmenu)\.test/~', $location, $from);
        }
    }

    public function test_old_domain_cannot_be_claimed_as_a_custom_domain(): void
    {
        $this->assertNull(Domain::normalize('menu.egmenu.test'));
        $this->assertNull(Domain::normalize('eghub.test'));
        $this->assertSame('menu.cafe.com', Domain::normalize('menu.cafe.com'));
    }
}
