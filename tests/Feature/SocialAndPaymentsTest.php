<?php

namespace Tests\Feature;

use App\Models\PlatformAdmin;
use App\Models\PlatformSetting;
use Tests\TenantTestCase;

/** EgMenu's social media icons in the site footer, and the "connect your own payment gateway" service. */
class SocialAndPaymentsTest extends TenantTestCase
{
    public function test_social_icons_show_only_for_saved_https_links(): void
    {
        $this->get($this->menuUrl())->assertOk()->assertDontSee('data-social-links', false);

        $admin = PlatformAdmin::query()->create(['name' => 'A', 'email' => 'social@egweb.test', 'password' => 'Secret-pass-123']);
        $this->actingAs($admin, 'admin')->get($this->adminUrl('/settings'))->assertOk()->assertSee('name="social_facebook"', false);
        $this->actingAs($admin, 'admin')->post($this->adminUrl('/settings'), ['social_instagram' => 'javascript:alert(1)'])
            ->assertSessionHasErrors('social_instagram');
        $this->actingAs($admin, 'admin')->post($this->adminUrl('/settings'), [
            'social_facebook' => 'https://facebook.com/egmenu', 'social_tiktok' => 'https://tiktok.com/@egmenu',
        ])->assertSessionHasNoErrors();
        $this->assertSame('https://facebook.com/egmenu', PlatformSetting::get('social_facebook'));

        $this->get($this->menuUrl())->assertOk()->assertSee('data-social-links', false)
            ->assertSee('href="https://facebook.com/egmenu"', false)->assertSee('href="https://tiktok.com/@egmenu"', false)
            ->assertSee('aria-label="TikTok"', false)->assertDontSee('aria-label="YouTube"', false);
    }

    public function test_the_payment_gateway_service_is_offered_on_home_and_services(): void
    {
        $this->get($this->menuUrl())->assertOk()->assertSee('data-payment-gateways', false)
            ->assertSee('Fawry')->assertSee('Kashier')->assertSee('Paymob')->assertSee('Geidea')
            ->assertSee('service=payment_gateway', false);
        $this->get($this->menuUrl('services?service=payment_gateway'))->assertOk()->assertSee('دفع أونلاين ببوابة الدفع بتاعتك');
    }
}
