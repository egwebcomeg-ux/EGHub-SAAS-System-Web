<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\DiningTable;
use App\Models\Tenant;
use App\Services\Settings;
use Tests\TenantTestCase;

/** The public links page egmenu.app/{slug}/links and the QR target setting that can point the QR codes at it. */
class LinksPageTest extends TenantTestCase
{
    private const BASE = [
        'brand_name' => 'Kahwa', 'currency' => 'ج.م', 'currency_en' => 'EGP', 'color_primary' => '#112233',
        'color_secondary' => '#445566', 'color_bg' => '#FFFFFF', 'font' => 'Cairo', 'default_locale' => 'ar',
    ];

    private function table(Tenant $t): DiningTable
    {
        return $this->as($t, fn () => DiningTable::query()->first());
    }

    public function test_the_links_page_shows_every_button_that_applies(): void
    {
        $a = $this->restaurant('cafe-a', 'pro');
        $this->as($a, function () {
            Settings::put(['brand_name' => 'روستو', 'tagline' => 'قهوة مختصة', 'whatsapp' => '01011112222', 'instagram' => 'https://instagram.com/rosto',
                'tiktok' => 'https://tiktok.com/@rosto', 'reservations_enabled' => '1', 'city' => 'cairo', 'area' => 'المعادي']);
            Branch::query()->first()->update(['address' => '12 شارع 9', 'working_hours' => '9 ص - 1 ص']);
        });

        $html = $this->get($this->pathUrl('cafe-a', 'links'))->assertOk()
            ->assertSee('<title>روابط روستو | EgMenu</title>', false)
            ->assertSee('<link rel="canonical" href="http://egmenu.test/cafe-a/links">', false)
            ->assertSee('<meta property="og:title" content="روابط روستو">', false)
            ->assertDontSee('name="robots"', false)
            ->assertSee(['data-link="menu"', 'data-link="order"', 'data-link="reserve"', 'data-link="whatsapp"', 'data-link="instagram"', 'data-link="tiktok"', 'data-link="map"', 'data-hours'], false)
            ->assertDontSee('data-link="facebook"', false)
            ->assertSee(['المنيو', 'اطلب أونلاين', 'احجز ترابيزة', 'واتساب', 'الموقع على الخريطة', 'ساعات العمل', '9 ص - 1 ص', '12 شارع 9', 'قهوة مختصة'])
            ->getContent();
        $this->assertStringContainsString('https://wa.me/201011112222', $html);
        $this->assertStringContainsString('google.com/maps/search/?api=1&amp;query=12%20%D8%B4%D8%A7%D8%B1%D8%B9%209', $html);
        $this->assertStringContainsString('http://egmenu.test/cafe-a/reserve', str_replace('\/', '/', $html));

        // the subdomain alias serves the same page with its own links and the canonical path
        $sub = $this->get($this->subUrl('cafe-a', 'links'))->assertOk()
            ->assertSee('<link rel="canonical" href="http://egmenu.test/cafe-a/links">', false)->getContent();
        $this->assertStringContainsString('http://cafe-a.egmenu.test/reserve', $sub);
    }

    public function test_optional_buttons_stay_away_when_there_is_nothing_behind_them(): void
    {
        $a = $this->restaurant('cafe-s', 'starter'); // no online ordering, no reservations
        $this->get($this->pathUrl('cafe-s', 'links'))->assertOk()
            ->assertSee('data-link="menu"', false)
            ->assertDontSee('data-link="order"', false)->assertDontSee('data-link="reserve"', false)->assertDontSee('data-link="whatsapp"', false)
            ->assertDontSee('data-link="instagram"', false)->assertDontSee('data-link="map"', false)->assertDontSee('data-hours', false)
            ->assertSee('مقدم من');
        // the branch's own Google Maps link wins over an address search
        $this->as($a, fn () => Branch::query()->first()->update(['maps_url' => 'https://maps.app.goo.gl/x']));
        $this->get($this->pathUrl('cafe-s', 'links'))->assertOk()->assertSee('href="https://maps.app.goo.gl/x"', false);
    }

    public function test_the_qr_target_setting_points_the_qr_codes_at_the_links_page(): void
    {
        $a = $this->restaurant('cafe-a', 'pro');
        $owner = $this->owner($a);
        $token = $this->table($a)->qr_token;

        // default: the menu
        $this->get($this->pathUrl('cafe-a', "t/$token"))->assertRedirect($this->pathUrl('cafe-a'));
        $this->actingAs($owner)->get($this->dashUrl('/tables'))->assertOk()->assertSee('data-qr-target="menu"', false)->assertSee('/cafe-a/links');
        $this->actingAs($owner)->get($this->dashUrl('/tables/print?menu=1'))->assertOk()->assertSee('امسح عشان تشوف المنيو')->assertDontSee('وروابطنا');

        $this->actingAs($owner)->get($this->dashUrl('/settings'))->assertOk()->assertSee('id="links-page"', false)->assertSee('name="qr_target"', false);
        $this->actingAs($owner)->post($this->dashUrl('/settings'), self::BASE + ['qr_target' => 'links'])->assertSessionHasNoErrors();
        $this->assertSame('links', $this->as($a, fn () => Settings::get('qr_target')));
        $this->actingAs($owner)->post($this->dashUrl('/settings'), self::BASE + ['qr_target' => 'elsewhere'])->assertSessionHasErrors('qr_target');

        // printed table QRs keep their link and now land on the links page; the storefront QR encodes the links page
        $this->get($this->pathUrl('cafe-a', "t/$token"))->assertRedirect($this->pathUrl('cafe-a', 'links'));
        $this->get($this->subUrl('cafe-a', "t/$token"))->assertRedirect($this->subUrl('cafe-a', 'links'));
        $this->actingAs($owner)->get($this->dashUrl('/tables'))->assertOk()->assertSee('data-qr-target="links"', false);
        $this->actingAs($owner)->get($this->dashUrl('/tables/print?menu=1'))->assertOk()->assertSee('امسح للمنيو والطلب وروابطنا');
        // the form posted without the field keeps the current target
        $this->actingAs($owner)->post($this->dashUrl('/settings'), self::BASE)->assertSessionHasNoErrors();
        $this->assertSame('links', $this->as($a, fn () => Settings::get('qr_target')));
    }
}
