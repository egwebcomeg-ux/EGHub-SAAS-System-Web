<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\MenuItem;
use App\Models\Tenant;
use App\Services\Settings;
use Illuminate\Testing\TestResponse;
use Tests\TenantTestCase;

/** The bilingual guest menu (setting menu_bilingual): the other base language under each name, smaller. */
class MenuBilingualTest extends TenantTestCase
{
    private const FORM = [
        'brand_name' => 'Cafe A', 'currency' => 'ج.م', 'currency_en' => 'EGP', 'color_primary' => '#F26522',
        'color_secondary' => '#0A2540', 'color_bg' => '#FFFFFF', 'font' => 'Cairo', 'default_locale' => 'ar',
    ];

    /** Grills (both languages) with a burger (both, a size group), kofta (Arabic only) and a "Pizza" named the same in both. */
    private function menu(bool $bilingual, array $settings = []): Tenant
    {
        $tenant = $this->restaurant('cafe-a');
        $this->as($tenant, function () use ($bilingual, $settings) {
            Settings::put(['menu_bilingual' => $bilingual ? '1' : '0'] + $settings);
            $cat = Category::query()->create(['name' => 'مشويات', 'name_en' => 'Grills']);
            $burger = MenuItem::query()->create(['category_id' => $cat->id, 'name' => 'برجر لحم', 'name_en' => 'Beef burger', 'price' => 120, 'is_featured' => true]);
            MenuItem::query()->create(['category_id' => $cat->id, 'name' => 'كفتة', 'name_en' => '', 'price' => 90]);
            MenuItem::query()->create(['category_id' => $cat->id, 'name' => 'Pizza', 'name_en' => 'pizza', 'price' => 150]);
            $size = $burger->optionGroups()->create(['name' => 'الحجم', 'name_en' => 'Size', 'type' => 'single', 'is_required' => true]);
            $size->options()->create(['name' => 'دبل', 'name_en' => 'Double', 'price' => 40]);
            $size->options()->create(['name' => 'سينجل', 'name_en' => '', 'price' => 0]);
        });

        return $tenant;
    }

    /** @return array<string, mixed> */
    private function data(TestResponse $res): array
    {
        return json_decode((string) str($res->getContent())->betweenFirst('<script type="application/json" id="menu-data">', '</script>'), true);
    }

    public function test_off_by_default_no_second_name_shows(): void
    {
        $this->menu(false);

        $res = $this->get($this->pathUrl('cafe-a'))->assertOk()->assertSee('برجر لحم')->assertDontSee('data-alt-name', false)->assertDontSee('Beef burger');
        $data = $this->data($res);
        $this->assertNull($data['alt']);
        $burger = collect($data['items'])->firstWhere('price', 120);
        $this->assertNull($burger['nameAlt']);
        $this->assertNull($burger['groups'][0]['nameAlt']);
        $this->assertNull($burger['groups'][0]['options'][0]['nameAlt']);
    }

    public function test_on_the_other_language_shows_under_names_on_cards_headings_and_in_the_sheet_data(): void
    {
        $this->menu(true);

        // Arabic page: English under the Arabic names, in its own language and direction
        $res = $this->get($this->pathUrl('cafe-a'))->assertOk()
            ->assertSee('<span lang="en" dir="ltr">Beef burger</span>', false)
            ->assertSee('<span lang="en" dir="ltr">Grills</span>', false);
        $html = (string) $res->getContent();
        // the heading carries it, the category chip stays on one line
        $this->assertMatchesRegularExpression('/<h2[^>]*>مشويات<span class="m-alt[^"]*" data-alt-name><span lang="en" dir="ltr">Grills<\/span>/u', $html);
        $this->assertDoesNotMatchRegularExpression('/data-cat-link="cat-\d+"[^>]*>مشويات<span class="m-alt/u', $html);
        // the featured row and the item card both show it
        $this->assertGreaterThanOrEqual(2, substr_count($html, '>Beef burger</span>'));

        $data = $this->data($res);
        $this->assertSame(['lang' => 'en', 'dir' => 'ltr'], $data['alt']);
        $burger = collect($data['items'])->firstWhere('price', 120);
        $this->assertSame('Beef burger', $burger['nameAlt']);
        $this->assertSame('Size', $burger['groups'][0]['nameAlt']);
        $this->assertSame('Double', $burger['groups'][0]['options'][0]['nameAlt']);

        // English page: Arabic under the English names
        $res = $this->get($this->pathUrl('cafe-a', '?lang=en'))->assertOk()
            ->assertSee('<span lang="ar" dir="rtl">برجر لحم</span>', false)
            ->assertSee('<span lang="ar" dir="rtl">مشويات</span>', false);
        $data = $this->data($res);
        $this->assertSame(['lang' => 'ar', 'dir' => 'rtl'], $data['alt']);
        $burger = collect($data['items'])->firstWhere('price', 120);
        $this->assertSame('برجر لحم', $burger['nameAlt']);
        $this->assertSame('الحجم', $burger['groups'][0]['nameAlt']);
        $this->assertSame('دبل', $burger['groups'][0]['options'][0]['nameAlt']);
    }

    public function test_missing_or_identical_other_names_are_skipped(): void
    {
        $this->menu(true);

        $data = $this->data($this->get($this->pathUrl('cafe-a'))->assertOk());
        $items = collect($data['items']);
        $this->assertNull($items->firstWhere('price', 90)['nameAlt']);   // كفتة: no English
        $this->assertNull($items->firstWhere('price', 150)['nameAlt']);  // Pizza / pizza: the same name
        $this->assertNull($items->firstWhere('price', 120)['groups'][0]['options'][1]['nameAlt']); // سينجل: no English

        // reading English, an item without English shows its Arabic name once, with nothing under it
        $data = $this->data($this->get($this->pathUrl('cafe-a', '?lang=en'))->assertOk());
        $this->assertNull(collect($data['items'])->firstWhere('price', 90)['nameAlt']);
    }

    public function test_every_item_layout_shows_the_second_name(): void
    {
        $tenant = $this->menu(true);

        foreach (['classic', 'cafe', 'bistro', 'minimal', 'grid', 'fastfood', 'oriental'] as $theme) {
            $this->as($tenant, fn () => Settings::put(['menu_theme' => $theme]));
            $this->assertGreaterThanOrEqual(2, substr_count((string) $this->get($this->pathUrl('cafe-a'))->assertOk()->getContent(), '>Beef burger</span>'), $theme);
        }
        foreach (['list', 'grid', 'cards', 'bold', 'text'] as $layout) {
            $this->as($tenant, fn () => Category::query()->update(['menu_layout' => $layout]));
            $this->assertGreaterThanOrEqual(2, substr_count((string) $this->get($this->pathUrl('cafe-a'))->assertOk()->getContent(), '>Beef burger</span>'), $layout);
        }
    }

    public function test_the_owner_turns_it_on_and_off_and_staff_without_the_permission_cannot(): void
    {
        $tenant = $this->restaurant('cafe-a');
        $owner = $this->owner($tenant);

        $this->actingAs($owner)->get($this->dashUrl('/settings'))->assertOk()->assertSee('name="menu_bilingual"', false);
        $this->actingAs($owner)->post($this->dashUrl('/settings'), self::FORM + ['bilingual_sent' => '1', 'menu_bilingual' => '1'])->assertSessionHasNoErrors();
        $this->as($tenant, fn () => $this->assertSame('1', Settings::get('menu_bilingual')));

        // a save that does not carry the section keeps it
        $this->actingAs($owner)->post($this->dashUrl('/settings'), self::FORM)->assertSessionHasNoErrors();
        $this->as($tenant, fn () => $this->assertSame('1', Settings::get('menu_bilingual')));

        $this->actingAs($owner)->post($this->dashUrl('/settings'), self::FORM + ['bilingual_sent' => '1'])->assertSessionHasNoErrors();
        $this->as($tenant, fn () => $this->assertSame('0', Settings::get('menu_bilingual')));

        $cashier = $this->staff($tenant, 'cashier', 'cashier@cafe-a.test');
        $this->actingAs($cashier)->post($this->dashUrl('/settings'), self::FORM + ['bilingual_sent' => '1', 'menu_bilingual' => '1'])->assertForbidden();
        $this->as($tenant, fn () => $this->assertSame('0', Settings::get('menu_bilingual')));
    }
}
