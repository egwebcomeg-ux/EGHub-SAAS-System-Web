<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchItem;
use App\Models\Category;
use App\Models\ItemOption;
use App\Models\MenuItem;
use App\Services\Settings;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\PendingCommand;
use PDO;
use Tests\TenantTestCase;

/** egmenu:import-legacy-menu: a legacy (Desoky-family) menu.db into an EgMenu restaurant. */
class LegacyMenuImportTest extends TenantTestCase
{
    private string $folder;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->folder = sys_get_temp_dir().'/legacy-menu-test-'.uniqid();
        File::ensureDirectoryExists($this->folder.'/uploads');
        $this->legacyMenu();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->folder);
        parent::tearDown();
    }

    /** A small legacy menu.db: 2 categories, 4 items (one hidden, one with sizes and an add-on), 2 branches. */
    private function legacyMenu(bool $withBranches = true): void
    {
        @unlink($this->folder.'/menu.db');
        $db = new PDO('sqlite:'.$this->folder.'/menu.db');
        $db->exec('CREATE TABLE settings (key TEXT PRIMARY KEY, value TEXT)');
        $db->exec('CREATE TABLE categories (id INTEGER PRIMARY KEY, title_en TEXT, title_ar TEXT, subtitle TEXT, section_key TEXT, subtitle_en TEXT, sort_order INTEGER)');
        $db->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, category_id INTEGER, name_en TEXT, name_ar TEXT, price REAL, status INTEGER, image_url TEXT, is_featured INTEGER, note TEXT, is_suggested INTEGER, note_en TEXT, sort_order INTEGER, badges TEXT, is_sold_out INTEGER)');
        $db->exec('CREATE TABLE item_options (id INTEGER PRIMARY KEY, item_id INTEGER, kind TEXT, name_ar TEXT, name_en TEXT, price REAL, sort_order INTEGER)');
        $db->exec('CREATE TABLE item_pairings (item_id INTEGER, paired_id INTEGER, sort_order INTEGER)');
        $settings = [
            'restaurant_name' => 'Scratch', 'subtitle' => 'COFFEE & MORE', 'currency' => 'ج.م', 'color_primary' => '#28387e',
            'color_secondary' => '#f37021', 'color_bg' => '#fbf7f1', 'phones' => '01000000001 / 01000000002', 'whatsapp' => '201552155265',
            'whatsapp_enabled' => '1', 'service_percent' => '12', 'tax_percent' => '14', 'logo_url' => 'assets/brand/logo.svg',
            'social' => 'https://instagram.com/scratch https://example.com/x', 'footer_text' => 'Scratch your moment', 'scratch_seeded' => '1',
        ];
        $q = $db->prepare('INSERT INTO settings VALUES (?, ?)');
        foreach ($settings as $k => $v) {
            $q->execute([$k, $v]);
        }
        $db->exec("INSERT INTO categories VALUES (1, 'Specialty', 'قهوة مختصة', 'محمصة يومياً', 'section-1', 'Roasted daily', 0), (2, 'Bakery', 'مخبوزات طازجة', '', 'section-2', NULL, 1)");
        $db->exec("INSERT INTO items VALUES
            (1, 1, 'V60', 'في ٦٠', 150, 1, 'uploads/v60.png', 1, 'تقطير يدوي', 0, 'Hand brewed', 0, 'new,signature', 0),
            (2, 1, 'Latte', 'لاتيه', 90, 1, '', 0, '', 1, NULL, 1, 'decaf,unknown', 1),
            (3, 2, 'Croissant', 'كرواسون', 60, 0, NULL, 0, NULL, 0, NULL, 0, '', 0),
            (4, 2, 'Cookie', 'كوكيز', 40, 1, '', 0, '', 0, '', 1, '', 0)");
        $db->exec("INSERT INTO item_options VALUES (1, 2, 'size', 'سينجل', 'Single', 0, 0), (2, 2, 'size', 'دبل', 'Double', 10, 1), (3, 2, 'addon', 'شوت زيادة', 'Extra shot', 15, 0)");
        $db->exec('INSERT INTO item_pairings VALUES (2, 4, 0)');
        if ($withBranches) {
            $db->exec('CREATE TABLE branches (id INTEGER PRIMARY KEY, name_ar TEXT, name_en TEXT, slug TEXT, address TEXT, phones TEXT, status INTEGER, sort_order INTEGER, whatsapp TEXT, whatsapp_enabled INTEGER, lat REAL, lng REAL)');
            $db->exec('CREATE TABLE branch_items (branch_id INTEGER, item_id INTEGER, price REAL, is_available INTEGER, is_sold_out INTEGER)');
            $db->exec("INSERT INTO branches VALUES (1, 'نجف الجامعة', 'Nagaf El Gamaa', 'gamaa', 'شارع الجامعة', '', 1, 1, '', NULL, 30.798121, 30.991247),
                (2, 'الاستاد', 'Stadium', 'stadium', '', '0402222222', 1, 2, '201000000003', NULL, 30.811624, 30.993591)");
            // branch 1: everything as the main menu except a dearer V60; branch 2: no Cookie row (= not sold there), Latte sold out
            $db->exec('INSERT INTO branch_items VALUES (1, 1, 165, 1, 0), (1, 2, NULL, 1, 0), (1, 3, NULL, 1, 0), (1, 4, NULL, 1, 0),
                (2, 1, NULL, 1, 0), (2, 2, NULL, 1, 1), (2, 3, NULL, 1, 0)');
        }
        $db = null;

        $png = imagecreatetruecolor(60, 60);
        ob_start();
        imagepng($png);
        file_put_contents($this->folder.'/uploads/v60.png', ob_get_clean());
        File::ensureDirectoryExists($this->folder.'/assets/brand');
        file_put_contents($this->folder.'/assets/brand/logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');
    }

    private function import(string $tenant, array $options = []): PendingCommand
    {
        return $this->artisan('egmenu:import-legacy-menu', ['path' => $this->folder, 'tenant' => $tenant] + $options);
    }

    public function test_maps_settings_menu_options_and_images(): void
    {
        $a = $this->restaurant('cafe-a', 'business');

        $this->import('cafe-a')->assertSuccessful()
            ->expectsOutputToContain('SVG not accepted');

        $this->as($a, function () {
            $s = Settings::all();
            $this->assertSame(['Scratch', 'Scratch', 'COFFEE & MORE'], [$s['brand_name'], $s['brand_name_en'], $s['tagline']]);
            $this->assertSame(['#28387E', '#F37021', '#FBF7F1'], [$s['color_primary'], $s['color_secondary'], $s['color_bg']]);
            $this->assertSame(['01000000001', '201552155265', '1'], [$s['phone'], $s['whatsapp'], $s['whatsapp_ordering']]);
            $this->assertSame(['12', '14', 'https://instagram.com/scratch'], [$s['service_percent'], $s['tax_percent'], $s['instagram']]);
            $this->assertSame('', $s['logo']); // SVG is reported, not stored

            $specialty = Category::query()->where('name', 'قهوة مختصة')->firstOrFail();
            $this->assertSame(['Specialty', 'محمصة يومياً', 'Roasted daily'], [$specialty->name_en, $specialty->description, $specialty->description_en]);

            $v60 = MenuItem::query()->where('name', 'في ٦٠')->firstOrFail();
            $this->assertSame([150.0, 'V60', 'تقطير يدوي', 'Hand brewed'], [$v60->price, $v60->name_en, $v60->description, $v60->description_en]);
            $this->assertTrue($v60->is_featured);
            $this->assertSame(['new', 'barista_pick'], $v60->badges); // "signature" in a cafe
            $this->assertStringStartsWith('tenants/cafe-a/items/', $v60->image);
            Storage::disk('public')->assertExists($v60->image);

            $latte = MenuItem::query()->where('name', 'لاتيه')->with('optionGroups.options', 'pairings')->firstOrFail();
            $this->assertTrue($latte->is_sold_out);
            $this->assertSame(['decaf'], $latte->badges);
            [$sizes, $extras] = $latte->optionGroups;
            $this->assertSame(['Size', 'single', true], [$sizes->name_en, $sizes->type, $sizes->is_required]);
            $this->assertSame([['سينجل', 0.0, true], ['دبل', 10.0, false]], $sizes->options->map(fn ($o) => [$o->name, $o->price, $o->is_default])->all());
            $this->assertSame(['Extras', 'multiple', false], [$extras->name_en, $extras->type, $extras->is_required]);
            $this->assertSame([['Extra shot', 15.0]], $extras->options->map(fn ($o) => [$o->name_en, $o->price])->all());
            $this->assertSame(['كوكيز'], $latte->pairings->pluck('name')->all());

            $this->assertFalse(MenuItem::query()->where('name', 'كرواسون')->value('is_active')); // status 0 → hidden
        });
    }

    public function test_branches_and_per_branch_prices(): void
    {
        $a = $this->restaurant('cafe-a', 'business');

        $this->import('cafe-a')->assertSuccessful();

        $this->as($a, function () {
            // the starter branch made at sign-up is taken over by the first legacy branch
            $branches = Branch::query()->orderBy('id')->get();
            $this->assertSame(['gamaa', 'stadium'], $branches->pluck('slug')->all());
            [$gamaa, $stadium] = $branches;
            $this->assertSame(['نجف الجامعة', 'Nagaf El Gamaa', 'شارع الجامعة', '01000000001'], [$gamaa->name, $gamaa->name_en, $gamaa->address, $gamaa->phone]);
            $this->assertEqualsWithDelta(30.798121, $gamaa->lat, 0.000001);
            $this->assertSame(['0402222222', '201000000003'], [$stadium->phone, $stadium->whatsapp]);

            $item = fn (string $name) => MenuItem::query()->where('name', $name)->value('id');
            $override = fn (Branch $b, string $name) => BranchItem::query()->where('branch_id', $b->id)->where('menu_item_id', $item($name))->first();
            $this->assertSame(165.0, $override($gamaa, 'في ٦٠')->price);
            $this->assertNull($override($gamaa, 'لاتيه'));          // same as the main menu: no row
            $this->assertTrue($override($stadium, 'لاتيه')->is_sold_out);
            $this->assertFalse($override($stadium, 'كوكيز')->is_available); // no legacy row = not in that branch
            $this->assertSame(3, BranchItem::query()->count());
        });
    }

    public function test_branch_limit_of_the_plan_is_respected(): void
    {
        $a = $this->restaurant('cafe-a', 'pro'); // one branch

        $this->import('cafe-a')->assertSuccessful()->expectsOutputToContain('not created: الاستاد');

        $this->assertSame(['gamaa'], $this->as($a, fn () => Branch::query()->pluck('slug')->all()));
    }

    public function test_running_again_skips_what_exists(): void
    {
        $a = $this->restaurant('cafe-a', 'business');
        $this->import('cafe-a')->assertSuccessful();
        $before = $this->as($a, fn () => [Category::query()->count(), MenuItem::query()->count(), Branch::query()->count(), BranchItem::query()->count(),
            ItemOption::query()->count()]);

        $this->import('cafe-a')->assertSuccessful();

        $after = $this->as($a, fn () => [Category::query()->count(), MenuItem::query()->count(), Branch::query()->count(), BranchItem::query()->count(),
            ItemOption::query()->count()]);
        $this->assertSame($before, $after);
    }

    public function test_replace_clears_only_this_restaurant(): void
    {
        $a = $this->restaurant('cafe-a', 'business');
        $b = $this->restaurant('cafe-b', 'business');
        $this->item($a, 'Old item');
        $this->item($b, 'Neighbour item');

        $this->import('cafe-a', ['--replace' => true])->assertSuccessful();

        $this->assertFalse($this->as($a, fn () => MenuItem::query()->where('name', 'Old item')->exists()));
        $this->assertSame(4, $this->as($a, fn () => MenuItem::query()->count()));
        $this->assertSame(['Neighbour item'], $this->as($b, fn () => MenuItem::query()->pluck('name')->all()));
        $this->assertSame('Restaurant cafe-b', $this->as($b, fn () => Settings::get('brand_name')));
    }

    public function test_dry_run_writes_nothing(): void
    {
        $a = $this->restaurant('cafe-a', 'business');
        $before = $this->as($a, fn () => [Category::query()->count(), Branch::query()->pluck('slug')->all(), Settings::get('brand_name')]);

        $this->import('cafe-a', ['--dry-run' => true, '--replace' => true])->assertSuccessful()->expectsOutputToContain('DRY RUN');

        $this->assertSame($before, $this->as($a, fn () => [Category::query()->count(), Branch::query()->pluck('slug')->all(), Settings::get('brand_name')]));
        $this->assertSame(0, $this->as($a, fn () => MenuItem::query()->count()));
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_images_are_downloaded_from_the_base_url_and_only_images_are_kept(): void
    {
        unlink($this->folder.'/uploads/v60.png');
        (new PDO('sqlite:'.$this->folder.'/menu.db'))->exec("UPDATE items SET image_url = 'uploads/page.jpg' WHERE id = 4");
        $png = imagecreatetruecolor(60, 60);
        ob_start();
        imagepng($png);
        Http::fake([
            'old.example.com/uploads/v60.png' => Http::response(ob_get_clean(), 200, ['Content-Type' => 'image/png']),
            'old.example.com/*' => Http::response('<html>', 200, ['Content-Type' => 'text/html']),
        ]);
        $a = $this->restaurant('cafe-a', 'business');

        $this->import('cafe-a', ['--images-base-url' => 'https://old.example.com/'])->assertSuccessful()
            ->expectsOutputToContain('not an image (text/html)');

        $image = $this->as($a, fn () => MenuItem::query()->where('name', 'في ٦٠')->value('image'));
        Storage::disk('public')->assertExists($image);
        $this->assertNull($this->as($a, fn () => MenuItem::query()->where('name', 'كوكيز')->value('image')));
    }

    public function test_missing_tables_are_read_as_empty_and_unknown_restaurants_refused(): void
    {
        $this->legacyMenu(withBranches: false);
        $a = $this->restaurant('cafe-a', 'business');

        $this->import('cafe-a', ['--no-images' => true])->assertSuccessful();
        $this->import('nope')->assertFailed()->expectsOutputToContain('No restaurant');

        $this->assertSame(4, $this->as($a, fn () => MenuItem::query()->count()));
        $this->assertSame('01000000001', $this->as($a, fn () => Branch::query()->value('phone'))); // the restaurant phone fills the main branch
    }
}
