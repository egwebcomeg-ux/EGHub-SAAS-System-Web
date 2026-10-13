<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\MenuImport;
use App\Models\MenuItem;
use App\Models\Plan;
use App\Models\TenantSetting;
use App\Services\Entitlements;
use App\Services\Import\Ai\AiProviders;
use App\Services\Import\MenuDraft;
use App\Services\Settings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TenantTestCase;

/** Menu import from a spreadsheet, and from photos with the restaurant's own AI key. */
class MenuImportTest extends TenantTestCase
{
    private function csv(string $body): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('menu.csv', "\xEF\xBB\xBF" . $body);
    }

    private const SHEET = "القسم,القسم (إنجليزي),الصنف,الصنف (إنجليزي),السعر,الوصف,الأحجام,الإضافات\n"
        . "قهوة ساخنة,Hot Coffee,لاتيه,Latte,٦٥ ج.م,,صغير:0 | كبير:15,شوت زيادة:10\n"
        . ",,كابتشينو,Cappuccino,65,,,\n"
        . "حلويات,Desserts,تشيز كيك,Cheesecake,90,كيك بالجبنة,,\n";

    /** Rows of the review form, as the browser would post them back unchanged. */
    private function rowsOf(MenuImport $import): array
    {
        $rows = [];
        foreach ($import->result['categories'] as $c) {
            foreach ($c['items'] as $i) {
                $rows[] = ['keep' => '1', 'category' => $c['name'], 'category_en' => $c['name_en'], 'name' => $i['name'], 'name_en' => $i['name_en'],
                    'price' => $i['price'], 'description' => $i['description'], 'sizes' => MenuDraft::choicesText($i['sizes']), 'extras' => MenuDraft::choicesText($i['extras'])];
            }
        }
        return $rows;
    }

    public function test_template_downloads_as_xlsx(): void
    {
        $a = $this->restaurant('cafe-a');
        $res = $this->actingAs($this->owner($a))->get($this->dashUrl('/menu/import/template'))->assertOk();
        $this->assertStringContainsString('.xlsx', $res->headers->get('content-disposition'));
    }

    public function test_sheet_is_reviewed_then_added_with_sizes_and_extras(): void
    {
        $a = $this->restaurant('cafe-a');
        $owner = $this->owner($a);

        $this->actingAs($owner)->post($this->dashUrl('/menu/import/excel'), ['file' => $this->csv(self::SHEET)])->assertRedirect();
        $import = $this->as($a, fn () => MenuImport::query()->firstOrFail());
        $this->assertSame('parsed', $import->status);
        $this->assertSame(0, $this->as($a, fn () => MenuItem::query()->count())); // nothing added before review

        $this->actingAs($owner)->get($this->dashUrl("/menu/import/{$import->id}"))->assertOk()->assertSee('كابتشينو');
        $rows = $this->rowsOf($import);
        $rows[2]['keep'] = '';                                                  // owner unticks the cheesecake
        $this->actingAs($owner)->post($this->dashUrl("/menu/import/{$import->id}/apply"), ['mode' => 'add', 'rows' => $rows])
            ->assertRedirect(route('dash.menu'));

        $latte = $this->as($a, fn () => MenuItem::query()->where('name', 'لاتيه')->with('optionGroups.options', 'category')->first());
        $this->assertSame(65.0, $latte->price);                                // "٦٥ ج.م" → 65
        $this->assertSame('Hot Coffee', $latte->category->name_en);
        $this->assertSame(['الحجم', 'إضافات'], $latte->optionGroups->pluck('name')->all());
        $this->assertSame(15.0, $latte->optionGroups[0]->options[1]->price);
        $this->assertSame(2, $this->as($a, fn () => MenuItem::query()->count()));
        $this->assertSame('applied', $import->fresh()->status);
    }

    public function test_update_mode_changes_prices_without_duplicates(): void
    {
        $a = $this->restaurant('cafe-a');
        $owner = $this->owner($a);
        $this->as($a, function () {
            $cat = Category::query()->create(['name' => 'قهوة ساخنة']);
            MenuItem::query()->create(['category_id' => $cat->id, 'name' => 'لاتيه', 'price' => 50]);
        });
        $this->actingAs($owner)->post($this->dashUrl('/menu/import/excel'), ['file' => $this->csv(self::SHEET)]);
        $import = $this->as($a, fn () => MenuImport::query()->firstOrFail());
        $this->actingAs($owner)->post($this->dashUrl("/menu/import/{$import->id}/apply"), ['mode' => 'update', 'rows' => $this->rowsOf($import)])
            ->assertSessionHas('ok');

        $this->assertSame(1, $this->as($a, fn () => MenuItem::query()->where('name', 'لاتيه')->count()));
        $this->assertSame(65.0, $this->as($a, fn () => MenuItem::query()->where('name', 'لاتيه')->value('price') + 0.0));
        $this->assertSame(3, $this->as($a, fn () => MenuItem::query()->count()));
    }

    public function test_item_limit_blocks_the_whole_import(): void
    {
        $a = $this->restaurant('cafe-a', 'basic');
        Plan::query()->where('code', 'basic')->first()->features()->where('key', 'menu_items')->update(['value' => 2]);
        Entitlements::flush();
        $owner = $this->owner($a);
        $this->actingAs($owner)->post($this->dashUrl('/menu/import/excel'), ['file' => $this->csv(self::SHEET)]);
        $import = $this->as($a, fn () => MenuImport::query()->firstOrFail());

        $this->actingAs($owner)->post($this->dashUrl("/menu/import/{$import->id}/apply"), ['mode' => 'add', 'rows' => $this->rowsOf($import)])
            ->assertSessionHasErrors('apply');
        $this->assertSame(0, $this->as($a, fn () => MenuItem::query()->count()));
    }

    public function test_invisible_marks_from_arabic_sheets_are_ignored(): void
    {
        $a = $this->restaurant('cafe-a');
        $bom = "\u{FEFF}";
        $rlm = "\u{200F}";
        $sheet = "{$bom}القسم,الصنف,السعر\n{$bom}سندوتشات,كلوب,120\n{$bom},{$rlm}تونة{$rlm},95\n";
        $this->actingAs($this->owner($a))->post($this->dashUrl('/menu/import/excel'), ['file' => $this->csv($sheet)]);
        $draft = $this->as($a, fn () => MenuImport::query()->firstOrFail()->result);

        $this->assertCount(1, $draft['categories']);
        $this->assertSame('سندوتشات', $draft['categories'][0]['name']);
        $this->assertSame(['كلوب', 'تونة'], array_column($draft['categories'][0]['items'], 'name'));
    }

    public function test_bad_sheets_are_explained(): void
    {
        $a = $this->restaurant('cafe-a');
        $owner = $this->owner($a);
        $this->actingAs($owner)->post($this->dashUrl('/menu/import/excel'), ['file' => $this->csv("foo,bar\n1,2\n")])->assertSessionHasErrors('file');
        $this->actingAs($owner)->post($this->dashUrl('/menu/import/excel'), ['file' => UploadedFile::fake()->create('menu.docx', 10)])->assertSessionHasErrors('file');
    }

    public function test_ai_key_is_encrypted_masked_and_needed(): void
    {
        $a = $this->restaurant('cafe-a');
        $owner = $this->owner($a);
        $this->actingAs($owner)->post($this->dashUrl('/menu/import/ai'), ['files' => [UploadedFile::fake()->image('m.jpg')]])->assertSessionHasErrors('files');

        $this->actingAs($owner)->post($this->dashUrl('/settings'), [
            'brand_name' => 'X', 'currency' => 'ج.م', 'currency_en' => 'EGP', 'color_primary' => '#111111', 'color_secondary' => '#222222',
            'color_bg' => '#FFFFFF', 'font' => 'Cairo', 'default_locale' => 'ar', 'ai_provider' => 'gemini', 'ai_key' => 'AIzaSECRETKEY12345',
        ])->assertSessionHasNoErrors();

        $stored = $this->as($a, fn () => TenantSetting::query()->where('key', 'ai_key')->value('value'));
        $this->assertStringNotContainsString('SECRET', $stored);
        $this->assertSame('AIza…2345', $this->as($a, fn () => AiProviders::masked()));
        $this->actingAs($owner)->get($this->dashUrl('/settings'))->assertDontSee('AIzaSECRETKEY12345');
    }

    public function test_photos_are_read_with_the_restaurants_gemini_key(): void
    {
        Storage::fake('local');
        $a = $this->restaurant('cafe-a');
        $this->as($a, fn () => Settings::put(['ai_provider' => 'gemini', 'ai_key' => AiProviders::encrypt('gem-key')]));
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode([
            'categories' => [['name' => 'مشروبات', 'name_en' => 'Drinks', 'items' => [
                ['name' => 'شاي', 'name_en' => 'Tea', 'description' => '', 'description_en' => '', 'price' => '٢٠', 'sizes' => [], 'extras' => []],
            ]]],
        ])]]]]]])]);

        $this->actingAs($this->owner($a))->post($this->dashUrl('/menu/import/ai'), [
            'files' => [UploadedFile::fake()->image('page1.jpg', 800, 1200), UploadedFile::fake()->image('page2.jpg', 800, 1200)],
        ])->assertRedirect();

        $import = $this->as($a, fn () => MenuImport::query()->firstOrFail()); // queue is sync in tests: already read
        $this->assertSame('parsed', $import->status);
        $this->assertEquals(20, $import->result['categories'][0]['items'][0]['price']);
        Http::assertSent(fn ($req) => $req->hasHeader('x-goog-api-key', 'gem-key') && count($req['contents'][0]['parts']) === 3);
        $this->assertSame([], Storage::disk('local')->allFiles('tenants/cafe-a/imports')); // uploads removed
    }

    public function test_provider_errors_become_clear_messages(): void
    {
        Storage::fake('local');
        $a = $this->restaurant('cafe-a');
        $this->as($a, fn () => Settings::put(['ai_provider' => 'openai', 'ai_key' => AiProviders::encrypt('sk-bad')]));
        Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'bad key']], 401)]);

        $this->actingAs($this->owner($a))->post($this->dashUrl('/menu/import/ai'), ['files' => [UploadedFile::fake()->image('m.jpg')]]);
        $import = $this->as($a, fn () => MenuImport::query()->firstOrFail());
        $this->assertSame('failed', $import->status);
        $this->assertStringContainsString('OpenAI', $import->error);

        $this->actingAs($this->owner($a))->post($this->dashUrl('/settings/ai-test'))->assertSessionHasErrors('ai_key');
    }

    public function test_ai_import_is_a_plan_feature_with_a_monthly_limit(): void
    {
        $basic = $this->restaurant('cafe-basic', 'basic');
        $this->actingAs($this->owner($basic))->post($this->dashUrl('/menu/import/ai'), ['files' => [UploadedFile::fake()->image('m.jpg')]])->assertForbidden();

        Storage::fake('local');
        $pro = $this->restaurant('cafe-pro', 'pro');
        Plan::query()->where('code', 'pro')->first()->features()->where('key', 'ai_imports_per_month')->update(['value' => 1]);
        Entitlements::flush();
        $this->as($pro, fn () => Settings::put(['ai_provider' => 'gemini', 'ai_key' => AiProviders::encrypt('k')]));
        Http::fake(['*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => '{"categories":[]}']]]]]])]);
        $owner = $this->owner($pro);
        $this->actingAs($owner)->post($this->dashUrl('/menu/import/ai'), ['files' => [UploadedFile::fake()->image('m.jpg')]])->assertRedirect();
        $this->actingAs($owner)->post($this->dashUrl('/menu/import/ai'), ['files' => [UploadedFile::fake()->image('m.jpg')]])->assertSessionHasErrors('files');
    }

    public function test_imports_of_another_restaurant_are_not_reachable(): void
    {
        $a = $this->restaurant('cafe-a');
        $b = $this->restaurant('cafe-b');
        $this->actingAs($this->owner($b))->post($this->dashUrl('/menu/import/excel'), ['file' => $this->csv(self::SHEET)]);
        $importOfB = $this->as($b, fn () => MenuImport::query()->firstOrFail());

        $this->actingAs($this->owner($a))->get($this->dashUrl("/menu/import/{$importOfB->id}"))->assertNotFound();
        $this->actingAs($this->owner($a))->post($this->dashUrl("/menu/import/{$importOfB->id}/apply"), ['mode' => 'add', 'rows' => [['keep' => 1, 'name' => 'x']]])->assertNotFound();
    }
}
