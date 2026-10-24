<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Domain;
use App\Models\Feedback;
use App\Models\Tenant;
use App\Services\Settings;
use App\Support\OpeningHours;
use Tests\TenantTestCase;

/** Restaurant profile fields ("Showing up in search") and the SEO profile page eghub.app/{slug}/about. */
class SeoProfileTest extends TenantTestCase
{
    private const BASE = [
        'brand_name' => 'Kahwa', 'currency' => 'ج.م', 'currency_en' => 'EGP', 'color_primary' => '#112233',
        'color_secondary' => '#445566', 'color_bg' => '#FFFFFF', 'font' => 'Cairo', 'default_locale' => 'ar',
    ];

    public function test_owner_saves_the_search_profile(): void
    {
        $a = $this->restaurant('cafe-a');
        $this->actingAs($this->owner($a))->get($this->dashUrl('/settings'))->assertOk()->assertSee('الظهور في البحث')->assertSee('الإسكندرية');

        $this->actingAs($this->owner($a))->post($this->dashUrl('/settings'), self::BASE + [
            'city' => 'alexandria', 'area' => 'سموحة', 'cuisine' => ['coffee', 'desserts'],
            'short_description' => 'قهوة مختصة وحلويات', 'short_description_en' => 'Specialty coffee', 'listed_in_directory' => '1',
        ])->assertSessionHasNoErrors();

        $saved = $this->as($a, fn () => Settings::all());
        $this->assertSame(['alexandria', 'سموحة', 'coffee,desserts', '1'], [$saved['city'], $saved['area'], $saved['cuisine'], $saved['listed_in_directory']]);

        // unknown city / cuisine and too long descriptions are refused; unchecking hides from the directory
        $this->actingAs($this->owner($a))->post($this->dashUrl('/settings'), self::BASE + ['city' => 'paris'])->assertSessionHasErrors('city');
        $this->actingAs($this->owner($a))->post($this->dashUrl('/settings'), self::BASE + ['cuisine' => ['sushi']])->assertSessionHasErrors('cuisine.0');
        $this->actingAs($this->owner($a))->post($this->dashUrl('/settings'), self::BASE + ['short_description' => str_repeat('a', 161)])->assertSessionHasErrors('short_description');
        $this->actingAs($this->owner($a))->post($this->dashUrl('/settings'), self::BASE)->assertSessionHasNoErrors();
        $this->assertSame('0', $this->as($a, fn () => Settings::get('listed_in_directory')));
    }

    /** @return list<array<string, mixed>> the JSON-LD blocks of a page */
    private function jsonLd(string $html): array
    {
        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);

        return array_map(fn ($json) => json_decode($json, true, flags: JSON_THROW_ON_ERROR), $m[1]);
    }

    private function profile(Tenant $tenant, array $settings): void
    {
        $this->as($tenant, function () use ($settings) {
            Settings::put($settings);
            Branch::query()->first()->update(['address' => '12 شارع 9', 'working_hours' => '9 AM – 1 AM', 'phone' => '01011112222', 'maps_url' => 'https://maps.app.goo.gl/x']);
        });
    }

    public function test_cafe_profile_page_has_title_meta_and_cafe_json_ld(): void
    {
        $a = $this->restaurant('cafe-a');
        $this->item($a, 'Latte', 65);
        $this->item($a, 'Mocha', 80);
        $this->profile($a, ['brand_name' => 'روستو', 'business_type' => 'cafe', 'city' => 'cairo', 'area' => 'المعادي',
            'cuisine' => 'coffee,desserts', 'short_description' => 'قهوة مختصة في قلب المعادي', 'whatsapp' => '01011112222']);
        $this->as($a, fn () => Feedback::query()->insert([
            ['tenant_id' => 'cafe-a', 'rating' => 5, 'created_at' => now()], ['tenant_id' => 'cafe-a', 'rating' => 4, 'created_at' => now()],
        ]));

        $html = $this->get($this->pathUrl('cafe-a', 'about'))->assertOk()
            ->assertSee('<title>منيو روستو في المعادي، القاهرة | EgHub</title>', false)
            ->assertSee('<meta name="description" content="قهوة مختصة في قلب المعادي">', false)
            ->assertSee('<link rel="canonical" href="http://egmenu.test/cafe-a/about">', false)
            ->assertSee('og:image', false)
            ->assertSee(['افتح المنيو', 'Latte', '65', 'قهوة', 'حلويات', '12 شارع 9', 'wa.me/2', 'طلب من الترابيزة', '4.5'])
            ->getContent();

        [$place, $menu] = $this->jsonLd($html);
        $this->assertSame('CafeOrCoffeeShop', $place['@type']);
        $this->assertSame('روستو', $place['name']);
        $this->assertSame('http://egmenu.test/cafe-a', $place['hasMenu']);
        $this->assertSame(['Coffee', 'Desserts'], $place['servesCuisine']);
        $this->assertSame('Mo-Su 09:00-01:00', $place['openingHours']);
        $this->assertSame('01011112222', $place['telephone']);
        $this->assertSame(['@type' => 'PostalAddress', 'streetAddress' => '12 شارع 9', 'addressLocality' => 'المعادي', 'addressRegion' => 'Cairo', 'addressCountry' => 'EG'], $place['address']);
        $this->assertSame(['@type' => 'AggregateRating', 'ratingValue' => 4.5, 'reviewCount' => 2, 'bestRating' => 5, 'worstRating' => 1], $place['aggregateRating']);
        $this->assertSame('Menu', $menu['@type']);
        $this->assertSame('MenuSection', $menu['hasMenuSection'][0]['@type']);
        $this->assertSame(['@type' => 'Offer', 'price' => '65.00', 'priceCurrency' => 'EGP'], $menu['hasMenuSection'][0]['hasMenuItem'][0]['offers']);
        $this->assertSame('Latte', $menu['hasMenuSection'][0]['hasMenuItem'][0]['name']);
    }

    public function test_restaurant_profile_without_ratings_feature_or_hours_publishes_nothing_wrong(): void
    {
        $a = $this->restaurant('rest-a', 'free');
        $this->item($a, 'Kofta', 150);
        $this->profile($a, ['brand_name' => 'Sofra', 'business_type' => 'restaurant']);
        $this->as($a, function () {
            Branch::query()->first()->update(['working_hours' => 'من الضهر لآخر الليل']);
            Feedback::query()->insert(['tenant_id' => 'rest-a', 'rating' => 1, 'created_at' => now()]);
        });

        $html = $this->get($this->subUrl('rest-a', 'about'))->assertOk()
            ->assertSee('<title>منيو Sofra | EgHub</title>', false)
            ->assertSee('<link rel="canonical" href="http://egmenu.test/rest-a/about">', false)
            ->assertDontSee('طلب من الترابيزة')->getContent();
        [$place] = $this->jsonLd($html);
        $this->assertSame('Restaurant', $place['@type']);
        $this->assertArrayNotHasKey('openingHours', $place);
        $this->assertArrayNotHasKey('aggregateRating', $place);
        $this->assertArrayNotHasKey('servesCuisine', $place);
    }

    public function test_menu_page_has_seo_tags_and_links_to_the_profile_on_each_host(): void
    {
        $a = $this->restaurant('cafe-a', 'business');
        $this->item($a, 'Latte', 65);
        $this->profile($a, ['brand_name' => 'روستو', 'city' => 'giza', 'area' => 'الدقي']);

        $html = $this->get($this->pathUrl('cafe-a'))->assertOk()
            ->assertSee('<title>منيو روستو في الدقي، الجيزة | EgHub</title>', false)
            ->assertSee('href="http://egmenu.test/cafe-a/about"', false)->assertSee('عن المكان')->getContent();
        $this->assertSame('Menu', $this->jsonLd($html)[0]['@type']);
        $this->get($this->subUrl('cafe-a'))->assertSee('href="http://cafe-a.egmenu.test/about"', false);

        // a custom domain is canonical for its pages, wherever they are opened
        Domain::query()->create(['domain' => 'rosto.com', 'tenant_id' => 'cafe-a', 'source' => 'own', 'status' => 'active', 'verified_at' => now()]);
        $this->get('http://rosto.com/about')->assertOk()->assertSee('<link rel="canonical" href="http://rosto.com/about">', false);
        $this->get($this->pathUrl('cafe-a', 'about'))->assertSee('<link rel="canonical" href="http://rosto.com/about">', false);
    }

    public function test_opening_hours_parser(): void
    {
        $this->assertSame('Mo-Su 09:00-01:00', OpeningHours::toSchema('9 AM – 1 AM'));
        $this->assertSame('Mo-Su 09:00-14:30', OpeningHours::toSchema('٩ ص - ٢:٣٠ م'));
        $this->assertSame('Mo-Su 08:00-23:30', OpeningHours::toSchema('08:00 - 23:30'));
        $this->assertSame('Mo-Su 00:00-23:59', OpeningHours::toSchema('24 ساعة'));
        $this->assertNull(OpeningHours::toSchema('9 - 1'));
        $this->assertNull(OpeningHours::toSchema('من الضهر'));
        $this->assertNull(OpeningHours::toSchema(''));
    }
}
