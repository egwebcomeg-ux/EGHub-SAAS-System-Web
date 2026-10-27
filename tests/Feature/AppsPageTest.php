<?php

namespace Tests\Feature;

use App\Models\Tenant;
use Tests\TenantTestCase;

/** The «EgHub apps» page: only an app that can really be downloaded says «available». */
class AppsPageTest extends TenantTestCase
{
    public function test_the_screens_app_is_available_and_the_others_download_as_early_versions(): void
    {
        $page = $this->get($this->menuUrl('apps'))->assertOk()->assertSeeText('تطبيقات EgHub');

        $html = $page->getContent();
        $this->assertMatchesRegularExpression('~data-app="screens" data-status="available"~', $html);
        foreach (['pos', 'restaurant', 'waiter', 'driver', 'kiosk'] as $app) {
            $this->assertMatchesRegularExpression('~data-app="'.$app.'" data-status="beta"~', $html);
            $page->assertSee('href="http://egmenu.test/downloads/eghub-'.$app.'.apk"', false);
        }
        $page->assertSee('href="http://egmenu.test/tv.apk"', false);
        $this->assertSame(6, substr_count($html, 'data-app-download'));
        $page->assertSeeText('نسخة أولى')->assertSeeText('لسه مش على Google Play')->assertDontSee('App Store');
    }

    public function test_an_early_version_without_its_file_falls_back_to_coming_soon(): void
    {
        $path = public_path('downloads/eghub-kiosk.apk');
        rename($path, $path.'.bak');
        try {
            $html = $this->get($this->menuUrl('apps'))->assertOk()->getContent();
            $this->assertMatchesRegularExpression('~data-app="kiosk" data-status="soon"~', $html);
        } finally {
            rename($path.'.bak', $path);
        }
    }

    public function test_the_apps_page_is_linked_from_the_site_and_listed_in_the_sitemap(): void
    {
        $this->get($this->menuUrl('features'))->assertOk()->assertSee('data-footer-apps', false)->assertSee('data-nav-apps', false);
        $this->get($this->menuUrl('sitemap.xml'))->assertOk()->assertSee('http://egmenu.test/apps');
        $this->assertSame(__('This name is reserved, please choose another.'), Tenant::slugError('apps'));
    }

    public function test_the_feature_pages_say_coming_soon_for_what_the_apps_will_add(): void
    {
        $this->get($this->menuUrl('features/pos'))->assertOk()->assertSeeText('قريباً مع تطبيق كاشير EgHub للأندرويد');
        $this->get($this->menuUrl('features/delivery-drivers'))->assertOk()->assertSeeText('قريباً: تطبيق الطيار من EgHub');
    }
}
