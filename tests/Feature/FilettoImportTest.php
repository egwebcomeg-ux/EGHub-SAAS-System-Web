<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\DeliveryZone;
use App\Models\DiningTable;
use App\Models\KbArticle;
use App\Models\MenuItem;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Settings;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\PendingCommand;
use PDO;
use Tests\TenantTestCase;

/** egmenu:import-filetto: Filetto BBQ's old ordering site into the restaurant `filetto`. */
class FilettoImportTest extends TenantTestCase
{
    private string $folder;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->folder = sys_get_temp_dir().'/filetto-import-test-'.uniqid();
        File::ensureDirectoryExists($this->folder.'/assets/uploads/menu');
        $this->oldDatabase();
        $this->prices([['type' => 'variation', 'source_id' => 2, 'new_price' => 110], ['type' => 'item', 'source_id' => 11, 'new_price' => 130],
            ['type' => 'new_item', 'key' => 'tagine_okra_plain', 'new_price' => 130], ['type' => 'new_item', 'key' => 'sand_smoked_sojok', 'new_price' => 125]]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->folder);
        parent::tearDown();
    }

    /**
     * A small copy of the old schema: kofta with two sizes (75 / 98) and a combo, a hawawshi (95), the grilled sojok
     * sandwich (41, with a combo: the smoked one is added after it), the merged plain tagine (60, split by the owner),
     * the logo, two delivery zones (area + street), a coupon (not carried over), a knowledge-base answer, and guests
     * whose mobiles repeat in other forms, one invalid and one test row.
     */
    private function oldDatabase(): void
    {
        $db = new PDO('sqlite:'.$this->folder.'/database.sqlite');
        $db->exec('CREATE TABLE settings (id INTEGER PRIMARY KEY, key TEXT, value TEXT)');
        $db->exec('CREATE TABLE categories (id INTEGER PRIMARY KEY, slug TEXT, name_ar TEXT, name_en TEXT, sort_order INTEGER)');
        $db->exec('CREATE TABLE menu_items (id INTEGER PRIMARY KEY, slug TEXT, category_id INTEGER, name TEXT, english_name TEXT, description TEXT, base_price NUMERIC, image TEXT, badge TEXT, has_sizes INTEGER, allow_combo INTEGER, is_available INTEGER, suggested_extras TEXT)');
        $db->exec('CREATE TABLE menu_variations (id INTEGER PRIMARY KEY, menu_item_id INTEGER, slug TEXT, name TEXT, price NUMERIC, sort_order INTEGER, image TEXT)');
        $db->exec('CREATE TABLE extras (id INTEGER PRIMARY KEY, slug TEXT, name TEXT, price NUMERIC, category TEXT)');
        $db->exec('CREATE TABLE delivery_zones (id INTEGER PRIMARY KEY, slug TEXT, name TEXT, fee NUMERIC, estimated_minutes INTEGER, min_order NUMERIC, is_active INTEGER, parent_id INTEGER, polygon TEXT, color TEXT, sort_order INTEGER)');
        $db->exec('CREATE TABLE coupons (id INTEGER PRIMARY KEY, code TEXT, title TEXT, discount_type TEXT, discount_value NUMERIC, min_order_amount NUMERIC, max_discount_amount NUMERIC, expires_at TEXT, is_active INTEGER)');
        $db->exec('CREATE TABLE kb_entries (id INTEGER PRIMARY KEY, category TEXT, question TEXT, answer TEXT, tags TEXT, sort_order INTEGER, is_active INTEGER)');
        $db->exec('CREATE TABLE customers (id INTEGER PRIMARY KEY, phone TEXT, name TEXT, address_text TEXT, notes TEXT, created_at TEXT, addresses TEXT, phone_2 TEXT)');

        $q = $db->prepare('INSERT INTO settings (key, value) VALUES (?, ?)');
        foreach (['color_primary' => '#dc2626', 'color_background' => '#0d0d10', 'brand_tagline' => 'مشويات ولحوم بلدي فاخرة', 'logo_url' => 'uploads/menu/logo.png',
            'combo_price' => '55', 'combo_drinks' => '["في كولا","عصير"]', 'store_open_time' => '12:00', 'store_close_time' => '02:00'] as $k => $v) {
            $q->execute([$k, $v]);
        }
        $db->exec("INSERT INTO categories VALUES (1, 'sandwiches', 'سندوتشات', 'Sandwiches', 0)");
        $db->exec("INSERT INTO menu_items VALUES
            (10, 'sand_kofta', 1, 'كفتة', 'Kofta Sandwich', NULL, 75, 'uploads/menu/kofta.png', NULL, 1, 1, 1, '[\"sauce_cheddar\",\"drink_can\"]'),
            (11, 'sand_hawawshi', 1, 'حواوشي', 'Hawawshi', NULL, 95, NULL, 'جديد', 0, 0, 1, '[]'),
            (41, 'sand_sojok', 1, 'سجق', 'Sojok', NULL, 95, NULL, NULL, 0, 1, 0, '[\"drink_can\"]'),
            (60, 'cass_vegetable_plain', 1, 'طاجن خضار سادة', 'Vegetable Tagine', NULL, 119, NULL, 'جديد', 0, 0, 1, '[]')");
        $db->exec("INSERT INTO menu_variations VALUES (1, 10, 'v1', 'سنجل', 75, 0, NULL), (2, 10, 'v2', 'دبل', 98, 1, NULL)");
        $db->exec("INSERT INTO extras VALUES (1, 'sauce_cheddar', 'صوص شيدر', 25, 'sauce'), (2, 'drink_can', 'كانز', 25, 'drink')");
        $db->exec("INSERT INTO delivery_zones VALUES (1, 'zone_a', 'A — المركز', 30, 30, 80, 1, NULL, '[[30.7,30.9],[30.7,31.1],[30.8,31.1]]', '#22c55e', 1),
            (2, 'zone_a_bahr', 'شارع البحر', NULL, NULL, NULL, 1, 1, NULL, '#22c55e', 1)");
        $db->exec("INSERT INTO coupons VALUES (1, 'filetto15', 'خصم 15%', 'percentage', 15, 200, 75, NULL, 1)");
        $db->exec("INSERT INTO kb_entries VALUES (1, 'الأصناف', 'إيه هو الحواوشي؟', 'عيش بلدي محشي لحمة.', 'حواوشي', 1, 1)");
        $db->exec("INSERT INTO customers VALUES
            (1, '01012345678', 'أحمد', 'طنطا شارع البحر', NULL, '2026-09-27 18:18:47', NULL, NULL),
            (2, '+201012345678', 'أحمد تاني', NULL, NULL, '2026-09-27 18:18:47', NULL, NULL),
            (3, '1198765432', 'منى', NULL, NULL, '2026-09-27 18:18:47', '[{\"street\":\"شارع البحر\",\"building\":\"5\",\"delivery_zone_id\":2}]', NULL),
            (4, '12345', 'رقم غلط', NULL, NULL, NULL, NULL, NULL),
            (5, '01099998877', 'عميل', NULL, NULL, NULL, NULL, NULL)");
        $db = null;

        foreach (['logo.png', 'kofta.png'] as $name) {
            $png = imagecreatetruecolor(40, 40);
            imagepng($png, $this->folder.'/assets/uploads/menu/'.$name);
        }
    }

    /** @param  list<array{type:string, source_id?:int, key?:string, new_price:float|int}>  $targets */
    private function prices(array $targets): void
    {
        $rows = array_map(fn (array $t) => ['new_price' => $t['new_price'], 'target' => array_intersect_key($t, array_flip(['type', 'source_id', 'key']))], $targets);
        file_put_contents($this->folder.'/prices.json', json_encode(['rows' => [...$rows, ['pdf_name' => 'not on the menu', 'new_price' => 125, 'target' => null]]]));
    }

    private function import(array $options = []): PendingCommand
    {
        return $this->artisan('egmenu:import-filetto', [
            '--source' => $this->folder.'/database.sqlite', '--assets' => $this->folder.'/assets', '--prices' => $this->folder.'/prices.json',
        ] + $options);
    }

    private function filetto(): Tenant
    {
        return Tenant::query()->findOrFail('filetto');
    }

    public function test_creates_the_restaurant_on_a_complimentary_enterprise_plan_with_its_brand_menu_and_new_prices(): void
    {
        $this->import()->assertSuccessful()->expectsOutputToContain('Temporary password');

        $tenant = $this->filetto();
        $this->assertSame('Filetto BBQ', $tenant->name);
        $sub = Subscription::query()->where('tenant_id', 'filetto')->with('plan')->firstOrFail();
        $this->assertSame(['enterprise', 'active', null, null], [$sub->plan->code, $sub->status, $sub->current_period_end, $sub->trial_ends_at]);
        $owner = User::query()->withoutGlobalScopes()->where('tenant_id', 'filetto')->firstOrFail();
        $this->assertSame(['filetto@eghub.app', true], [$owner->email, $owner->hasRole('owner')]);

        $this->as($tenant, function () {
            $s = Settings::all();
            $this->assertSame(['#DC2626', '#0D0D10', 'dark', 'ar', 'Cairo'], [$s['color_primary'], $s['color_secondary'], $s['menu_theme'], $s['default_locale'], $s['font']]);
            $this->assertSame(['01022228334', 'https://www.facebook.com/Filetto.BBQ', 'https://www.instagram.com/filetto_bbq', 'https://www.tiktok.com/@filetto_egy'],
                [$s['phone'], $s['facebook'], $s['instagram'], $s['tiktok']]);
            $this->assertStringStartsWith('tenants/filetto/brand/', $s['logo']);
            $this->assertSame(['سندوتشات'], Category::query()->pluck('name')->all()); // the starter categories went

            $kofta = MenuItem::query()->where('name', 'كفتة')->with('optionGroups.options')->firstOrFail();
            // the single keeps 75, the double's new price 110 → base 75 + 35
            $this->assertSame([75.0, 'Kofta Sandwich'], [$kofta->price, $kofta->name_en]);
            Storage::disk('public')->assertExists($kofta->image);
            $groups = $kofta->optionGroups->keyBy('name_en');
            $this->assertSame([['Single', 0.0], ['Double', 35.0]], $groups['Size']->options->map(fn ($o) => [$o->name_en, $o->price])->all());
            $this->assertTrue($groups['Size']->is_required);
            $combo = $groups['Make it a combo (fries + drink)']->options;
            $this->assertCount(4, $combo); // 2 sides × 2 drinks
            $this->assertSame(['بطاطس فارم + في كولا', 55.0], [$combo[0]->name, $combo[0]->price]);
            $this->assertSame(70.0, $combo->firstWhere('name', 'بطاطس ودجز + عصير')->price);
            $this->assertSame([['صوص شيدر', 25.0]], $groups['Sauces']->options->map(fn ($o) => [$o->name, $o->price])->all());

            $hawawshi = MenuItem::query()->where('name', 'حواوشي')->firstOrFail();
            $this->assertSame([130.0, ['new']], [$hawawshi->price, $hawawshi->badges]); // overridden by the price file
            $sojok = MenuItem::query()->where('name', 'سجق')->firstOrFail();
            $this->assertSame([95.0, true], [$sojok->price, $sojok->is_sold_out]); // not in the file: old price

            $street = DeliveryZone::query()->where('name', 'شارع البحر')->with('parent')->firstOrFail();
            $this->assertSame(['A — المركز', 30.0], [$street->parent->name, $street->effective('fee')]);
            $this->assertCount(3, $street->parent->polygon);

            $this->assertSame('internal', KbArticle::query()->firstOrFail()->visibility);
            // pickup and delivery only: no coupons carried over, no tables, no dine-in, no reservations
            $this->assertSame(0, Coupon::query()->count());
            $this->assertSame(0, DiningTable::query()->count());
            $this->assertSame(['off', '0', '1', '1'], [$s['dine_in_mode'], $s['reservations_enabled'], $s['online_takeaway'], $s['online_delivery']]);
        });
    }

    public function test_the_owner_s_split_tagines_and_added_sandwich(): void
    {
        $this->import()->assertSuccessful();

        $this->as($this->filetto(), function () {
            $names = MenuItem::query()->orderBy('sort_order')->pluck('price', 'name')->all();
            $this->assertArrayNotHasKey('طاجن خضار سادة', $names); // the merged tagine gave way to one item per tagine
            $this->assertSame([130.0, 119.0, 119.0, 119.0], [$names['طاجن بامية سادة (وسط)'], $names['طاجن بطاطس سادة (وسط)'],
                $names['طاجن ملوخية سادة (وسط)'], $names['طاجن خضار مشكّل سادة (وسط)']]);
            $this->assertSame(['كفتة', 'حواوشي', 'سجق', 'ساندوتش سجق مدخن', 'طاجن بامية سادة (وسط)'], array_slice(array_keys($names), 0, 5));

            $smoked = MenuItem::query()->where('name', 'ساندوتش سجق مدخن')->with('optionGroups')->firstOrFail();
            $this->assertSame([125.0, 'Smoked Sojok Sandwich', false], [$smoked->price, $smoked->name_en, $smoked->is_sold_out]);
            $this->assertSame(['Make it a combo (fries + drink)', 'Drinks'], $smoked->optionGroups->pluck('name_en')->all()); // like the grilled one
            $this->assertSame(['new'], MenuItem::query()->where('name', 'طاجن ملوخية سادة (وسط)')->value('badges'));
        });
    }

    public function test_a_merged_tagine_and_coupons_from_an_earlier_run_are_removed(): void
    {
        $this->import()->assertSuccessful();
        $this->as($this->filetto(), function () {
            $merged = MenuItem::query()->create(['category_id' => Category::query()->value('id'), 'name' => 'طاجن خضار سادة', 'price' => 119]);
            $map = json_decode(Settings::get('import_filetto_map'), true);
            $map['items']['60'] = $merged->id;
            Settings::put(['import_filetto_map' => json_encode($map)]);
            Coupon::query()->create(['code' => 'FILETTO15', 'type' => 'percent', 'value' => 15, 'min_order' => 200, 'source' => 'voucher', 'note' => 'خصم 15%']);
            Coupon::query()->create(['code' => 'OWN10', 'type' => 'percent', 'value' => 10, 'min_order' => 0, 'source' => 'voucher', 'note' => 'the owner\'s own']);
        });

        $this->import()->assertSuccessful();

        $this->as($this->filetto(), function () {
            $this->assertFalse(MenuItem::query()->where('name', 'طاجن خضار سادة')->exists());
            $this->assertSame(['OWN10'], Coupon::query()->pluck('code')->all());
        });
    }

    public function test_guests_are_normalised_deduplicated_and_never_messaged(): void
    {
        $this->import()->assertSuccessful();

        $this->as($this->filetto(), function () {
            $guests = Customer::query()->orderBy('phone')->get()->keyBy('phone');
            $this->assertSame(['01012345678', '01198765432'], $guests->keys()->all()); // +20 copy merged, invalid and test rows left out
            $this->assertSame('أحمد', $guests['01012345678']->name);
            $this->assertSame('طنطا شارع البحر', $guests['01012345678']->addresses[0]['text']);
            $mona = $guests['01198765432'];
            $this->assertSame('شارع البحر، عمارة 5', $mona->addresses[0]['text']);
            $this->assertSame(DeliveryZone::query()->where('name', 'شارع البحر')->value('id'), $mona->addresses[0]['zone_id']);
            $this->assertFalse($mona->marketing_opt_in);
        });
    }

    public function test_running_again_updates_and_never_duplicates(): void
    {
        $this->import()->assertSuccessful();
        $this->prices([['type' => 'variation', 'source_id' => 2, 'new_price' => 120], ['type' => 'item', 'source_id' => 11, 'new_price' => 130]]);

        $this->import()->assertSuccessful()->doesntExpectOutputToContain('Temporary password');

        $this->assertSame(1, Tenant::query()->whereKey('filetto')->count());
        $this->assertSame(1, User::query()->withoutGlobalScopes()->where('tenant_id', 'filetto')->count());
        $this->as($this->filetto(), function () {
            // 3 old items + the smoked sojok sandwich + 4 plain tagines
            $this->assertSame([1, 8, 2, 2, 0, 1], [Category::query()->count(), MenuItem::query()->count(), Customer::query()->count(),
                DeliveryZone::query()->count(), Coupon::query()->count(), KbArticle::query()->count()]);
            $kofta = MenuItem::query()->where('name', 'كفتة')->with('optionGroups.options')->firstOrFail();
            $this->assertCount(4, $kofta->optionGroups);
            $this->assertSame([0.0, 45.0], $kofta->optionGroups->firstWhere('name_en', 'Size')->options->pluck('price')->all());
            $this->assertCount(2, Storage::disk('public')->allFiles('tenants/filetto')); // logo and photo stored once
        });
    }

    public function test_writes_nothing_into_another_restaurant(): void
    {
        $other = $this->restaurant('other-grill', 'full');
        $this->as($other, function () {
            Customer::query()->create(['name' => 'زبون المطعم التاني', 'phone' => '01012345678']);
            Category::query()->create(['name' => 'سندوتشات']);
        });

        $this->import()->assertSuccessful();

        $this->as($other, function () {
            $this->assertSame(['زبون المطعم التاني'], Customer::query()->pluck('name')->all());
            $this->assertSame(0, MenuItem::query()->count());
            $this->assertSame(0, DeliveryZone::query()->count());
            $this->assertSame('', Settings::get('facebook'));
        });
        $this->as($this->filetto(), fn () => $this->assertSame('أحمد', Customer::query()->where('phone', '01012345678')->value('name')));
    }

    public function test_a_dry_run_saves_nothing_and_an_unrelated_filetto_is_left_alone(): void
    {
        $this->import(['--dry-run' => true])->assertSuccessful()->expectsOutputToContain('DRY RUN');
        $this->assertNull(Tenant::query()->find('filetto'));
        $this->assertSame([], Storage::disk('public')->allFiles());

        $this->item($this->restaurant('filetto'), 'Margherita');
        $this->import()->assertFailed()->expectsOutputToContain('--adopt');
        $this->as($this->filetto(), fn () => $this->assertSame(['Margherita'], MenuItem::query()->pluck('name')->all()));
    }
}
