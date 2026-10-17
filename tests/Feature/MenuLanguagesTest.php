<?php

namespace Tests\Feature;

use App\Billing\Billing;
use App\Jobs\TranslateMenu;
use App\Models\Category;
use App\Models\MenuItem;
use App\Models\MenuTranslation;
use App\Models\Tenant;
use App\Services\Import\Ai\AiException;
use App\Services\Import\Ai\AiProviders;
use App\Services\Menu\MenuTranslator;
use App\Services\Settings;
use App\Support\MenuLocales;
use Tests\TenantTestCase;

class MenuLanguagesTest extends TenantTestCase
{
    /** A Business restaurant with French and German on, and three items: translated, English only, Arabic only. */
    private function menu(string $slug = 'cafe-a', string $plan = 'business'): Tenant
    {
        $tenant = $this->restaurant($slug, $plan);
        $this->as($tenant, function () {
            Settings::put(['menu_locales' => json_encode(['fr', 'de'])]);
            $cat = Category::query()->create(['name' => 'مشروبات', 'name_en' => 'Drinks']);
            $latte = MenuItem::query()->create(['category_id' => $cat->id, 'name' => 'لاتيه', 'name_en' => 'Latte', 'price' => 65,
                'description' => 'قهوة بالحليب', 'description_en' => 'Coffee with milk']);
            MenuItem::query()->create(['category_id' => $cat->id, 'name' => 'موكا', 'name_en' => 'Mocha', 'price' => 70]);
            MenuItem::query()->create(['category_id' => $cat->id, 'name' => 'سحلب', 'name_en' => '', 'price' => 50]);
            MenuTranslation::query()->create(['translatable_type' => 'item', 'translatable_id' => $latte->id, 'locale' => 'fr',
                'name' => 'Café au lait', 'description' => 'Café avec du lait chaud']);
            MenuTranslation::query()->create(['translatable_type' => 'category', 'translatable_id' => $cat->id, 'locale' => 'fr', 'name' => 'Boissons']);
        });

        return $tenant;
    }

    public function test_an_extra_language_shows_its_translation_then_english_then_arabic(): void
    {
        $this->menu();

        $res = $this->get($this->pathUrl('cafe-a', '?lang=fr'))->assertOk()
            ->assertSee('<html lang="fr" dir="ltr">', false)
            ->assertSee('Café au lait')->assertSee('Café avec du lait chaud')->assertSee('Boissons')
            ->assertSee('Mocha')      // no French: English
            ->assertSee('سحلب')       // neither: Arabic
            ->assertSee('Français')   // the language menu lists it
            ->assertCookie('lang_cafe-a', 'fr');
        $this->assertStringContainsString('?lang=fr', (string) $res->getContent()); // canonical keeps the language
        $data = json_decode((string) str($res->getContent())->betweenFirst('<script type="application/json" id="menu-data">', '</script>'), true);
        $latte = collect($data['items'])->firstWhere('price', 65);
        $this->assertSame('Café au lait', $latte['name']);
        $this->assertSame('Café avec du lait chaud', $latte['description']);

        // the cookie remembers it on the next visit; the profile page follows
        $this->withCookie('lang_cafe-a', 'fr')->get($this->pathUrl('cafe-a', 'about'))->assertOk()->assertSee('<html lang="fr" dir="ltr">', false);
        // German is on but untranslated: English for everything
        $this->get($this->pathUrl('cafe-a', '?lang=de'))->assertOk()->assertSee('Latte')->assertDontSee('Café au lait');
        // back to Arabic
        $this->get($this->pathUrl('cafe-a', '?lang=ar'))->assertOk()->assertSee('<html lang="ar" dir="rtl">', false)->assertSee('لاتيه')->assertDontSee('Café au lait');
    }

    public function test_unknown_or_disabled_languages_are_ignored(): void
    {
        $this->menu();

        foreach (['xx', 'it', '../en', 'FR'] as $lang) {
            $this->get($this->pathUrl('cafe-a', '?lang='.$lang))->assertOk()
                ->assertSee('<html lang="ar" dir="rtl">', false)->assertDontSee('Café au lait')->assertCookieMissing('lang_cafe-a');
        }
        // a language switched off later is ignored even when remembered in the cookie
        $this->as(Tenant::find('cafe-a'), fn () => Settings::put(['menu_locales' => json_encode(['de'])]));
        $this->withCookie('lang_cafe-a', 'fr')->get($this->pathUrl('cafe-a'))->assertOk()
            ->assertSee('<html lang="ar" dir="rtl">', false)->assertDontSee('Café au lait');
    }

    public function test_extra_languages_need_the_feature_or_the_add_on(): void
    {
        $pro = $this->menu('cafe-a', 'pro');
        $owner = $this->owner($pro);

        $this->get($this->pathUrl('cafe-a', '?lang=fr'))->assertOk()->assertSee('<html lang="ar" dir="rtl">', false)->assertDontSee('Café au lait')->assertDontSee('Français');
        $this->actingAs($owner)->get($this->dashUrl('/settings'))->assertOk()->assertDontSee('name="menu_locales[]"', false);
        $this->actingAs($owner)->post($this->dashUrl('/menu/translate'))->assertForbidden();
        $this->actingAs($owner)->get($this->dashUrl('/menu/items/'.$this->as($pro, fn () => MenuItem::query()->first()->id)))
            ->assertOk()->assertDontSee('tr[fr][name]', false);

        app(Billing::class)->setAddon($pro, 'languages_pack', 1);
        $this->get($this->pathUrl('cafe-a', '?lang=fr'))->assertOk()->assertSee('Café au lait');
        $this->actingAs($owner)->get($this->dashUrl('/settings'))->assertOk()->assertSee('name="menu_locales[]"', false);
        $this->actingAs($owner)->get($this->dashUrl('/menu/items/'.$this->as($pro, fn () => MenuItem::query()->first()->id)))
            ->assertOk()->assertSee('tr[fr][name]', false)->assertSee('Café au lait');
    }

    public function test_another_restaurants_translations_never_show(): void
    {
        $a = $this->menu('cafe-a');
        $mochaId = $this->as($a, fn () => MenuItem::query()->where('name', 'موكا')->value('id'));
        // cafe-b writes rows that point at cafe-a's item id
        $b = $this->restaurant('cafe-b', 'business');
        $this->as($b, function () use ($mochaId) {
            Settings::put(['menu_locales' => json_encode(['fr'])]);
            MenuTranslation::query()->create(['translatable_type' => 'item', 'translatable_id' => $mochaId, 'locale' => 'fr', 'name' => 'Moka volé']);
        });

        $this->get($this->pathUrl('cafe-a', '?lang=fr'))->assertOk()->assertSee('Mocha')->assertDontSee('Moka volé');
        $this->actingAs($this->owner($a))->get($this->dashUrl('/menu/items/'.$mochaId))->assertOk()->assertDontSee('Moka volé');
        $this->actingAs($this->owner($b))->get($this->dashUrl('/menu/items/'.$mochaId))->assertNotFound();
    }

    public function test_the_dashboard_saves_translations_and_keeps_option_translations_when_an_item_is_saved_again(): void
    {
        $a = $this->menu();
        $owner = $this->owner($a);
        $cat = $this->as($a, fn () => Category::query()->first());
        $form = fn (array $tr) => [
            'category_id' => $cat->id, 'name' => 'شاي', 'name_en' => 'Tea', 'price' => 30, 'is_active' => '1', 'tr' => $tr,
            'groups' => [['name' => 'الحجم', 'type' => 'single', 'options' => [['name' => 'صغير'], ['name' => 'كبير', 'price' => 5]]]],
        ];
        $this->actingAs($owner)->post($this->dashUrl('/menu/items'), $form(['fr' => ['name' => 'Thé', 'description' => ''], 'de' => ['name' => '', 'description' => '']]))
            ->assertSessionHasNoErrors();
        $tea = $this->as($a, fn () => MenuItem::query()->where('name', 'شاي')->with('optionGroups.options')->first());
        $this->as($a, function () use ($tea) {
            MenuTranslation::query()->create(['translatable_type' => 'option', 'translatable_id' => $tea->optionGroups[0]->options[1]->id, 'locale' => 'fr', 'name' => 'Grand']);
            $this->assertSame(['fr'], MenuTranslation::query()->where('translatable_type', 'item')->where('translatable_id', $tea->id)->pluck('locale')->all());
        });

        // saved again: the option rows are re-created, their translation follows the same Arabic name
        $this->actingAs($owner)->post($this->dashUrl('/menu/items/'.$tea->id), $form(['fr' => ['name' => 'Thé noir', 'description' => 'Thé de Ceylan']]))->assertSessionHasNoErrors();
        $this->as($a, function () use ($tea) {
            $large = MenuItem::query()->find($tea->id)->optionGroups()->first()->options()->where('name', 'كبير')->first();
            $this->assertSame('Grand', MenuTranslation::query()->where('translatable_type', 'option')->where('translatable_id', $large->id)->value('name'));
            $this->assertSame(1, MenuTranslation::query()->where('translatable_type', 'option')->count());
            $this->assertSame('Thé de Ceylan', MenuTranslation::query()->where('translatable_type', 'item')->where('translatable_id', $tea->id)->value('description'));
        });
        $res = $this->get($this->pathUrl('cafe-a', '?lang=fr'))->assertOk()->assertSee('Thé noir');
        $this->assertStringContainsString('"Grand"', (string) $res->getContent());
    }

    public function test_the_ai_job_fills_only_empty_fields_and_runs_again_safely(): void
    {
        $a = $this->menu();
        $calls = [];
        $this->app->instance(MenuTranslator::class, new class($calls) extends MenuTranslator
        {
            public function __construct(private array &$calls) {}

            public function translate(array $rows, string $locale): array
            {
                $this->calls[] = [$locale, array_keys($rows)];

                return collect($rows)->map(fn ($r) => ['name' => strtoupper($locale).' '.($r['name_en'] ?: $r['name']), 'description' => $r['description'] !== '' ? strtoupper($locale).' desc' : ''])->all();
            }
        });
        $this->as($a, function () {
            Settings::put(['ai_provider' => 'openai', 'ai_key' => AiProviders::encrypt('sk-test-key')]);
            $latte = MenuItem::query()->where('name', 'لاتيه')->first();
            // hand-written French name, description still empty
            MenuTranslation::query()->where('translatable_id', $latte->id)->where('translatable_type', 'item')->update(['description' => null]);
        });

        $this->actingAs($this->owner($a))->post($this->dashUrl('/menu/translate'))->assertSessionHasNoErrors()->assertSessionHas('ok');

        $this->as($a, function () {
            $latte = MenuItem::query()->where('name', 'لاتيه')->first();
            $fr = MenuTranslation::query()->where('translatable_type', 'item')->where('translatable_id', $latte->id)->where('locale', 'fr')->first();
            $this->assertSame('Café au lait', $fr->name);           // never overwritten
            $this->assertSame('FR desc', $fr->description);         // empty field filled
            $mocha = MenuItem::query()->where('name', 'موكا')->first();
            $this->assertSame('FR Mocha', MenuTranslation::query()->where('translatable_type', 'item')->where('translatable_id', $mocha->id)->where('locale', 'fr')->value('name'));
            $this->assertSame('DE Latte', MenuTranslation::query()->where('translatable_type', 'item')->where('translatable_id', $latte->id)->where('locale', 'de')->value('name'));
            $this->assertNull(MenuTranslation::query()->where('translatable_type', 'item')->where('translatable_id', $mocha->id)->where('locale', 'de')->value('description'));
            $this->assertSame('Boissons', MenuTranslation::query()->where('translatable_type', 'category')->where('locale', 'fr')->value('name'));
            $status = TranslateMenu::status();
            $this->assertSame('done', $status['state']);
            $this->assertSame($status['total'], $status['done']);
        });
        $this->assertNotEmpty($calls);

        // a second run has nothing left to do
        $calls = [];
        $this->actingAs($this->owner($a))->post($this->dashUrl('/menu/translate'))->assertSessionHas('ok');
        $this->assertSame([], $calls);
        $this->as($a, fn () => $this->assertSame(0, TranslateMenu::status()['total']));
    }

    public function test_the_ai_translation_needs_a_key_and_reports_errors(): void
    {
        $a = $this->menu();
        $this->actingAs($this->owner($a))->post($this->dashUrl('/menu/translate'))->assertSessionHasErrors('translate');

        $this->app->instance(MenuTranslator::class, new class extends MenuTranslator
        {
            public function translate(array $rows, string $locale): array
            {
                throw new AiException('Key refused');
            }
        });
        $this->as($a, fn () => Settings::put(['ai_provider' => 'openai', 'ai_key' => AiProviders::encrypt('sk-test-key')]));
        $this->actingAs($this->owner($a))->post($this->dashUrl('/menu/translate'))->assertSessionHasNoErrors();
        $this->as($a, fn () => $this->assertSame(['failed', 'Key refused'], [TranslateMenu::status()['state'], TranslateMenu::status()['error']]));
        $this->actingAs($this->owner($a))->get($this->dashUrl('/menu'))->assertOk()->assertSee('Key refused');
        $this->actingAs($this->owner($a))->getJson($this->dashUrl('/menu/translate/status'))->assertOk()->assertJson(['state' => 'failed']);
    }

    public function test_the_printed_menu_can_use_an_extra_language(): void
    {
        $a = $this->menu();
        $this->actingAs($this->owner($a))->get($this->dashUrl('/menu/print?lang=ar-fr'))->assertOk()
            ->assertSee('لاتيه')->assertSee('Café au lait');
        $this->actingAs($this->owner($a))->get($this->dashUrl('/menu/print?lang=fr'))->assertOk()
            ->assertSee('<html lang="fr" dir="ltr">', false)->assertSee('Café au lait')->assertSee('Mocha');
        $this->actingAs($this->owner($a))->get($this->dashUrl('/menu/print?lang=it'))->assertSessionHasErrors('lang');
    }

    public function test_settings_save_the_chosen_languages(): void
    {
        $a = $this->restaurant('cafe-a', 'business');
        $this->actingAs($this->owner($a))->post($this->dashUrl('/settings'), [
            'brand_name' => 'X', 'currency' => 'ج.م', 'currency_en' => 'EGP', 'color_primary' => '#F26522', 'color_secondary' => '#0A2540',
            'color_bg' => '#FFFFFF', 'font' => 'Cairo', 'default_locale' => 'ar', 'menu_locales_sent' => '1', 'menu_locales' => ['zh', 'fr'],
        ])->assertSessionHasNoErrors();
        $this->as($a, fn () => $this->assertSame(['fr', 'zh'], MenuLocales::parse(Settings::get('menu_locales'))));
        $this->get($this->pathUrl('cafe-a', '?lang=zh'))->assertOk()->assertSee('<html lang="zh" dir="ltr">', false)->assertSee('中文');
    }
}
