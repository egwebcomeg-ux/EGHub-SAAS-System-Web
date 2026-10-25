<?php

namespace Tests\Feature;

use App\Models\PlatformSetting;
use Tests\TenantTestCase;

/** EgHub's own Meta pixel: on the marketing site, once on the first dashboard page after a sign-up, never on menus. */
class PlatformPixelTest extends TenantTestCase
{
    private const PIXEL = '3037066589988797';

    public function test_the_marketing_site_loads_the_pixel_only_when_it_is_set(): void
    {
        $this->get($this->menuUrl('pricing'))->assertOk()->assertDontSee('data-platform-pixel', false);

        PlatformSetting::put('platform_meta_pixel', self::PIXEL);

        $this->get($this->menuUrl('pricing'))->assertOk()
            ->assertSee("fbq('init', '".self::PIXEL."')", false)
            ->assertSee("fbq('track', 'Contact')", false)
            ->assertDontSee('CompleteRegistration');

        // the sign-up page is part of the funnel: a PageView there, the CompleteRegistration only after the sign-up
        $this->get($this->menuUrl('register'))->assertOk()
            ->assertSee("fbq('init', '".self::PIXEL."')", false)
            ->assertSee("fbq('track', 'PageView')", false)
            ->assertDontSee('CompleteRegistration');
    }

    public function test_a_sign_up_counts_once_and_restaurant_menus_never_load_it(): void
    {
        PlatformSetting::put('platform_meta_pixel', self::PIXEL);

        $res = $this->post($this->menuUrl('register'), [
            'name' => 'كافيه البيكسل', 'slug' => 'pixel-cafe', 'owner_name' => 'Sara', 'email' => 'sara@pixel.test',
            'phone' => '01066666661', 'password' => 'Secret-pass-123', 'password_confirmation' => 'Secret-pass-123', 'terms' => '1',
        ]);
        $first = $this->get($res->headers->get('Location'))->headers->get('Location');

        $this->get($first)->assertOk()->assertSee("fbq('track', \"CompleteRegistration\")", false);
        $this->get($first)->assertOk()->assertDontSee('data-platform-pixel', false);

        $this->get($this->subUrl('pixel-cafe'))->assertOk()->assertDontSee('data-platform-pixel', false);
    }
}
