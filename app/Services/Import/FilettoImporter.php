<?php

namespace App\Services\Import;

use App\Billing\Billing;
use App\Models\AddonPrice;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\DeliveryZone;
use App\Models\DiningTable;
use App\Models\ItemOption;
use App\Models\ItemOptionGroup;
use App\Models\KbArticle;
use App\Models\KbCategory;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit;
use App\Services\Customers\CustomerImport;
use App\Services\Entitlements;
use App\Services\ImageService;
use App\Services\Settings;
use App\Services\TenantProvisioner;
use App\Services\WelcomeKit;
use App\Support\BusinessType;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PDO;
use RuntimeException;

/**
 * Moves Filetto BBQ (Tanta) from its old Laravel 11 + React ordering site into EgHub as the restaurant `filetto`:
 * the restaurant itself (created through App\Services\TenantProvisioner and the admin's welcome kit when missing) on a
 * non-expiring Enterprise subscription, its brand, social links, branch, menu (categories, items, sizes, combo and
 * extras as option groups, photos), delivery zones, knowledge base and guest list. Pickup and delivery only: no
 * tables, no dine-in, no reservations; the old coupons are not carried over (the owner's decisions of 2026-10-27,
 * with the split tagines and the added smoked sojok sandwich of NEW_ITEMS).
 *
 * The old database is opened read-only. Everything is written inside the restaurant's tenant scope and one
 * transaction; a dry run rolls it back and stores no file. Re-running updates what an earlier run imported (ids kept
 * in the `import_filetto_map` setting, else matched by name / code / mobile) and never duplicates it. Prices may be
 * overridden by a mapping file (see priceOverrides()); every other value comes from the old system.
 */
final class FilettoImporter
{
    public const TENANT = 'filetto';

    public const NAME = 'Filetto BBQ';

    public const PLAN = 'enterprise';

    public const MAP_KEY = 'import_filetto_map';

    public const PHONE = '01022228334';

    /** How many packs of each limit add-on are granted when the plan still caps something (the admin form's maximum). */
    public const LIMIT_PACKS = 50;

    private const CUSTOMER_CHUNK = 500;

    private const MAX_ADDRESSES = 5;

    /** The combo sides the old site used: the setting was never saved, so its frontend default applied (frontend/src/lib/settings.ts). */
    private const DEFAULT_COMBO_SIDES = [['name' => 'بطاطس فارم', 'extra' => 0], ['name' => 'بطاطس ودجز', 'extra' => 15]];

    private const SIZE_EN = ['سنجل' => 'Single', 'دبل' => 'Double', '250 جم' => '250 g', 'ثلث كيلو' => '⅓ kg', '500 جم' => '500 g', '1 كجم' => '1 kg'];

    private const SIZE_AR = ['Single' => 'سنجل', 'Double' => 'دبل'];

    private const EXTRA_EN = [
        'sauce_cheddar' => 'Cheddar sauce', 'sauce_texas' => 'Texas sauce', 'sauce_bbq' => 'BBQ sauce', 'sauce_ketchup' => 'Ketchup',
        'sauce_mayo' => 'Mayonnaise', 'sauce_sweet_chili' => 'Sweet chili sauce', 'sauce_dark_smoked' => 'Dark smoked sauce',
        'sauce_honey_texas' => 'Honey Texas', 'sauce_honey_mustard' => 'Honey mustard', 'sauce_smoked_bbq' => 'Smoked BBQ', 'sauce_honey' => 'Honey',
        'salad_tahini' => 'Tahini salad', 'salad_baladi' => 'Baladi salad', 'salad_tomatoes' => 'Seasoned tomatoes', 'salad_onion' => 'Onion salad',
        'salad_pickles' => 'Pickles', 'salad_yogurt' => 'Yogurt salad', 'side_farm_fries' => 'Farm fries', 'side_wedge_fries' => 'Wedge fries',
        'side_loaded_fries' => 'Loaded fries', 'side_jalapeno' => 'Jalapeño', 'side_bread' => 'Bread (4 pcs)', 'drink_water' => 'Mineral water',
        'drink_can' => 'Soft drink (can)',
    ];

    private const COMBO_EN = ['في كولا' => 'V Cola', 'في كولا دايت' => 'V Cola Diet', 'في سيفين' => 'V Seven', 'عصير' => 'Juice',
        'بطاطس فارم' => 'Farm fries', 'بطاطس ودجز' => 'Wedge fries'];

    /** extras.category => [group key, arabic, english, sort] */
    private const EXTRA_GROUPS = [
        'sauce' => ['sauces', 'صوصات', 'Sauces', 2],
        'side' => ['sides', 'سلطات وإضافات', 'Salads & sides', 3],
        'appetizer' => ['sides', 'سلطات وإضافات', 'Salads & sides', 3],
        'drink' => ['drinks', 'مشروبات', 'Drinks', 4],
    ];

    /**
     * Items the owner set up differently from the old menu (2026-10-27): each tagine on its own instead of the two
     * merged ones (`replaces` the old item, at its place), and a smoked sojok sandwich next to the grilled one
     * (`after`). They copy the category, combo and extras of that old item; `price` is the old price (the price file
     * gives the new one, target type new_item).
     *
     * @var array<string, array{name: string, name_en: string, price: float|int, replaces?: int, after?: int}>
     */
    public const NEW_ITEMS = [
        'sand_smoked_sojok' => ['name' => 'ساندوتش سجق مدخن', 'name_en' => 'Smoked Sojok Sandwich', 'price' => 115, 'after' => 41],
        'tagine_okra_meat' => ['name' => 'طاجن بامية باللحمة (وسط)', 'name_en' => 'Okra Tagine with Meat (medium)', 'price' => 295, 'replaces' => 59],
        'tagine_potato_meat' => ['name' => 'طاجن بطاطس باللحمة (وسط)', 'name_en' => 'Potato Tagine with Meat (medium)', 'price' => 295, 'replaces' => 59],
        'tagine_mixed_meat' => ['name' => 'طاجن خضار مشكّل باللحمة (وسط)', 'name_en' => 'Mixed Vegetable Tagine with Meat (medium)', 'price' => 295, 'replaces' => 59],
        'tagine_okra_plain' => ['name' => 'طاجن بامية سادة (وسط)', 'name_en' => 'Plain Okra Tagine (medium)', 'price' => 119, 'replaces' => 60],
        'tagine_potato_plain' => ['name' => 'طاجن بطاطس سادة (وسط)', 'name_en' => 'Plain Potato Tagine (medium)', 'price' => 119, 'replaces' => 60],
        'tagine_molokhia_plain' => ['name' => 'طاجن ملوخية سادة (وسط)', 'name_en' => 'Plain Molokhia Tagine (medium)', 'price' => 119, 'replaces' => 60],
        'tagine_mixed_plain' => ['name' => 'طاجن خضار مشكّل سادة (وسط)', 'name_en' => 'Plain Mixed Vegetable Tagine (medium)', 'price' => 119, 'replaces' => 60],
    ];

    /** Old badges with an EgHub badge; «بريميوم» is the premium categories' own name, so it is not repeated. */
    private const BADGES = ['جديد' => 'new'];

    private PDO $old;

    private ?string $assets = null;

    private bool $dryRun = false;

    /** @var array<string, array<string, int>> what an earlier run imported: kind => old key => new id */
    private array $map = [];

    /** @var array{item: array<int, float>, variation: array<int, float>, new_item: array<string, float>} */
    private array $prices = ['item' => [], 'variation' => [], 'new_item' => []];

    /** @var array<string, array<string, int>> */
    private array $counts = [];

    /** @var list<string> */
    private array $notes = [];

    /** @var list<string> files stored during this run, removed again when it fails */
    private array $storedImages = [];

    public function __construct(
        private readonly ImageService $images,
        private readonly TenantProvisioner $provisioner,
        private readonly WelcomeKit $welcomeKit,
        private readonly Billing $billing,
    ) {}

    /**
     * @param  array{dry_run?: bool, owner_email?: ?string, adopt?: bool}  $options
     * @param  array<string, mixed>|null  $prices  the decoded price mapping file
     * @return array{tenant_created: bool, owner_email: string, password: ?string, welcome_url: ?string, counts: array<string, array<string, int>>, notes: list<string>}
     */
    public function import(string $source, ?string $assets, ?array $prices, array $options = []): array
    {
        if (! is_file($source)) {
            throw new RuntimeException("Source database not found: {$source}");
        }
        $this->assets = $assets !== null ? rtrim(str_replace('\\', '/', $assets), '/') : null;
        if ($this->assets !== null && ! is_dir($this->assets)) {
            throw new RuntimeException("Assets folder not found: {$this->assets}");
        }
        $this->dryRun = (bool) ($options['dry_run'] ?? false);
        $this->counts = [];
        $this->notes = [];
        $this->storedImages = [];
        // read-only: SQLite writes nothing next to the old database (no journal, no -wal)
        $this->old = new PDO('sqlite:'.$source, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY,
        ]);
        $this->prices = $this->priceOverrides($prices);
        $email = strtolower(trim((string) ($options['owner_email'] ?? ''))) ?: WelcomeKit::placeholderEmail(self::TENANT);

        DB::beginTransaction();
        try {
            [$tenant, $created] = $this->tenant($email);
            $this->subscription($tenant);
            [$password, $welcomeUrl] = $created && ! $this->dryRun ? $this->issueWelcomeKit($tenant) : [null, null];
            $this->inTenant($tenant, function () use ($created, $options) {
                $this->map = json_decode(Settings::get(self::MAP_KEY), true) ?: [];
                if (! $created && $this->map === [] && MenuItem::query()->exists() && ! ($options['adopt'] ?? false)) {
                    throw new RuntimeException('The restaurant "'.self::TENANT.'" already exists with a menu this command did not import. Check it is Filetto, then run again with --adopt.');
                }
                if ($created) {
                    $this->removeStarterCategories();
                }
                $this->importSettings();
                $this->importBranch();
                $categories = $this->importCategories();
                $this->importItems($categories);
                $this->importDeliveryZones();
                $this->removeImportedCoupons();
                $this->removeTables();
                $this->importKnowledgeBase();
                $this->importCustomers();
                $this->reportSkipped();
                Settings::put([self::MAP_KEY => json_encode($this->map)]);
                Audit::record('menu.filetto_imported', self::NAME, ['counts' => $this->counts]);
            });

            if ($this->dryRun) {
                DB::rollBack();
            } else {
                DB::commit();
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            // only files this run wrote, all under tenants/filetto/
            Storage::disk('public')->delete($this->storedImages);

            throw $e;
        } finally {
            unset($this->old);
            Entitlements::flush();
            Settings::flush();
        }

        return [
            'tenant_created' => $created, 'owner_email' => $email, 'password' => $password, 'welcome_url' => $welcomeUrl,
            'counts' => $this->counts, 'notes' => $this->notes,
        ];
    }

    /**
     * The `rows` and `derived_rows` of the mapping file: each matched row's `target` (an old menu item or variation
     * id, or a NEW_ITEMS key) gets `new_price`. Two rows may point at the same target only with the same price.
     *
     * @param  array<string, mixed>|null  $file
     * @return array{item: array<int, float>, variation: array<int, float>, new_item: array<string, float>}
     */
    public function priceOverrides(?array $file): array
    {
        $out = ['item' => [], 'variation' => [], 'new_item' => []];
        foreach ([...(array) ($file['rows'] ?? []), ...(array) ($file['derived_rows'] ?? [])] as $i => $row) {
            $target = $row['target'] ?? null;
            if (! is_array($target)) {
                continue;
            }
            $type = (string) ($target['type'] ?? '');
            $id = $type === 'new_item' ? (string) ($target['key'] ?? '') : (int) ($target['source_id'] ?? 0);
            $price = $row['new_price'] ?? null;
            $validId = $type === 'new_item' ? isset(self::NEW_ITEMS[$id]) : $id > 0;
            if (! isset($out[$type]) || ! $validId || ! is_numeric($price) || (float) $price < 0) {
                throw new RuntimeException('Price file row '.($i + 1).' has no valid target / new_price.');
            }
            if (isset($out[$type][$id]) && abs($out[$type][$id] - (float) $price) > 0.001) {
                throw new RuntimeException("Price file gives two prices to {$type} {$id}.");
            }
            $out[$type][$id] = (float) $price;
        }

        return $out;
    }

    // ---------------------------------------------------------------- the restaurant and its subscription

    /** @return array{0: Tenant, 1: bool} the restaurant and whether this run created it */
    private function tenant(string $email): array
    {
        $tenant = Tenant::query()->withTrashed()->find(self::TENANT);
        if ($tenant?->trashed()) {
            throw new RuntimeException('The restaurant "'.self::TENANT.'" is deleted (soft). Restore it from the admin first.');
        }
        if ($tenant) {
            $this->count('restaurant', 'existing');

            return [$tenant, false];
        }
        if (User::query()->where('email', $email)->exists()) {
            throw new RuntimeException("The owner email {$email} is already used by another account.");
        }
        // the admin's "new restaurant" path (Admin\TenantsController@store): provisioner, then the welcome kit
        $tenant = $this->provisioner->create([
            'slug' => self::TENANT, 'name' => self::NAME, 'owner_name' => self::NAME, 'email' => $email,
            'password' => Str::password(40), 'plan' => self::PLAN, 'trial' => false, 'business_type' => 'restaurant',
        ]);
        $this->count('restaurant', 'created');

        return [$tenant, true];
    }

    /**
     * A complimentary Enterprise subscription that never ends (active, no period end: App\Billing\Lifecycle never
     * touches it, no invoice is made), and every add-on still needed for a feature or a limit the plan lacks.
     */
    private function subscription(Tenant $tenant): void
    {
        $plan = Plan::query()->where('code', self::PLAN)->firstOrFail();
        Subscription::query()->updateOrCreate(['tenant_id' => $tenant->id], [
            'plan_id' => $plan->id, 'status' => 'active', 'cycle_months' => 12,
            'trial_ends_at' => null, 'current_period_end' => null, 'grace_ends_at' => null,
        ]);
        Entitlements::flush();
        $tenant->unsetRelation('subscription');

        $rights = Entitlements::for($tenant);
        $missing = array_keys(array_filter($rights->features(), fn (bool $on) => ! $on));
        $granted = [];
        foreach (AddonPrice::query()->where('type', 'feature')->get() as $pack) {
            if (array_intersect($missing, (array) $pack->features) !== []) {
                $this->billing->setAddon($tenant, $pack->key, 1);
                $granted[] = $pack->key;
            }
        }
        foreach (AddonPrice::query()->where('type', 'limit')->get() as $pack) {
            if (array_key_exists($pack->key, $rights->limits()) && $rights->limit($pack->key) !== null) {
                $this->billing->setAddon($tenant, $pack->key, self::LIMIT_PACKS);
                $granted[] = $pack->key.'×'.self::LIMIT_PACKS;
            }
        }
        Entitlements::flush();
        $rights = Entitlements::for($tenant);
        $still = array_keys(array_filter($rights->features(), fn (bool $on) => ! $on));
        $this->notes[] = 'Subscription: '.$plan->code.' («'.$plan->name.'»), active, no end date'.($granted ? '; add-ons granted: '.implode(', ', $granted) : '; the plan already includes every module').'.';
        if ($still) {
            $this->notes[] = 'Modules no plan or add-on can switch on here: '.implode(', ', $still).'.';
        }
        $capped = array_filter($rights->limits(), fn (?int $limit) => $limit !== null);
        if ($capped) {
            $this->notes[] = 'Limits still capped: '.collect($capped)->map(fn ($v, $k) => "$k=$v")->implode(', ').'.';
        }
    }

    /** @return array{0: string, 1: ?string} the temporary password (shown once) and the welcome page link */
    private function issueWelcomeKit(Tenant $tenant): array
    {
        $owner = User::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->role('owner')->orderBy('id')->firstOrFail();
        $password = $this->welcomeKit->issue($tenant, $owner);

        return [$password, $this->welcomeKit->url($tenant)];
    }

    /** The provisioner adds empty starter categories for the business type; the imported menu replaces them. */
    private function removeStarterCategories(): void
    {
        $starter = collect(BusinessType::categories('restaurant'))->pluck(0)->all();
        Category::query()->whereIn('name', $starter)->whereDoesntHave('items')->delete();
    }

    // ---------------------------------------------------------------- brand and settings

    private function importSettings(): void
    {
        $old = [];
        foreach ($this->rows('settings') as $row) {
            $old[(string) $row['key']] = trim((string) ($row['value'] ?? ''));
        }
        $values = [
            'brand_name' => self::NAME,
            'brand_name_en' => self::NAME,
            'tagline' => $old['brand_tagline'] ?? 'مشويات ولحوم بلدي فاخرة',
            'tagline_en' => 'Fine meat cuts · '.Str::title(strtolower($old['header_slogan_en'] ?? '100% farm fresh')),
            'short_description' => Str::limit(trim(str_replace(['«', '»'], '', $old['footer_text'] ?? '')), 250, ''),
            'short_description_en' => 'Fresh local meat grilled over charcoal, with our own sauces. Fine meat cuts, 100% farm fresh.',
            'intro_slogan' => $old['header_slogan_ar'] ?? '',
            'business_type' => 'restaurant',
            'menu_theme' => 'dark',
            'font' => 'Cairo',
            'default_locale' => 'ar',
            'english_enabled' => '1',
            'phone' => self::PHONE,
            'whatsapp' => self::PHONE,
            'facebook' => 'https://www.facebook.com/Filetto.BBQ',
            'instagram' => 'https://www.instagram.com/filetto_bbq',
            'tiktok' => 'https://www.tiktok.com/@filetto_egy',
            'city' => 'tanta',
            'area' => 'شارع المتحف',
            'cuisine' => 'grills,burgers',
            // pickup and delivery only: no tables, no dine-in, no reservations
            'online_takeaway' => '1',
            'online_delivery' => '1',
            'dine_in_mode' => 'off',
            'reservations_enabled' => '0',
        ];
        $primary = BrandStyle::hex($old['color_primary'] ?? null);
        $background = BrandStyle::hex($old['color_background'] ?? null);
        if (Entitlements::current()->allows(BrandStyle::FEATURE)) {
            $values['color_primary'] = $primary ?? '#DC2626';
            // the old site was dark (#0D0D10) with red: the dark template paints the page, the dark colour heads it
            $values['color_secondary'] = $background && BrandStyle::luminance($background) <= 0.3 ? $background : '#0D0D10';
        }
        if (($phone = normalize_phone($old['hotline_phone'] ?? '')) !== null && $phone !== self::PHONE) {
            $this->notes[] = "The old hotline {$phone} differs from ".self::PHONE.'; '.self::PHONE.' was kept.';
        }
        if (Settings::get('logo') === '' && ($logo = $this->storeImage($old['logo_url'] ?? '', 'logo')) !== null) {
            $values['logo'] = $logo;
        }
        Settings::put($values);
        $this->count('settings', 'written', count($values));
    }

    private function importBranch(): void
    {
        $branch = Branch::query()->orderBy('sort_order')->orderBy('id')->first();
        if (! $branch) {
            $branch = Branch::query()->create(['name' => 'الفرع الرئيسي', 'name_en' => 'Main branch', 'slug' => 'main']);
        }
        $old = collect($this->rows('settings'))->pluck('value', 'key');
        $hours = ($old['store_open_time'] ?? '') !== '' && ($old['store_close_time'] ?? '') !== ''
            ? $old['store_open_time'].' - '.$old['store_close_time'] : $branch->working_hours;
        $branch->update([
            'name' => 'فيليتو - طنطا', 'name_en' => 'Filetto - Tanta',
            'address' => '9 شارع المتحف، طنطا', 'phone' => self::PHONE, 'working_hours' => (string) $hours,
        ]);
        $this->map['branch'] = ['main' => $branch->id];
        $this->count('branch', 'updated');
    }

    // ---------------------------------------------------------------- menu

    /** @return array<int, Category> old category id => category */
    private function importCategories(): array
    {
        $out = [];
        foreach ($this->rows('categories', 'sort_order, id') as $i => $row) {
            $attributes = [
                'name' => Str::limit(trim((string) $row['name_ar']), 255, ''), 'name_en' => Str::limit(trim((string) ($row['name_en'] ?? '')), 255, ''),
                'description' => '', 'description_en' => '', 'sort_order' => $i + 1, 'is_active' => true,
            ];
            $out[(int) $row['id']] = $this->upsert(Category::class, 'categories', (string) $row['id'], $attributes, ['name' => $attributes['name']]);
        }

        return $out;
    }

    /** @param  array<int, Category>  $categories */
    private function importItems(array $categories): void
    {
        $variations = [];
        foreach ($this->rows('menu_variations', 'menu_item_id, sort_order, id') as $v) {
            $variations[(int) $v['menu_item_id']][] = $v;
        }
        $extras = collect($this->rows('extras'))->keyBy('slug');
        $settings = collect($this->rows('settings'))->pluck('value', 'key');
        $combo = $this->comboChoices($settings->all());
        $known = ['item' => [], 'variation' => [], 'new_item' => []];
        $order = [];

        foreach ($this->itemPlan() as [$key, $row]) {
            $category = $categories[(int) $row['category_id']] ?? null;
            if (! $category) {
                $this->count('items', 'skipped (no category)');

                continue;
            }
            $id = (int) $row['id'];
            $isNew = str_starts_with($key, 'new:');
            $isNew ? $known['new_item'][substr($key, 4)] = true : $known['item'][$id] = true;
            $sizes = $isNew ? [] : ($variations[$id] ?? []);
            $sizePrices = [];
            foreach ($sizes as $v) {
                $known['variation'][(int) $v['id']] = true;
                $sizePrices[(int) $v['id']] = $this->prices['variation'][(int) $v['id']] ?? (float) $v['price'];
            }
            // EgHub adds the chosen size to the item price: the item carries the cheapest size, the sizes the difference
            $base = match (true) {
                $sizePrices !== [] => min($sizePrices),
                $isNew => $this->prices['new_item'][substr($key, 4)] ?? (float) $row['base_price'],
                default => $this->prices['item'][$id] ?? (float) $row['base_price'],
            };
            if ($sizePrices && isset($this->prices['item'][$id])) {
                $this->notes[] = "Price file sets item {$id} itself, but it has sizes: the sizes' prices were used.";
            }
            $badge = self::BADGES[trim((string) ($row['badge'] ?? ''))] ?? null;
            $order[$category->id] = ($order[$category->id] ?? 0) + 1;
            $attributes = [
                'category_id' => $category->id,
                'name' => Str::limit(trim((string) $row['name']), 255, ''),
                'name_en' => Str::limit(trim((string) ($row['english_name'] ?? '')), 255, ''),
                'description' => trim((string) ($row['description'] ?? '')) ?: null,
                'price' => round($base, 2),
                'badges' => $badge ? [$badge] : null,
                'is_active' => true,
                'is_sold_out' => (int) $row['is_available'] !== 1,
                'sort_order' => $order[$category->id],
            ];
            $item = $this->upsert(MenuItem::class, 'items', $key, $attributes,
                ['category_id' => $category->id, 'name' => $attributes['name']]);
            if (! $item->image && ($path = $this->storeImage((string) ($row['image'] ?? ''), 'item')) !== null) {
                $item->forceFill(['image' => $path])->save();
            }

            $this->syncGroup($item, $key, 'size', 'الحجم', 'Size', 'single', true, 0, collect($sizes)->map(fn ($v) => [
                'key' => (string) $v['id'],
                'name' => self::SIZE_AR[trim((string) $v['name'])] ?? trim((string) $v['name']),
                'name_en' => self::SIZE_EN[trim((string) $v['name'])] ?? trim((string) $v['name']),
                'price' => round($sizePrices[(int) $v['id']] - $base, 2),
            ])->values()->all());
            $this->syncGroup($item, $key, 'combo', 'حوّلها كومبو (بطاطس + مشروب)', 'Make it a combo (fries + drink)', 'single', false, 1,
                (int) $row['allow_combo'] === 1 ? $combo : []);

            $byGroup = [];
            foreach ((array) json_decode((string) ($row['suggested_extras'] ?? '[]'), true) as $slug) {
                $extra = $extras->get($slug);
                if (! $extra) {
                    $this->count('extras', 'missing in the old menu');

                    continue;
                }
                [$group, $ar, $en, $sort] = self::EXTRA_GROUPS[$extra['category']] ?? self::EXTRA_GROUPS['side'];
                $byGroup[$group]['meta'] = [$ar, $en, $sort];
                $byGroup[$group]['options'][] = [
                    'key' => (string) $slug, 'name' => trim((string) $extra['name']),
                    'name_en' => self::EXTRA_EN[$slug] ?? Str::headline((string) $slug), 'price' => (float) $extra['price'],
                ];
            }
            foreach (['sauces', 'sides', 'drinks'] as $group) {
                [$ar, $en, $sort] = $byGroup[$group]['meta'] ?? ['', '', 0];
                $this->syncGroup($item, $key, $group, $ar, $en, 'multiple', false, $sort, $byGroup[$group]['options'] ?? []);
            }
        }

        foreach (['item', 'variation', 'new_item'] as $type) {
            foreach (array_diff_key($this->prices[$type], $known[$type]) as $missing => $_) {
                $this->notes[] = "Price file targets {$type} {$missing}, which is not in the menu: ignored.";
            }
        }
        $this->count('prices', 'overridden', count($this->prices['item']) + count($this->prices['variation']) + count($this->prices['new_item']));
        $this->removeReplacedItems();
        $unusedExtras = $extras->keys()->diff(collect($this->rows('menu_items'))->flatMap(fn ($r) => (array) json_decode((string) ($r['suggested_extras'] ?? '[]'), true)));
        if ($unusedExtras->isNotEmpty()) {
            $this->notes[] = 'Extras no item offers in the old menu (not imported, nothing to attach them to): '.$unusedExtras->implode(', ').'.';
        }
    }

    /**
     * The menu to write, in order: [map key, row in the old menu_items shape]. An old item the owner split
     * (NEW_ITEMS `replaces`) gives way to its new items at its place; an added item (`after`) follows its neighbour.
     * New items take the category, badge, combo and extras of that old item (never its photo); they start available.
     *
     * @return list<array{0: string, 1: array<string, mixed>}>
     */
    private function itemPlan(): array
    {
        $plan = [];
        foreach ($this->rows('menu_items', 'category_id, id') as $row) {
            $id = (int) $row['id'];
            if (! in_array($id, array_column(self::NEW_ITEMS, 'replaces'), true)) {
                $plan[] = [(string) $id, $row];
            }
            foreach (self::NEW_ITEMS as $key => $new) {
                if (($new['replaces'] ?? $new['after'] ?? null) === $id) {
                    $plan[] = ['new:'.$key, ['name' => $new['name'], 'english_name' => $new['name_en'], 'description' => null,
                        'base_price' => $new['price'], 'image' => null, 'has_sizes' => 0, 'is_available' => 1] + $row];
                }
            }
        }

        return $plan;
    }

    /** The old items the owner split (the merged tagines): what an earlier run made of them goes. */
    private function removeReplacedItems(): void
    {
        foreach (array_unique(array_filter(array_column(self::NEW_ITEMS, 'replaces'))) as $old) {
            $id = $this->map['items'][(string) $old] ?? null;
            $item = $id ? MenuItem::query()->find($id) : null;
            if ($item) {
                foreach ($item->optionGroups as $group) {
                    $group->options()->delete();
                    $group->delete();
                }
                $this->images->delete($item->image);
                $item->delete();
                $this->count('items', 'removed (split by the owner)');
            }
            unset($this->map['items'][(string) $old]);
            foreach (['groups', 'options'] as $kind) {
                $this->map[$kind] = array_filter($this->map[$kind] ?? [], fn ($k) => ! str_starts_with((string) $k, $old.':'), ARRAY_FILTER_USE_KEY);
            }
        }
    }

    /**
     * The old "make it a combo" switch: +combo_price with a side and a drink. One single choice per side × drink, so a
     * guest can only get the drink and the side together with the combo price.
     *
     * @param  array<string, string>  $settings
     * @return list<array{key: string, name: string, name_en: string, price: float}>
     */
    private function comboChoices(array $settings): array
    {
        $price = (float) ($settings['combo_price'] ?? 55);
        $parse = function (?string $json, array $default): array {
            $list = json_decode((string) $json, true);
            $list = is_array($list) && $list !== [] ? $list : $default;

            return array_values(array_filter(array_map(fn ($o) => is_array($o)
                ? ['name' => trim((string) ($o['name'] ?? '')), 'extra' => (float) ($o['extra'] ?? 0)]
                : ['name' => trim((string) $o), 'extra' => 0.0], $list), fn ($o) => $o['name'] !== ''));
        };
        $drinks = $parse($settings['combo_drinks'] ?? null, [['name' => 'كانز', 'extra' => 0]]);
        $sides = $parse($settings['combo_sides'] ?? null, self::DEFAULT_COMBO_SIDES);
        $choices = [];
        foreach ($sides as $side) {
            foreach ($drinks as $drink) {
                $choices[] = [
                    'key' => $side['name'].'|'.$drink['name'],
                    'name' => $side['name'].' + '.$drink['name'],
                    'name_en' => (self::COMBO_EN[$side['name']] ?? $side['name']).' + '.(self::COMBO_EN[$drink['name']] ?? $drink['name']),
                    'price' => $price + $side['extra'] + $drink['extra'],
                ];
            }
        }

        return $choices;
    }

    /**
     * Creates or updates one option group of an item and its options (keyed by the old ids / slugs); a group or an
     * option this importer made earlier and the old menu no longer has is removed.
     *
     * @param  list<array{key: string, name: string, name_en: string, price: float}>  $options
     */
    private function syncGroup(MenuItem $item, string $oldItem, string $kind, string $name, string $nameEn, string $type, bool $required, int $sort, array $options): void
    {
        $groupKey = "{$oldItem}:{$kind}";
        $group = isset($this->map['groups'][$groupKey]) ? ItemOptionGroup::query()->find($this->map['groups'][$groupKey]) : null;
        if ($options === []) {
            if ($group) {
                $group->options()->delete();
                $group->delete();
            }
            unset($this->map['groups'][$groupKey]);

            return;
        }
        $attributes = ['menu_item_id' => $item->id, 'name' => $name, 'name_en' => $nameEn, 'type' => $type, 'is_required' => $required, 'sort_order' => $sort];
        if ($group) {
            $group->update($attributes);
        } else {
            $group = ItemOptionGroup::query()->create($attributes);
        }
        $this->map['groups'][$groupKey] = $group->id;
        $this->count('option groups', $group->wasRecentlyCreated ? 'created' : 'updated');

        $keep = [];
        foreach ($options as $j => $o) {
            $optionKey = "{$groupKey}:{$o['key']}";
            $option = isset($this->map['options'][$optionKey]) ? ItemOption::query()->where('item_option_group_id', $group->id)->find($this->map['options'][$optionKey]) : null;
            $values = ['item_option_group_id' => $group->id, 'name' => Str::limit($o['name'], 255, ''), 'name_en' => Str::limit($o['name_en'], 255, ''),
                'price' => $o['price'], 'is_default' => $type === 'single' && $required && $j === 0, 'sort_order' => $j];
            if ($option) {
                $option->update($values);
            } else {
                $option = ItemOption::query()->create($values);
            }
            $this->map['options'][$optionKey] = $option->id;
            $keep[] = $option->id;
            $this->count($kind === 'size' ? 'sizes' : ($kind === 'combo' ? 'combo choices' : 'extras'), $option->wasRecentlyCreated ? 'created' : 'updated');
        }
        $group->options()->whereNotIn('id', $keep)->delete();
    }

    // ---------------------------------------------------------------- delivery, coupons, knowledge base

    private function importDeliveryZones(): void
    {
        $branch = (int) ($this->map['branch']['main'] ?? Branch::query()->orderBy('id')->value('id'));
        // parents first, so sub-areas can point at them
        $rows = collect($this->rows('delivery_zones', 'sort_order, id'))->sortBy(fn ($r) => $r['parent_id'] === null ? 0 : 1)->values();
        $ids = [];
        foreach ($rows as $row) {
            $polygon = json_decode((string) ($row['polygon'] ?? ''), true);
            $parent = $row['parent_id'] !== null ? ($ids[(int) $row['parent_id']] ?? null) : null;
            $attributes = [
                'branch_id' => $branch, 'parent_id' => $parent, 'name' => Str::limit(trim((string) $row['name']), 255, ''), 'name_en' => '',
                'polygon' => is_array($polygon) && count($polygon) >= 3 ? $polygon : null,
                'color' => BrandStyle::hex($row['color'] ?? null) ?? '#F26522',
                'fee' => $row['fee'] !== null ? (float) $row['fee'] : null,
                'min_order' => $row['min_order'] !== null ? (float) $row['min_order'] : null,
                'estimated_minutes' => $row['estimated_minutes'] !== null ? (int) $row['estimated_minutes'] : null,
                'is_active' => (int) $row['is_active'] === 1, 'sort_order' => (int) $row['sort_order'],
            ];
            $zone = $this->upsert(DeliveryZone::class, 'zones', (string) $row['id'], $attributes, ['name' => $attributes['name'], 'parent_id' => $parent]);
            $ids[(int) $row['id']] = $zone->id;
        }
    }

    /**
     * The old coupons are not carried over (the owner's decision, 2026-10-27). An earlier run of this command made
     * them as public vouchers noted with the old title: those go again, unless a guest already used one.
     */
    private function removeImportedCoupons(): void
    {
        $old = collect($this->rows('coupons'));
        if ($old->isNotEmpty()) {
            $this->count('coupons', 'not imported (owner)', $old->count());
        }
        foreach ($old as $row) {
            $coupon = Coupon::query()->where('code', strtoupper(trim((string) $row['code'])))->where('source', 'voucher')->whereNull('customer_id')
                ->where('note', Str::limit(trim((string) $row['title']), 255, ''))->first();
            if (! $coupon) {
                continue;
            }
            if ($coupon->uses > 0) {
                $coupon->update(['is_active' => false]);
                $this->notes[] = "Coupon {$coupon->code} from an earlier run was already used: switched off instead of deleted.";
                $this->count('coupons', 'switched off (used)');

                continue;
            }
            $coupon->delete();
            $this->count('coupons', 'removed (earlier run)');
        }
    }

    /**
     * Filetto has no tables and no dine-in: the tables the provisioner made go (a table with orders on it is kept,
     * its orders point at it).
     */
    private function removeTables(): void
    {
        $used = Order::query()->whereNotNull('dining_table_id')->distinct()->pluck('dining_table_id');
        $removed = DiningTable::query()->whereNotIn('id', $used)->delete();
        if ($removed) {
            $this->count('tables', 'removed', $removed);
        }
        if ($kept = DiningTable::query()->count()) {
            $this->notes[] = "{$kept} tables kept: they already have orders.";
        }
    }

    /** The old answers were never marked verified by the restaurant: they go in for the team only (internal). */
    private function importKnowledgeBase(): void
    {
        $categories = [];
        foreach ($this->rows('kb_entries', 'sort_order, id') as $row) {
            $name = trim((string) $row['category']);
            if (! isset($categories[$name])) {
                $categories[$name] = $this->upsert(KbCategory::class, 'kb_categories', $name,
                    ['name' => Str::limit($name, 255, ''), 'sort_order' => count($categories) + 1, 'is_active' => true], ['name' => $name])->id;
            }
            $this->upsert(KbArticle::class, 'kb', (string) $row['id'], [
                'category_id' => $categories[$name], 'question' => Str::limit(trim((string) $row['question']), 255, ''),
                'answer' => trim((string) $row['answer']), 'tags' => Str::limit(trim((string) ($row['tags'] ?? '')), 255, '') ?: null,
                'visibility' => 'internal', 'is_active' => (int) $row['is_active'] === 1, 'sort_order' => (int) $row['sort_order'],
            ], ['question' => Str::limit(trim((string) $row['question']), 255, '')]);
        }
    }

    // ---------------------------------------------------------------- guests

    /**
     * The guest list, matched by mobile inside this restaurant only. New guests are inserted directly (no model
     * events, no welcome message, no marketing opt-in); a guest already here only gets an empty name or address
     * filled. Mobiles are normalised to 01xxxxxxxxx; the old test rows («عميل» without an address) are left out.
     */
    private function importCustomers(): void
    {
        $zones = $this->map['zones'] ?? [];
        $guests = [];
        foreach ($this->rows('customers', 'id') as $row) {
            $phone = CustomerImport::phone((string) $row['phone']);
            if ($phone === null) {
                $this->count('customers', 'skipped (invalid mobile)');

                continue;
            }
            $name = mb_substr(trim(preg_replace('/\s+/u', ' ', (string) ($row['name'] ?? '')) ?? ''), 0, 100);
            $addresses = $this->addresses($row, $zones);
            if ($name === 'عميل' && $addresses === []) {
                $this->count('customers', 'skipped (test placeholder)');
                $this->notes[] = "Guest {$phone} «عميل» without an address looks like a test row: skipped.";

                continue;
            }
            if (isset($guests[$phone])) {
                $this->count('customers', 'duplicates merged');
                $guests[$phone]['addresses'] = collect([...$guests[$phone]['addresses'], ...$addresses])
                    ->unique(fn ($a) => $a['zone_id'].'|'.$a['text'])->take(self::MAX_ADDRESSES)->values()->all();
                $guests[$phone]['name'] = $guests[$phone]['name'] !== '' ? $guests[$phone]['name'] : $name;

                continue;
            }
            $phone2 = CustomerImport::phone((string) ($row['phone_2'] ?? ''));
            $guests[$phone] = [
                'name' => $name, 'phone' => $phone, 'phone_2' => $phone2 !== $phone ? $phone2 : null,
                'since' => $this->date($row['created_at'] ?? null),
                'notes' => ($n = trim((string) ($row['notes'] ?? ''))) !== '' ? mb_substr($n, 0, 1000) : null,
                'addresses' => $addresses,
            ];
        }

        $tenantId = tenant()->getTenantKey();
        foreach (array_chunk($guests, self::CUSTOMER_CHUNK) as $chunk) {
            $existing = Customer::query()->whereIn('phone', array_column($chunk, 'phone'))->get()->keyBy('phone');
            $insert = [];
            foreach ($chunk as $guest) {
                $customer = $existing->get($guest['phone']);
                if ($customer) {
                    $fill = array_filter([
                        'name' => $customer->name === '' && $guest['name'] !== '' ? $guest['name'] : null,
                        'addresses' => empty($customer->addresses) && $guest['addresses'] !== [] ? $guest['addresses'] : null,
                    ], fn ($v) => $v !== null);
                    if ($fill !== []) {
                        $customer->forceFill($fill)->save();
                    }
                    $this->count('customers', $fill !== [] ? 'existing (filled)' : 'existing (unchanged)');

                    continue;
                }
                $now = now();
                $insert[] = [
                    'tenant_id' => $tenantId, 'name' => $guest['name'], 'phone' => $guest['phone'], 'phone_2' => $guest['phone_2'],
                    'notes' => $guest['notes'], 'addresses' => $guest['addresses'] !== [] ? json_encode($guest['addresses'], JSON_UNESCAPED_UNICODE) : null,
                    'source' => 'import', 'marketing_opt_in' => false, 'card_token' => random_token(12),
                    'created_at' => $guest['since'] ?? $now, 'updated_at' => $now,
                ];
            }
            if ($insert !== []) {
                $this->count('customers', 'imported', DB::table('customers')->insertOrIgnore($insert));
            }
        }
    }

    /**
     * A guest's saved addresses in EgHub's shape ({label, zone_id, text, lat?, lng?}): the old structured ones
     * (street, building, floor, apartment, landmark, zone) first, else the free-text address.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, int>  $zones  old zone id => new zone id
     * @return list<array<string, mixed>>
     */
    private function addresses(array $row, array $zones): array
    {
        $out = [];
        foreach ((array) json_decode((string) ($row['addresses'] ?? ''), true) as $a) {
            if (! is_array($a)) {
                continue;
            }
            $text = collect([
                $a['street'] ?? '', ($a['building'] ?? '') !== '' ? 'عمارة '.$a['building'] : '', ($a['floor'] ?? '') !== '' ? 'دور '.$a['floor'] : '',
                ($a['apartment'] ?? '') !== '' ? 'شقة '.$a['apartment'] : '', $a['landmark'] ?? '',
            ])->map(fn ($p) => trim((string) $p))->filter()->implode('، ');
            if ($text === '') {
                continue;
            }
            $out[] = ['label' => '', 'zone_id' => isset($a['delivery_zone_id']) ? ($zones[(string) $a['delivery_zone_id']] ?? null) : null, 'text' => mb_substr($text, 0, 255)]
                + (isset($a['lat'], $a['lng']) && is_numeric($a['lat']) && is_numeric($a['lng']) ? ['lat' => (float) $a['lat'], 'lng' => (float) $a['lng']] : []);
        }
        $free = trim((string) ($row['address_text'] ?? ''));
        if ($out === [] && $free !== '') {
            $out[] = ['label' => '', 'zone_id' => null, 'text' => mb_substr($free, 0, 255)];
        }

        return array_slice($out, 0, self::MAX_ADDRESSES);
    }

    private function reportSkipped(): void
    {
        $offers = count($this->rows('special_offers'));
        if ($offers) {
            $this->count('special offers', 'skipped', $offers);
            $this->notes[] = "{$offers} special offers (fixed-price bundles of free-text contents, some pickup- or delivery-only) have no clean EgHub equivalent: set them up again as combo items or timed offers.";
        }
        $loyalty = $this->rows('loyalty_accounts');
        if ($loyalty) {
            $this->count('loyalty accounts', 'skipped', count($loyalty));
            $this->notes[] = count($loyalty).' loyalty accounts ('.array_sum(array_column($loyalty, 'points')).' points) were not moved: EgHub keeps points on a loyalty program the restaurant has not chosen yet.';
        }
        foreach (['orders' => 'past orders', 'users' => 'staff accounts of the old system', 'order_ratings' => 'ratings'] as $table => $label) {
            if ($n = count($this->rows($table))) {
                $this->count($label, 'skipped', $n);
            }
        }
        $variationImages = count(array_filter($this->rows('menu_variations'), fn ($v) => ($v['image'] ?? '') !== ''));
        if ($variationImages) {
            $this->notes[] = "{$variationImages} size photos (e.g. a double sandwich) were not imported: EgHub sizes have no photo of their own.";
        }
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Updates the row an earlier run made (by its remembered id), else one matched by $match, else creates it.
     *
     * @template T of \Illuminate\Database\Eloquent\Model
     *
     * @param  class-string<T>  $model
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $match
     * @return T
     */
    private function upsert(string $model, string $kind, string $oldKey, array $attributes, array $match)
    {
        $id = $this->map[$kind][$oldKey] ?? null;
        $row = $id ? $model::query()->find($id) : null;
        $row ??= $model::query()->where($match)->whereNotIn('id', array_values($this->map[$kind] ?? []))->first();
        if ($row) {
            $row->update($attributes);
            $this->count($kind, 'updated');
        } else {
            $row = $model::query()->create($attributes);
            $this->count($kind, 'created');
        }
        $this->map[$kind][$oldKey] = $row->id;

        return $row;
    }

    /**
     * Stores an old picture through the dashboard's image pipeline (WebP under tenants/filetto/...). The file is
     * looked up in the assets folder by its old relative path, then by its name. Null on a dry run, without an assets
     * folder, or when the file is missing (reported).
     */
    private function storeImage(string $relative, string $profile): ?string
    {
        $relative = ltrim(str_replace('\\', '/', trim($relative)), '/');
        if ($relative === '') {
            return null;
        }
        if (preg_match('~^https?://~i', $relative)) {
            $this->count('images', 'skipped (remote)');

            return null;
        }
        $file = null;
        if ($this->assets !== null && ! str_contains($relative, '..')) {
            foreach ([$relative, basename($relative)] as $candidate) {
                if (is_file($this->assets.'/'.$candidate)) {
                    $file = $this->assets.'/'.$candidate;
                    break;
                }
            }
        }
        if ($file === null) {
            $this->count('images', 'missing');
            $this->notes[] = "Image not found in the assets folder: {$relative}";

            return null;
        }
        if ($this->dryRun) {
            $this->count('images', 'would store');

            return null;
        }
        try {
            $path = $this->images->store(new UploadedFile($file, basename($file), null, null, true), $profile);
        } catch (\Throwable $e) {
            $this->count('images', 'failed');
            $this->notes[] = "Image not stored ({$relative}): ".$e->getMessage();

            return null;
        }
        $this->storedImages[] = $path;
        $this->count('images', 'stored');

        return $path;
    }

    /** @return list<array<string, mixed>> all rows of an old table ([] when it does not exist) */
    private function rows(string $table, ?string $orderBy = null): array
    {
        $q = $this->old->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?");
        $q->execute([$table]);
        if (! $q->fetchColumn()) {
            return [];
        }
        $order = $orderBy ? ' ORDER BY '.collect(explode(',', $orderBy))->map(fn ($c) => '"'.trim($c).'"')->implode(', ') : '';

        return $this->old->query("SELECT * FROM \"{$table}\"{$order}")->fetchAll();
    }

    /** The guest's first date in the old system (kept so "new this month" means new), null when unreadable or in the future. */
    private function date(mixed $value): ?Carbon
    {
        try {
            $date = $value ? Carbon::parse((string) $value) : null;
        } catch (\Throwable) {
            return null;
        }

        return $date && $date->isPast() ? $date : null;
    }

    private function count(string $entity, string $what, int $n = 1): void
    {
        $this->counts[$entity][$what] = ($this->counts[$entity][$what] ?? 0) + $n;
    }

    private function inTenant(Tenant $tenant, \Closure $fn): mixed
    {
        tenancy()->initialize($tenant);
        Settings::flush();
        Entitlements::flush();
        try {
            return $fn();
        } finally {
            tenancy()->end();
            Settings::flush();
        }
    }
}
