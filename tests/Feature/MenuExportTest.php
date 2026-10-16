<?php

namespace Tests\Feature;

use App\Models\MenuImport;
use App\Models\MenuItem;
use App\Models\Tenant;
use App\Services\Import\MenuDraft;
use Illuminate\Http\UploadedFile;
use Tests\TenantTestCase;

/** Menu export in the import layout: the CSV comes back in through the Excel import unchanged. */
class MenuExportTest extends TenantTestCase
{
    private function menu(Tenant $tenant): void
    {
        $latte = $this->item($tenant, 'لاتيه', 65);
        $this->as($tenant, function () use ($latte) {
            $latte->update(['name_en' => 'Latte', 'description' => 'إسبريسو ولبن', 'allergens' => ['dairy']]);
            $size = $latte->optionGroups()->create(['name' => 'الحجم', 'type' => 'single', 'is_required' => true]);
            $size->options()->create(['name' => 'صغير', 'price' => 0]);
            $size->options()->create(['name' => 'كبير', 'price' => 15]);
            $extras = $latte->optionGroups()->create(['name' => 'إضافات', 'type' => 'multiple']);
            $extras->options()->create(['name' => 'شوت زيادة', 'price' => 10]);
        });
        $this->item($tenant, 'تشيز كيك', 90);
    }

    public function test_csv_export_round_trips_through_the_import(): void
    {
        $a = $this->restaurant('cafe-a');
        $owner = $this->owner($a);
        $this->menu($a);

        $res = $this->actingAs($owner)->get($this->dashUrl('/menu/export.csv'))->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $csv = $res->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('الحساسية', $csv);
        $this->assertStringContainsString('"صغير:0 | كبير:15"', $csv);
        $this->assertStringContainsString('dairy', $csv);

        // a second restaurant imports the file and gets the same menu, allergens included
        $b = $this->restaurant('cafe-b');
        $this->actingAs($this->owner($b))->post($this->dashUrl('/menu/import/excel'), ['file' => UploadedFile::fake()->createWithContent('menu.csv', $csv)])->assertRedirect();
        $import = $this->as($b, fn () => MenuImport::query()->firstOrFail());
        $this->assertSame('parsed', $import->status);
        $this->assertSame(['dairy'], $import->result['categories'][0]['items'][0]['allergens']);

        $rows = [];
        foreach ($import->result['categories'] as $c) {
            foreach ($c['items'] as $i) {
                $rows[] = ['keep' => '1', 'category' => $c['name'], 'category_en' => $c['name_en'], 'name' => $i['name'], 'name_en' => $i['name_en'],
                    'price' => $i['price'], 'description' => $i['description'], 'sizes' => MenuDraft::choicesText($i['sizes']),
                    'extras' => MenuDraft::choicesText($i['extras']), 'allergens' => implode(' | ', $i['allergens'])];
            }
        }
        $this->actingAs($this->owner($b))->post($this->dashUrl("/menu/import/{$import->id}/apply"), ['mode' => 'add', 'rows' => $rows])->assertRedirect();

        $latte = $this->as($b, fn () => MenuItem::query()->where('name', 'لاتيه')->with('optionGroups.options')->firstOrFail());
        $this->assertSame(65.0, $latte->price);
        $this->assertSame('Latte', $latte->name_en);
        $this->assertSame(['dairy'], $latte->allergens);
        $this->assertSame(['صغير', 'كبير', 'شوت زيادة'], $latte->optionGroups->flatMap->options->pluck('name')->all());
        $this->assertSame(2, $this->as($b, fn () => MenuItem::query()->count()));
    }

    public function test_xlsx_download_and_permission(): void
    {
        $a = $this->restaurant('cafe-a');
        $this->menu($a);
        $res = $this->actingAs($this->owner($a))->get($this->dashUrl('/menu/export.xlsx'))->assertOk();
        $this->assertStringContainsString('.xlsx', $res->headers->get('content-disposition'));

        $cashier = $this->staff($a, 'cashier', 'c@cafe-a.test');
        $this->actingAs($cashier)->get($this->dashUrl('/menu/export.csv'))->assertForbidden();
        $this->actingAs($this->owner($a))->get($this->dashUrl('/menu/export.pdf'))->assertNotFound();
    }
}
