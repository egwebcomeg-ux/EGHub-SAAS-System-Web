<?php

namespace Tests\Feature;

use App\Models\PlatformAdmin;
use App\Models\PlatformSetting;
use Tests\TenantTestCase;

/** The marketing site's footer contact block: sales and support WhatsApp numbers and the public email, set by the admin. */
class FooterContactsTest extends TenantTestCase
{
    public function test_the_block_is_hidden_until_a_contact_is_set(): void
    {
        $this->get($this->menuUrl())->assertOk()->assertDontSee('data-footer-contacts', false)->assertDontSee('wa.me/', false);
    }

    public function test_each_contact_shows_only_when_set_as_a_clickable_link(): void
    {
        PlatformSetting::put('support_whatsapp', '0102 222 2222');
        $this->get($this->menuUrl())->assertOk()->assertSee('data-footer-contacts', false)
            ->assertSee('href="https://wa.me/201022222222"', false)->assertSee('الدعم:')->assertDontSee('المبيعات:')->assertDontSee('mailto:', false);

        PlatformSetting::put('sales_whatsapp', '+20 101 111 1111');
        PlatformSetting::put('contact_email', 'hi@eghub.app');
        $this->get($this->menuUrl('pricing'))->assertOk()
            ->assertSee('href="https://wa.me/201011111111"', false)->assertSee('المبيعات:')
            ->assertSee('href="https://wa.me/201022222222"', false)->assertSee('الدعم:')
            ->assertSee('href="mailto:hi@eghub.app"', false)->assertSee('hi@eghub.app');
        $this->get($this->menuUrl('?lang=en'))->assertOk()->assertSee('Sales:')->assertSee('Support:')->assertSee('(WhatsApp)');
    }

    public function test_the_admin_saves_the_new_keys(): void
    {
        $admin = PlatformAdmin::query()->create(['name' => 'A', 'email' => 'a@egweb.test', 'password' => 'Secret-pass-123']);

        $this->actingAs($admin, 'admin')->get($this->adminUrl('/settings'))->assertOk()
            ->assertSee('name="sales_whatsapp"', false)->assertSee('name="contact_email"', false);
        $this->actingAs($admin, 'admin')->post($this->adminUrl('/settings'), [
            'support_whatsapp' => '01022222222', 'sales_whatsapp' => '01011111111', 'contact_email' => 'hi@eghub.app',
        ])->assertSessionHasNoErrors();
        $this->assertSame('01011111111', PlatformSetting::get('sales_whatsapp'));
        $this->assertSame('hi@eghub.app', PlatformSetting::get('contact_email'));

        $this->actingAs($admin, 'admin')->post($this->adminUrl('/settings'), ['contact_email' => 'not-an-email'])->assertSessionHasErrors('contact_email');
    }
}
