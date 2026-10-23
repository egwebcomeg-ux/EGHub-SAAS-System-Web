<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\MenuImport;
use App\Models\Tenant;
use App\Services\Import\Ai\AiProviders;
use App\Services\Import\MenuDraft;
use App\Services\Settings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TenantTestCase;

/** The look of an uploaded menu (colours, font, language, logo) offered on the review page and applied with the import. */
class MenuImportBrandTest extends TenantTestCase
{
    private const BRAND = [
        'primary' => '#9f2241', 'secondary' => '72253D', 'background' => '#FDFBE6', 'text' => '#222222',
        'heading_font_style' => 'serif', 'language' => 'en',
        'logo_box' => ['found' => true, 'page' => 1, 'x' => 0.1, 'y' => 0.05, 'w' => 0.5, 'h' => 0.2],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function parsed(Tenant $tenant, ?array $brand, ?string $logo = null): MenuImport
    {
        return $this->as($tenant, function () use ($brand, $logo) {
            $draft = MenuDraft::normalize(['categories' => [['name' => 'Drinks', 'name_en' => 'Drinks', 'items' => [
                ['name' => 'Tea', 'name_en' => 'Tea', 'price' => 20],
            ]]], 'brand' => $brand === null ? null : $brand + ['logo' => $logo]]);

            return MenuImport::query()->create(['source' => 'pdf', 'file' => '[]', 'status' => 'parsed', 'result' => $draft]);
        });
    }

    private function storedLogo(Tenant $tenant): string
    {
        $path = 'tenants/'.$tenant->getTenantKey().'/brand/'.'0b8f2e5c-1d2a-4c3b-9e8f-112233445566.webp';
        Storage::disk('public')->put($path, 'webp');

        return $path;
    }

    private function applyRows(): array
    {
        return ['mode' => 'add', 'rows' => [['keep' => '1', 'category' => 'Drinks', 'name' => 'Tea', 'name_en' => 'Tea', 'price' => '20']]];
    }

    public function test_the_brand_part_of_the_ai_reply_is_checked_strictly(): void
    {
        $draft = MenuDraft::normalize(['categories' => [], 'brand' => [
            'primary' => 'red', 'secondary' => '#12345', 'background' => '#fff', 'text' => '#0A0B0C; background:url(x)',
            'heading_font_style' => 'comic', 'language' => 'fr',
            'logo_box' => ['found' => true, 'page' => 1, 'x' => 1.4, 'y' => 0, 'w' => 0.3, 'h' => 0.3],
        ]]);
        $this->assertSame('#FFFFFF', $draft['brand']['background']); // "#fff" is a colour, expanded
        $this->assertNull($draft['brand']['primary']);
        $this->assertNull($draft['brand']['secondary']);
        $this->assertNull($draft['brand']['text']);
        $this->assertNull($draft['brand']['font_style']);
        $this->assertNull($draft['brand']['language']);
        $this->assertNull($draft['brand']['logo_box']);

        $good = MenuDraft::normalize(['categories' => [], 'brand' => self::BRAND]);
        $this->assertSame('#9F2241', $good['brand']['primary']);
        $this->assertSame('#72253D', $good['brand']['secondary']);
        $this->assertSame('Playfair Display', $good['brand']['font']);
        $this->assertSame(['page' => 1, 'x' => 0.1, 'y' => 0.05, 'w' => 0.5, 'h' => 0.2], $good['brand']['logo_box']);
        // nothing usable, or no brand at all: no style is offered
        $this->assertArrayNotHasKey('brand', MenuDraft::normalize(['categories' => [], 'brand' => ['primary' => 'nope', 'logo_box' => ['found' => false]]]));
        $this->assertArrayNotHasKey('brand', MenuDraft::normalize(['categories' => []]));
        // the schema the providers get asks for it
        $this->assertContains('brand', MenuDraft::schema()['required']);
    }

    public function test_the_review_shows_the_style_and_applying_it_restyles_the_menu(): void
    {
        $a = $this->restaurant('cafe-a');
        $logo = $this->storedLogo($a);
        $import = $this->parsed($a, self::BRAND, $logo);
        $owner = $this->owner($a);
        // a template with its own header colour would hide the brand: applying the style leaves it for classic
        $this->as($a, fn () => Settings::put(['menu_theme' => 'cafe']));

        $this->actingAs($owner)->get($this->dashUrl("/menu/import/{$import->id}"))->assertOk()
            ->assertSee('data-import-brand', false)->assertSee('لقينا شكل البراند بتاعك في الملف')
            ->assertSee('data-brand-swatch="#9F2241"', false)->assertSee('Playfair Display')->assertSee('data-brand-language="en"', false)
            ->assertSee('/storage/'.$logo, false)->assertSee('name="apply_style"', false)->assertDontSee('data-brand-locked', false);

        $this->actingAs($owner)->post($this->dashUrl("/menu/import/{$import->id}/apply"), $this->applyRows() + ['apply_style' => '1'])
            ->assertRedirect(route('dash.menu'));

        $settings = $this->as($a, fn () => Settings::all());
        $this->assertSame('#9F2241', $settings['color_primary']);
        $this->assertSame('#72253D', $settings['color_secondary']);
        $this->assertSame('#FDFBE6', $settings['color_bg']);
        $this->assertSame('Playfair Display', $settings['font']);
        $this->assertSame('classic', $settings['menu_theme']);
        $this->assertSame('0', $settings['item_placeholder']); // no item photos yet: no empty tiles
        $this->assertSame('white', $settings['logo_background']);
        $this->assertSame('en', $settings['default_locale']); // an English-only file
        $this->assertSame($logo, $settings['logo']);          // the restaurant had no logo yet
        $this->assertSame(1, $this->as($a, fn () => AuditLog::query()->where('action', 'branding.from_import')->count()));

        // the guest menu loads the chosen Google font with Cairo for Arabic text
        $this->get($this->subUrl('cafe-a'))->assertOk()
            ->assertSee('family=Playfair+Display:wght@400;600;700;800', false)->assertSee("font-family: 'Playfair Display', 'Cairo'", false);
    }

    public function test_unticked_the_style_is_left_alone_and_the_cut_out_logo_removed(): void
    {
        $a = $this->restaurant('cafe-a');
        $logo = $this->storedLogo($a);
        $import = $this->parsed($a, self::BRAND, $logo);
        $before = $this->as($a, fn () => Settings::all());

        $this->actingAs($this->owner($a))->post($this->dashUrl("/menu/import/{$import->id}/apply"), $this->applyRows())->assertRedirect(route('dash.menu'));

        $after = $this->as($a, fn () => Settings::all());
        foreach (['color_primary', 'color_secondary', 'color_bg', 'font', 'default_locale', 'logo'] as $key) {
            $this->assertSame($before[$key], $after[$key], $key);
        }
        Storage::disk('public')->assertMissing($logo);
    }

    public function test_an_existing_logo_is_only_replaced_when_asked(): void
    {
        $a = $this->restaurant('cafe-a');
        $this->as($a, fn () => Settings::put(['logo' => 'tenants/cafe-a/brand/old.webp']));
        $logo = $this->storedLogo($a);
        $owner = $this->owner($a);

        $first = $this->parsed($a, self::BRAND, $logo);
        $this->actingAs($owner)->get($this->dashUrl("/menu/import/{$first->id}"))->assertSee('name="replace_logo"', false);
        $this->actingAs($owner)->post($this->dashUrl("/menu/import/{$first->id}/apply"), $this->applyRows() + ['apply_style' => '1']);
        $this->assertSame('tenants/cafe-a/brand/old.webp', $this->as($a, fn () => Settings::get('logo')));

        $logo = $this->storedLogo($a);
        $second = $this->parsed($a, self::BRAND, $logo);
        $this->actingAs($owner)->post($this->dashUrl("/menu/import/{$second->id}/apply"), $this->applyRows() + ['apply_style' => '1', 'replace_logo' => '1']);
        $this->assertSame($logo, $this->as($a, fn () => Settings::get('logo')));
    }

    public function test_without_the_branding_feature_the_card_is_disabled_and_nothing_changes(): void
    {
        $a = $this->restaurant('cafe-a', 'starter');
        $import = $this->parsed($a, self::BRAND, $this->storedLogo($a));
        $before = $this->as($a, fn () => Settings::all());
        $owner = $this->owner($a);

        $this->actingAs($owner)->get($this->dashUrl("/menu/import/{$import->id}"))->assertOk()
            ->assertSee('data-import-brand', false)->assertSee('data-brand-locked', false)->assertDontSee('name="apply_style"', false);
        $this->actingAs($owner)->post($this->dashUrl("/menu/import/{$import->id}/apply"), $this->applyRows() + ['apply_style' => '1'])->assertRedirect(route('dash.menu'));

        $after = $this->as($a, fn () => Settings::all());
        foreach (['color_primary', 'font', 'default_locale', 'logo'] as $key) {
            $this->assertSame($before[$key], $after[$key], $key);
        }
    }

    public function test_a_logo_path_of_another_restaurant_is_never_taken(): void
    {
        $a = $this->restaurant('cafe-a');
        $b = $this->restaurant('cafe-b');
        $foreign = $this->storedLogo($b);
        $import = $this->parsed($a, self::BRAND, $foreign);

        $this->assertNull($import->result['brand']['logo']);
        // even written straight into the stored draft
        $this->as($a, fn () => $import->update(['result' => ['brand' => ['primary' => '#9F2241', 'logo' => $foreign]] + $import->result]));
        $this->actingAs($this->owner($a))->post($this->dashUrl("/menu/import/{$import->id}/apply"), $this->applyRows() + ['apply_style' => '1', 'replace_logo' => '1']);

        $this->assertSame('', (string) $this->as($a, fn () => Settings::get('logo')));
        Storage::disk('public')->assertExists($foreign);
    }

    public function test_an_ai_import_cuts_the_logo_out_of_the_photo(): void
    {
        Storage::fake('local');
        $a = $this->restaurant('cafe-a');
        $this->as($a, fn () => Settings::put(['ai_provider' => 'gemini', 'ai_key' => AiProviders::encrypt('gem-key')]));
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode([
            'categories' => [['name' => 'مشروبات', 'name_en' => 'Drinks', 'items' => [
                ['name' => 'شاي', 'name_en' => 'Tea', 'description' => '', 'description_en' => '', 'price' => 20, 'sizes' => [], 'extras' => []],
            ]]],
            'brand' => ['primary' => '#C0392B', 'secondary' => '', 'background' => '', 'text' => '', 'heading_font_style' => 'sans', 'language' => 'both',
                'logo_box' => ['found' => true, 'page' => 1, 'x' => 0.25, 'y' => 0.25, 'w' => 0.5, 'h' => 0.5]],
        ])]]]]]])]);

        // a white page with a red square in the middle (the "logo")
        $page = imagecreatetruecolor(400, 400);
        imagefill($page, 0, 0, imagecolorallocate($page, 255, 255, 255));
        imagefilledrectangle($page, 160, 160, 239, 239, imagecolorallocate($page, 192, 57, 43));
        ob_start();
        imagepng($page);
        $png = (string) ob_get_clean();

        $this->actingAs($this->owner($a))->post($this->dashUrl('/menu/import/ai'), [
            'files' => [UploadedFile::fake()->createWithContent('menu.png', $png)],
        ])->assertRedirect();

        $brand = $this->as($a, fn () => MenuImport::query()->firstOrFail()->result['brand']);
        $this->assertSame('Poppins', $brand['font']);
        $this->assertStringStartsWith('tenants/cafe-a/brand/', $brand['logo']);
        Storage::disk('public')->assertExists($brand['logo']);
        [$w, $h] = getimagesizefromstring(Storage::disk('public')->get($brand['logo']));
        $this->assertLessThan(120, $w); // trimmed to the mark, not the 200 px box
        $this->assertLessThan(120, $h);
    }
}
