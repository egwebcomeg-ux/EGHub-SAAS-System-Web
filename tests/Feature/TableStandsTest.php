<?php

namespace Tests\Feature;

use App\Models\DiningTable;
use App\Services\Settings;
use App\Support\StandStyles;
use Tests\TenantTestCase;

/** Table QR stands: the design saved per restaurant, the print page, the SVG download and the studio on /services. */
class TableStandsTest extends TenantTestCase
{
    public function test_the_print_page_renders_every_style_and_layout_from_the_settings(): void
    {
        $a = $this->restaurant('cafe-a');
        $owner = $this->owner($a);

        $page = $this->actingAs($owner)->get($this->dashUrl('/tables/print'))->assertOk()
            ->assertSee('data-style="white"', false)->assertSee('data-layout="classic"', false)
            ->assertSee('امسح الكود واطلب')->assertSee('ترابيزة 1')->assertSee('@page { size: A4; margin: 10mm; }', false);
        $this->assertSame(5, substr_count($page->getContent(), 'class="cell"'));

        foreach (StandStyles::keys() as $style) {
            $this->as($a, fn () => Settings::put(['stand_style' => $style, 'stand_layout' => 'a6']));
            $this->actingAs($owner)->get($this->dashUrl('/tables/print'))->assertOk()
                ->assertSee('data-style="'.$style.'"', false)->assertDontSee('data-style="'.($style === 'white' ? 'navy' : 'white').'" style', false)
                ->assertSee('@page { size: 105mm 148mm; margin: 0; }', false);
        }
        $this->as($a, fn () => Settings::put(['stand_layout' => 'a4']));
        $this->actingAs($owner)->get($this->dashUrl('/tables/print'))->assertSee('data-layout="a4"', false)->assertSee('@page { size: A4; margin: 0; }', false);
    }

    public function test_the_optional_lines_follow_the_saved_options(): void
    {
        $a = $this->restaurant('cafe-a');
        $owner = $this->owner($a);

        // defaults: waiter and link on, payment and Wi-Fi off
        $html = $this->actingAs($owner)->get($this->dashUrl('/tables/print'))->assertOk()->getContent();
        $this->assertDoesNotMatchRegularExpression('/data-line="waiter"\s+hidden/', $html);
        $this->assertMatchesRegularExpression('/data-line="payment"\s+hidden/', $html);
        $this->assertMatchesRegularExpression('/data-line="wifi"\s+hidden/', $html);
        $this->assertStringContainsString('egmenu.test/cafe-a</span>', $html);

        $this->actingAs($owner)->post($this->dashUrl('/tables/design'), [
            'stand_style' => 'wood', 'stand_layout' => 'a4', 'accent' => '#0f766e', 'payment' => '1',
            'wifi_name' => 'Cafe-Guest', 'wifi_password' => 'pass1234',
        ])->assertSessionHasNoErrors();

        $html = $this->actingAs($owner)->get($this->dashUrl('/tables/print'))->assertOk()
            ->assertSee('--accent: #0F766E', false)->assertSee('Cafe-Guest')->assertSee('pass1234')->getContent();
        $this->assertMatchesRegularExpression('/data-line="waiter"\s+hidden/', $html);
        $this->assertDoesNotMatchRegularExpression('/data-line="payment"\s+hidden/', $html);
        $this->assertDoesNotMatchRegularExpression('/data-line="wifi"\s+hidden/', $html);
        $this->assertMatchesRegularExpression('/data-line="url"\s+hidden/', $html);
    }

    public function test_the_waiter_line_needs_table_ordering(): void
    {
        $a = $this->restaurant('cafe-a', 'starter'); // menu only, no ordering from the table

        $this->actingAs($this->owner($a))->get($this->dashUrl('/tables/print'))->assertOk()
            ->assertDontSee('data-line="waiter"', false)->assertDontSee('امسح الكود واطلب')->assertSee('امسح عشان تشوف المنيو');
    }

    public function test_the_design_is_validated(): void
    {
        $a = $this->restaurant('cafe-a');
        $owner = $this->owner($a);
        $valid = ['stand_style' => 'navy', 'stand_layout' => 'a6', 'accent' => '#112233'];

        $this->actingAs($owner)->post($this->dashUrl('/tables/design'), ['stand_style' => 'gold'] + $valid)->assertSessionHasErrors('stand_style');
        $this->actingAs($owner)->post($this->dashUrl('/tables/design'), ['stand_layout' => 'a3'] + $valid)->assertSessionHasErrors('stand_layout');
        $this->actingAs($owner)->post($this->dashUrl('/tables/design'), ['accent' => 'red'] + $valid)->assertSessionHasErrors('accent');
        $this->actingAs($owner)->post($this->dashUrl('/tables/design'), ['wifi_name' => str_repeat('x', 41)] + $valid)->assertSessionHasErrors('wifi_name');
        $this->assertSame('white', $this->as($a, fn () => StandStyles::current()['style']));

        $this->actingAs($owner)->post($this->dashUrl('/tables/design'), $valid)->assertSessionHasNoErrors();
        $this->assertSame(['navy', 'a6'], $this->as($a, fn () => [StandStyles::current()['style'], StandStyles::current()['layout']]));
    }

    public function test_one_table_downloads_as_an_svg_of_its_own_restaurant_only(): void
    {
        $a = $this->restaurant('cafe-a');
        $b = $this->restaurant('cafe-b');
        $this->as($a, fn () => Settings::put(['brand_name' => 'Rosto', 'stand_style' => 'navy']));
        $table = $this->as($a, fn () => DiningTable::query()->where('name', '3')->firstOrFail());
        $other = $this->as($b, fn () => DiningTable::query()->firstOrFail());

        $response = $this->actingAs($this->owner($a))->get($this->dashUrl("/tables/{$table->id}/svg"))->assertOk()
            ->assertHeader('Content-Type', 'image/svg+xml; charset=utf-8')
            ->assertHeader('Content-Disposition', 'attachment; filename="table-3-qr.svg"');
        $svg = $response->getContent();
        $this->assertStringStartsWith('<?xml', $svg);
        $this->assertStringContainsString('ترابيزة 3', $svg);
        $this->assertStringContainsString('Rosto', $svg);
        $this->assertStringContainsString('#21437A', $svg); // navy palette
        $this->assertMatchesRegularExpression('/<svg xmlns="http:\/\/www.w3.org\/2000\/svg" version="1.1" x="[\d.]+" y="[\d.]+" width="580"/', $svg);
        $this->assertNotFalse(simplexml_load_string($svg), 'The SVG is well-formed XML');

        $this->actingAs($this->owner($a))->get($this->dashUrl("/tables/{$other->id}/svg"))->assertNotFound();
    }

    public function test_an_english_restaurant_prints_its_cards_in_english_whatever_the_dashboard_language(): void
    {
        $a = $this->restaurant('cafe-a', 'starter');
        $this->as($a, fn () => Settings::put(['default_locale' => 'en', 'brand_name' => 'روستو', 'brand_name_en' => 'Rosto']));
        $owner = $this->owner($a);

        // the dashboard itself stays Arabic, the cards are English and left-to-right
        $this->actingAs($owner)->get($this->dashUrl('/tables/print'))->assertOk()
            ->assertSee('تصميم الستاند')->assertSee('Scan to see the menu')->assertSee('Table 1')->assertSee('Rosto')
            ->assertSee('lang="en" dir="ltr"', false)->assertDontSee('امسح عشان تشوف المنيو');

        $table = $this->as($a, fn () => DiningTable::query()->firstOrFail());
        $svg = $this->actingAs($owner)->get($this->dashUrl("/tables/{$table->id}/svg"))->assertOk()->getContent();
        $this->assertStringContainsString('direction="ltr"', $svg);
        $this->assertStringContainsString('Table '.$table->name, $svg);
        $this->assertStringContainsString('Scan to see the menu', $svg);
    }

    public function test_the_services_page_has_the_stand_studio(): void
    {
        $this->get($this->menuUrl('services'))->assertOk()
            ->assertSee('data-stand-studio', false)->assertSee('صمّم ستاند الترابيزة بتاعك')->assertSee('اطلب الستاندات مطبوعة')
            ->assertSee('أكريليك كحلي')->assertSee('خشب طبيعي')->assertSee('شفاف فروستد')
            ->assertSee('ده QR للتجربة بيفتح eghub.app')->assertSee('data-stand-prices', false)
            ->assertSee('services?stand=white', false);
    }

    public function test_ordering_from_the_studio_prefills_the_print_request(): void
    {
        $html = $this->get($this->menuUrl('services?stand=wood&accent=%230f766e&restaurant=Rosto&table=7'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<option value="print"\s+selected/', $html);
        $this->assertStringContainsString('name="restaurant" value="Rosto"', $html);
        $this->assertStringContainsString('ستاندات QR للترابيزات، تصميم خشب طبيعي، واللون المميز #0F766E. رقم الترابيزة في النموذج: 7.</textarea>', $html);

        // an unknown design leaves the form as it is
        $html = $this->get($this->menuUrl('services?stand=gold&restaurant=Rosto'))->assertOk()->getContent();
        $this->assertStringNotContainsString('name="restaurant" value="Rosto"', $html);
        $this->assertDoesNotMatchRegularExpression('/<option value="print"\s+selected/', $html);
    }
}
