<?php

namespace App\Services\Import;

use App\Models\Branch;
use App\Models\BranchItem;
use App\Models\Category;
use App\Models\ItemOption;
use App\Models\ItemOptionGroup;
use App\Models\MenuItem;
use App\Models\MenuTranslation;
use App\Models\Tenant;
use App\Services\Audit;
use App\Services\Entitlements;
use App\Services\ImageService;
use App\Services\Settings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PDO;
use RuntimeException;

/**
 * Moves a menu from the legacy "Desoky" app family (plain PHP + one SQLite file, menu.db) into an EgMenu restaurant:
 * settings, logo/cover, categories, items with sizes/add-ons, "goes well with", combos, branches and their per-branch
 * prices/availability, and item photos (from {folder}/uploads or downloaded from a base URL). Missing legacy tables or
 * columns are read as empty. Everything is written inside the tenant scope and one transaction; a dry run rolls the
 * transaction back and touches no files. Re-running skips what already exists (matched by name).
 */
final class LegacyMenuImporter
{
    /** The largest photo accepted, same cap as the dashboard upload. */
    public const MAX_IMAGE_BYTES = 5 * 1024 * 1024;

    public const DOWNLOAD_TIMEOUT = 15;

    /** Legacy badge keys that carry another name in EgMenu (the same keys map one to one). */
    private const BADGE_ALIASES = ['signature' => ['cafe' => 'barista_pick', 'default' => 'chef_pick']];

    /** Legacy settings with no EgMenu counterpart that are not worth reporting (internal flags). */
    private const SILENT_SETTINGS = ['admin_pass_hash', 'admin_user', 'admin_password', 'schema_version'];

    private const ENTITIES = ['categories', 'items', 'sizes', 'extras', 'pairings', 'combos', 'branches', 'branch_overrides', 'images', 'settings'];

    private PDO $legacy;

    private string $folder;

    /** @var array{dry_run:bool, replace:bool, images:bool, images_base_url:?string, logo:?string} */
    private array $options;

    /** @var array<string, array{created:int, skipped:int, unmapped:int}> */
    private array $counts = [];

    /** @var list<string> */
    private array $notes = [];

    /** @var array<string, string> legacy setting key => value that has no EgMenu equivalent */
    private array $unmappedSettings = [];

    /** @var list<string> files written to the public disk during this run (removed again when it fails) */
    private array $storedImages = [];

    /** @var list<string> images of the replaced menu, deleted once the new one is committed */
    private array $replacedImages = [];

    public function __construct(private readonly ImageService $images) {}

    /**
     * @param  array{dry_run?:bool, replace?:bool, images?:bool, images_base_url?:?string, logo?:?string}  $options
     * @return array{counts: array<string, array{created:int, skipped:int, unmapped:int}>, notes: list<string>, unmapped_settings: array<string,string>}
     */
    public function import(string $folder, Tenant $tenant, array $options = []): array
    {
        $this->folder = rtrim(str_replace('\\', '/', $folder), '/');
        $this->options = [
            'dry_run' => (bool) ($options['dry_run'] ?? false),
            'replace' => (bool) ($options['replace'] ?? false),
            'images' => (bool) ($options['images'] ?? true),
            'images_base_url' => ($options['images_base_url'] ?? null) ? rtrim((string) $options['images_base_url'], '/') : null,
            'logo' => $options['logo'] ?? null,
        ];
        $this->counts = array_fill_keys(self::ENTITIES, ['created' => 0, 'skipped' => 0, 'unmapped' => 0]);
        $this->notes = $this->unmappedSettings = $this->storedImages = $this->replacedImages = [];

        $source = $this->folder.'/menu.db';
        if (! is_file($source)) {
            throw new RuntimeException("No menu.db in {$this->folder}");
        }
        // the legacy folder is never written to: SQLite may create journal files next to the database it opens
        $copy = tempnam(sys_get_temp_dir(), 'legacy-menu');
        copy($source, $copy);

        try {
            $this->legacy = new PDO('sqlite:'.$copy, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
            $this->runInTenant($tenant);
        } finally {
            unset($this->legacy);
            @unlink($copy);
        }

        return ['counts' => $this->counts, 'notes' => $this->notes, 'unmapped_settings' => $this->unmappedSettings];
    }

    private function runInTenant(Tenant $tenant): void
    {
        tenancy()->initialize($tenant);
        Settings::flush();
        Entitlements::flush();
        DB::beginTransaction();
        try {
            if ($this->options['replace']) {
                $this->clearMenu();
            }
            $settings = $this->importSettings();
            $categories = $this->importCategories();
            $items = $this->importItems($categories);
            $this->importOptions($items);
            $this->importPairings($items);
            $branches = $this->importBranches($settings);
            $this->importBranchOverrides($branches, $items);
            $this->reportUnmappedTables();
            // the same rule as a style taken from an uploaded menu (App\Services\Import\BrandStyle::apply): no item
            // photos → no empty placeholder tiles (the price sits on the name's line); a logo → the header follows it
            if (! MenuItem::query()->whereNotNull('image')->where('image', '!=', '')->exists()) {
                Settings::put(['item_placeholder' => '0']);
            }

            if ($this->options['dry_run']) {
                DB::rollBack();
            } else {
                Audit::record('menu.legacy_imported', basename($this->folder), ['counts' => $this->counts]);
                DB::commit();
                foreach ($this->replacedImages as $path) {
                    $this->images->delete($path);
                }
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            foreach ($this->storedImages as $path) {
                $this->images->delete($path);
            }

            throw $e;
        } finally {
            tenancy()->end();
            Settings::flush();
            Entitlements::flush();
        }
    }

    /** --replace: the restaurant's categories and items go (options, branch prices and pivots cascade); branches stay. */
    private function clearMenu(): void
    {
        $this->replacedImages = array_values(array_filter([
            ...MenuItem::query()->pluck('image')->all(),
            ...Category::query()->pluck('image')->all(),
        ]));
        $items = MenuItem::query()->count();
        MenuTranslation::query()->whereIn('translatable_type', ['category', 'item', 'group', 'option'])->delete();
        ItemOption::query()->delete();
        ItemOptionGroup::query()->delete();
        BranchItem::query()->delete();
        DB::table('item_pairings')->where('tenant_id', tenant()->getTenantKey())->delete();
        DB::table('combo_items')->where('tenant_id', tenant()->getTenantKey())->delete();
        MenuItem::query()->delete();
        $categories = Category::query()->count();
        Category::query()->delete();
        $this->notes[] = "Replaced: removed {$categories} categories and {$items} items of the current menu (branches kept).";
    }

    // ---------------------------------------------------------------- settings

    /**
     * Maps the legacy settings table onto the restaurant settings and returns the values branches fall back on.
     *
     * @return array{address:string, phones:string, hours:string}
     */
    private function importSettings(): array
    {
        $legacy = [];
        foreach ($this->rows('settings') as $row) {
            $legacy[(string) ($row['key'] ?? '')] = trim((string) ($row['value'] ?? ''));
        }
        $rights = Entitlements::current();
        $values = [];
        $handled = [];
        $take = function (string $key) use (&$handled, $legacy): string {
            $handled[] = $key;

            return $legacy[$key] ?? '';
        };

        if (($name = $take('restaurant_name')) !== '') {
            $values['brand_name'] = Str::limit($name, 80, '');
            if (preg_match('/[A-Za-z]/', $name) && ($legacy['restaurant_name_en'] ?? '') === '') {
                $values['brand_name_en'] = Str::limit($name, 80, '');
            }
        }
        if (($nameEn = $take('restaurant_name_en')) !== '') {
            $values['brand_name_en'] = Str::limit($nameEn, 80, '');
        }
        if (($subtitle = $take('subtitle')) !== '') {
            $values['tagline'] = Str::limit($subtitle, 150, '');
            if (preg_match('/[A-Za-z]/', $subtitle) && ($legacy['subtitle_en'] ?? '') === '') {
                $values['tagline_en'] = Str::limit($subtitle, 150, '');
            }
        }
        if (($subtitleEn = $take('subtitle_en')) !== '') {
            $values['tagline_en'] = Str::limit($subtitleEn, 150, '');
        }
        foreach (['currency' => 'currency', 'currency_en' => 'currency_en'] as $from => $to) {
            if (($v = $take($from)) !== '') {
                $values[$to] = Str::limit($v, 10, '');
            }
        }
        $colors = [];
        foreach (['color_primary' => 'color_primary', 'color_secondary' => 'color_secondary', 'color_bg' => 'color_bg'] as $from => $to) {
            $v = $take($from);
            if (preg_match('/^#[0-9a-fA-F]{6}$/', $v)) {
                $colors[$to] = strtoupper($v);
            } elseif ($v !== '') {
                $this->unmappedSettings[$from] = $v;
            }
        }
        if ($colors && $rights->allows('full_branding')) {
            $values += $colors;
        } elseif ($colors) {
            $this->notes[] = 'Colours not applied: the plan has no full_branding ('.implode(', ', $colors).').';
        }

        $phones = $take('phones');
        if ($phones !== '') {
            $values['phone'] = $this->firstPhone($phones);
        }
        if (($wa = preg_replace('/\D/', '', $take('whatsapp'))) !== '') {
            $values['whatsapp'] = substr($wa, 0, 20);
        }
        if (($waOn = $take('whatsapp_enabled')) !== '') {
            $values['whatsapp_ordering'] = $waOn === '1' ? '1' : '0';
        }
        foreach (['service_percent', 'tax_percent'] as $key) {
            $v = $take($key);
            if (is_numeric($v) && (float) $v >= 0 && (float) $v <= 30) {
                $values[$key] = (string) (float) $v;
            } elseif ($v !== '') {
                $this->unmappedSettings[$key] = $v;
            }
        }
        foreach (['google_review_url', 'instagram', 'facebook', 'tiktok'] as $key) {
            $v = $take($key);
            if ($v !== '' && filter_var($v, FILTER_VALIDATE_URL)) {
                $values[$key] = $v;
            } elseif ($v !== '') {
                $this->unmappedSettings[$key] = $v;
            }
        }
        // "social" is a free text field: links are sorted by network, anything else is reported
        $rest = [];
        foreach (preg_split('/[\s,|]+/', $take('social')) ?: [] as $link) {
            $network = match (true) {
                $link === '' => null,
                (bool) preg_match('~instagram\.com~i', $link) => 'instagram',
                (bool) preg_match('~(facebook\.com|fb\.com|fb\.me)~i', $link) => 'facebook',
                (bool) preg_match('~tiktok\.com~i', $link) => 'tiktok',
                default => false,
            };
            if ($network && filter_var($link, FILTER_VALIDATE_URL) && ! isset($values[$network])) {
                $values[$network] = $link;
            } elseif ($network !== null) {
                $rest[] = $link;
            }
        }
        if ($rest) {
            $this->unmappedSettings['social'] = implode(' ', $rest);
        }

        $logo = $take('logo_url');
        $cover = $take('cover_url');
        $fallback = ['address' => $take('address'), 'phones' => $phones, 'hours' => ''];
        foreach (['working_hours', 'opening_hours', 'hours'] as $key) {
            if (($v = $take($key)) !== '' && $fallback['hours'] === '') {
                $fallback['hours'] = $v;
            }
        }

        foreach ($legacy as $key => $value) {
            if (! in_array($key, $handled, true) && $value !== '' && ! in_array($key, self::SILENT_SETTINGS, true)
                && ! str_ends_with($key, '_seeded') && ! isset($this->unmappedSettings[$key])) {
                $this->unmappedSettings[$key] = Str::limit($value, 120);
            }
        }

        foreach (['logo' => $this->options['logo'] ?: $logo, 'cover' => $cover] as $key => $url) {
            if ($url === '' || $url === null) {
                continue;
            }
            $path = $this->importImage((string) $url, $key, $key === 'logo');
            if ($path !== null) {
                $old = Settings::get($key);
                $values[$key] = $path;
                if ($old !== '' && ! $this->options['dry_run']) {
                    $this->replacedImages[] = $old;
                }
            }
        }

        if ($values !== []) {
            Settings::put($values);
        }
        $this->counts['settings']['created'] = count($values);
        $this->counts['settings']['unmapped'] = count($this->unmappedSettings);

        return $fallback;
    }

    // ---------------------------------------------------------------- menu

    /** @return array<int, Category> legacy category id => EgMenu category */
    private function importCategories(): array
    {
        $existing = Category::query()->get()->keyBy(fn (Category $c) => $this->key($c->name));
        $order = (int) Category::query()->max('sort_order');
        $map = [];
        foreach ($this->rows('categories', 'sort_order, id') as $row) {
            $name = $this->text($row['title_ar'] ?? '') ?: $this->text($row['title_en'] ?? '');
            if ($name === '') {
                $this->counts['categories']['unmapped']++;

                continue;
            }
            if ($found = $existing[$this->key($name)] ?? null) {
                $map[(int) $row['id']] = $found;
                $this->counts['categories']['skipped']++;

                continue;
            }
            $category = Category::query()->create([
                'name' => Str::limit($name, 255, ''),
                'name_en' => Str::limit($this->text($row['title_en'] ?? ''), 255, ''),
                'description' => Str::limit($this->text($row['subtitle'] ?? ''), 255, ''),
                'description_en' => Str::limit($this->text($row['subtitle_en'] ?? ''), 255, ''),
                'sort_order' => ++$order,
            ]);
            $existing[$this->key($name)] = $category;
            $map[(int) $row['id']] = $category;
            $this->counts['categories']['created']++;
        }

        return $map;
    }

    /**
     * @param  array<int, Category>  $categories
     * @return array<int, array{item: MenuItem, created: bool}> legacy item id => EgMenu item
     */
    private function importItems(array $categories): array
    {
        $rows = $this->rows('items', 'category_id, sort_order, id');
        $existing = MenuItem::query()->get()->groupBy('category_id')
            ->map(fn ($items) => $items->keyBy(fn (MenuItem $i) => $this->key($i->name)));

        $plan = [];
        $orphans = 0;
        foreach ($rows as $row) {
            $category = $categories[(int) ($row['category_id'] ?? 0)] ?? null;
            $name = $this->text($row['name_ar'] ?? '') ?: $this->text($row['name_en'] ?? '');
            if (! $category || $name === '') {
                $orphans++;

                continue;
            }
            $plan[] = [$row, $category, $name, $existing[$category->id][$this->key($name)] ?? null];
        }
        if ($orphans) {
            $this->counts['items']['unmapped'] += $orphans;
            $this->notes[] = "{$orphans} legacy items had no name or no (known) category and were left out.";
        }

        $toCreate = count(array_filter($plan, fn ($p) => $p[3] === null));
        $rights = Entitlements::current();
        if (! $rights->canAdd('menu_items', $toCreate)) {
            throw new RuntimeException("The plan allows {$rights->limit('menu_items')} items; this import adds {$toCreate} to the {$rights->usage('menu_items')} already there.");
        }

        $type = Settings::get('business_type');
        $unknownBadges = [];
        $suggested = 0;
        $map = [];
        $orders = [];
        foreach ($plan as [$row, $category, $name, $found]) {
            if ($found) {
                $map[(int) $row['id']] = ['item' => $found, 'created' => false];
                $this->counts['items']['skipped']++;

                continue;
            }
            $badges = [];
            foreach (array_filter(array_map('trim', explode(',', (string) ($row['badges'] ?? '')))) as $badge) {
                $key = isset(MenuItem::TAGS[$badge]) ? $badge
                    : (self::BADGE_ALIASES[$badge][$type] ?? self::BADGE_ALIASES[$badge]['default'] ?? null);
                if ($key) {
                    $badges[] = $key;
                } else {
                    $unknownBadges[$badge] = ($unknownBadges[$badge] ?? 0) + 1;
                }
            }
            $suggested += (int) ($row['is_suggested'] ?? 0) === 1 ? 1 : 0;
            $orders[$category->id] ??= (int) MenuItem::query()->where('category_id', $category->id)->max('sort_order');

            $item = MenuItem::query()->create([
                'category_id' => $category->id,
                'name' => Str::limit($name, 255, ''),
                'name_en' => Str::limit($this->text($row['name_en'] ?? ''), 255, ''),
                'description' => $this->text($row['note'] ?? '') ?: null,
                'description_en' => $this->text($row['note_en'] ?? '') ?: null,
                'price' => max(0, (float) ($row['price'] ?? 0)),
                'image' => $this->importImage((string) ($row['image_url'] ?? ''), 'item'),
                'badges' => $badges ? array_values(array_unique($badges)) : null,
                'is_active' => (int) ($row['status'] ?? 1) !== 0,
                'is_sold_out' => (int) ($row['is_sold_out'] ?? 0) === 1,
                'is_featured' => (int) ($row['is_featured'] ?? 0) === 1,
                'sort_order' => ++$orders[$category->id],
            ]);
            $existing[$category->id] ??= collect();
            $existing[$category->id][$this->key($name)] = $item;
            $map[(int) $row['id']] = ['item' => $item, 'created' => true];
            $this->counts['items']['created']++;
        }
        foreach ($unknownBadges as $badge => $n) {
            $this->notes[] = "Badge \"{$badge}\" ({$n} items) has no EgMenu badge and was dropped.";
        }
        foreach (self::BADGE_ALIASES as $legacyKey => $to) {
            $n = count(array_filter($rows, fn ($r) => in_array($legacyKey, array_map('trim', explode(',', (string) ($r['badges'] ?? ''))), true)));
            if ($n) {
                $this->notes[] = "Badge \"{$legacyKey}\" ({$n} items) became \"".($to[$type] ?? $to['default']).'".';
            }
        }
        if ($suggested) {
            $this->notes[] = "{$suggested} items were marked \"suggested\" (is_suggested): EgMenu has no such flag; featured is kept.";
        }

        return $map;
    }

    /**
     * item_options kind=size → a required single-choice "Size" group, kind=addon → an optional "Extras" group.
     *
     * @param  array<int, array{item: MenuItem, created: bool}>  $items
     */
    private function importOptions(array $items): void
    {
        $byItem = [];
        foreach ($this->rows('item_options', 'item_id, sort_order, id') as $row) {
            $byItem[(int) ($row['item_id'] ?? 0)][($row['kind'] ?? 'addon') === 'size' ? 'sizes' : 'extras'][] = $row;
        }
        foreach ($byItem as $legacyId => $kinds) {
            $target = $items[$legacyId] ?? null;
            foreach ($kinds as $kind => $choices) {
                if (! $target) {
                    $this->counts[$kind]['unmapped'] += count($choices);

                    continue;
                }
                if (! $target['created']) {
                    $this->counts[$kind]['skipped'] += count($choices);

                    continue;
                }
                $isSize = $kind === 'sizes';
                $group = ItemOptionGroup::query()->create([
                    'menu_item_id' => $target['item']->id,
                    'name' => $isSize ? 'الحجم' : 'إضافات', 'name_en' => $isSize ? 'Size' : 'Extras',
                    'type' => $isSize ? 'single' : 'multiple', 'is_required' => $isSize, 'sort_order' => $isSize ? 0 : 1,
                ]);
                foreach (array_values($choices) as $j => $choice) {
                    ItemOption::query()->create([
                        'item_option_group_id' => $group->id,
                        'name' => Str::limit($this->text($choice['name_ar'] ?? '') ?: $this->text($choice['name_en'] ?? ''), 255, ''),
                        'name_en' => Str::limit($this->text($choice['name_en'] ?? ''), 255, ''),
                        'price' => (float) ($choice['price'] ?? 0),
                        'is_default' => $isSize && $j === 0,
                        'sort_order' => $j,
                    ]);
                    $this->counts[$kind]['created']++;
                }
            }
        }
    }

    /**
     * "Goes well with" (item_pairings) and combo contents (combo_items) between imported items.
     *
     * @param  array<int, array{item: MenuItem, created: bool}>  $items
     */
    private function importPairings(array $items): void
    {
        $pivot = ['tenant_id' => tenant()->getTenantKey()];
        foreach ([
            'pairings' => ['item_pairings', 'item_id', 'paired_id', 'pairings'],
            'combos' => ['combo_items', 'combo_id', 'item_id', 'comboParts'],
        ] as $entity => [$table, $fromCol, $toCol, $relation]) {
            foreach ($this->rows($table) as $row) {
                $from = $items[(int) ($row[$fromCol] ?? 0)]['item'] ?? null;
                $to = $items[(int) ($row[$toCol] ?? 0)]['item'] ?? null;
                if (! $from || ! $to || $from->is($to)) {
                    $this->counts[$entity]['unmapped']++;

                    continue;
                }
                $extra = $entity === 'combos' ? ['quantity' => max(1, min(255, (int) ($row['qty'] ?? 1)))] : [];
                $result = $from->{$relation}()->syncWithoutDetaching([$to->id => $pivot + $extra]);
                $this->counts[$entity][$result['attached'] ? 'created' : 'skipped']++;
            }
        }
    }

    // ---------------------------------------------------------------- branches

    /**
     * Legacy branches become EgMenu branches up to the plan's branch limit. A restaurant that still has only the
     * starter branch made at sign-up (slug "main") hands that branch to the first legacy branch instead of keeping an
     * empty one next to it. Without a legacy branches table the restaurant-wide address/phones fill the main branch.
     *
     * @param  array{address:string, phones:string, hours:string}  $fallback
     * @return array<int, Branch> legacy branch id => EgMenu branch
     */
    private function importBranches(array $fallback): array
    {
        $rows = $this->rows('branches', 'sort_order, id');
        $existing = Branch::query()->orderBy('sort_order')->orderBy('id')->get();
        if (! $this->hasTable('branches')) {
            $this->fillEmpty($existing->first(), $fallback);

            return [];
        }

        $match = function (array $row) use (&$existing): ?Branch {
            $slug = $this->slug($row);
            $names = array_filter([$this->key($row['name_ar'] ?? ''), $this->key($row['name_en'] ?? '')]);

            return $existing->first(fn (Branch $b) => $b->slug === $slug || in_array($this->key($b->name), $names, true) || ($b->name_en !== '' && in_array($this->key($b->name_en), $names, true)));
        };
        $starter = $existing->count() === 1 && $existing->first()->slug === 'main' && ! collect($rows)->contains(fn ($r) => $match($r)?->slug === 'main')
            ? $existing->first() : null;

        $rights = Entitlements::current();
        $limit = $rights->limit('branches');
        $free = $limit === null ? PHP_INT_MAX : max(0, $limit - $rights->usage('branches'));
        $order = (int) Branch::query()->max('sort_order');
        $map = [];
        $left = [];
        foreach ($rows as $row) {
            $name = $this->text($row['name_ar'] ?? '') ?: $this->text($row['name_en'] ?? '');
            if ($name === '') {
                $this->counts['branches']['unmapped']++;

                continue;
            }
            if ($found = $match($row)) {
                $map[(int) $row['id']] = $found;
                $this->counts['branches']['skipped']++;

                continue;
            }
            $phones = $this->text($row['phones'] ?? '') ?: $fallback['phones'];
            $values = [
                'name' => Str::limit($name, 255, ''),
                'name_en' => Str::limit($this->text($row['name_en'] ?? ''), 255, ''),
                'slug' => $this->slug($row),
                'address' => Str::limit($this->text($row['address'] ?? '') ?: $fallback['address'], 255, ''),
                'phone' => $phones !== '' ? $this->firstPhone($phones) : '',
                'whatsapp' => ($wa = substr((string) preg_replace('/\D/', '', (string) ($row['whatsapp'] ?? '')), 0, 20)) !== '' ? $wa : null,
                'working_hours' => Str::limit($fallback['hours'], 255, ''),
                'lat' => is_numeric($row['lat'] ?? null) ? (float) $row['lat'] : null,
                'lng' => is_numeric($row['lng'] ?? null) ? (float) $row['lng'] : null,
                'is_active' => (int) ($row['status'] ?? 1) !== 0,
            ];
            if (str_contains($phones, '/') || str_contains($phones, ',')) {
                $this->notes[] = "Branch \"{$name}\": only the first of the phones \"{$phones}\" was kept.";
            }
            if ($row['whatsapp_enabled'] ?? null) {
                $this->notes[] = "Branch \"{$name}\": its own WhatsApp on/off switch (whatsapp_enabled) has no EgMenu equivalent.";
            }
            if ($starter) {
                $starter->update($values);
                $this->notes[] = "The starter branch (main) became \"{$name}\" (slug {$values['slug']}).";
                $branch = $starter;
                $starter = null;
            } elseif ($free > 0) {
                $branch = Branch::query()->create($values + ['sort_order' => ++$order]);
                $free--;
            } else {
                $left[] = $name;
                $this->counts['branches']['unmapped']++;

                continue;
            }
            $existing->push($branch);
            $map[(int) $row['id']] = $branch;
            $this->counts['branches']['created']++;
        }
        if ($left) {
            $this->notes[] = 'Branch limit of the plan ('.($limit ?? '∞').') reached: not created: '.implode('، ', $left).'.';
        }

        return $map;
    }

    /**
     * Legacy rule: in a branch an item exists only with a branch_items row with is_available=1, and a null price means
     * the main price. EgMenu's rule: no row = same as the main menu. So only the differences become rows.
     *
     * @param  array<int, Branch>  $branches
     * @param  array<int, array{item: MenuItem, created: bool}>  $items
     */
    private function importBranchOverrides(array $branches, array $items): void
    {
        if (! $branches || ! $this->hasTable('branch_items')) {
            return;
        }
        $legacy = [];
        foreach ($this->rows('branch_items') as $row) {
            $legacy[(int) $row['branch_id']][(int) $row['item_id']] = $row;
        }
        $existing = BranchItem::query()->get()->keyBy(fn (BranchItem $b) => $b->branch_id.':'.$b->menu_item_id);
        $prices = 0;
        foreach ($branches as $legacyBranch => $branch) {
            foreach ($items as $legacyItem => ['item' => $item]) {
                $row = $legacy[$legacyBranch][$legacyItem] ?? null;
                $available = $row !== null && (int) ($row['is_available'] ?? 1) === 1;
                $price = $row !== null && is_numeric($row['price'] ?? null) && abs((float) $row['price'] - $item->price) > 0.001 ? (float) $row['price'] : null;
                $soldOut = $row !== null && (int) ($row['is_sold_out'] ?? 0) === 1;
                if ($available && $price === null && ! $soldOut) {
                    continue; // same as the main menu
                }
                if ($existing->has($branch->id.':'.$item->id)) {
                    $this->counts['branch_overrides']['skipped']++;

                    continue;
                }
                BranchItem::query()->create(['branch_id' => $branch->id, 'menu_item_id' => $item->id, 'price' => $price, 'is_available' => $available, 'is_sold_out' => $soldOut]);
                $prices += $price !== null ? 1 : 0;
                $this->counts['branch_overrides']['created']++;
            }
        }
        if ($this->counts['branch_overrides']['created'] && ! Entitlements::current()->allows('branch_pricing')) {
            $this->notes[] = "{$this->counts['branch_overrides']['created']} branch overrides ({$prices} prices) were saved, but the plan has no per-branch prices (branch_pricing): the menu shows the main prices until it does.";
        }
    }

    private function reportUnmappedTables(): void
    {
        foreach (['time_offers' => 'timed offers', 'popups' => 'popups'] as $table => $label) {
            $n = count($this->rows($table));
            if ($n) {
                $this->notes[] = "{$n} legacy {$label} ({$table}) were not imported: set them up again in the dashboard.";
            }
        }
    }

    // ---------------------------------------------------------------- images

    /**
     * Stores a legacy picture through the dashboard's image pipeline (GD → WebP under tenants/{id}/...). Relative
     * paths are read from the legacy folder, else downloaded from --images-base-url; absolute URLs are downloaded.
     * Returns the stored path, or null (nothing stored on a dry run; failures are reported).
     */
    private function importImage(string $url, string $profile, bool $isLogo = false): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }
        if (! $this->options['images']) {
            $this->counts['images']['unmapped']++;

            return null;
        }
        $source = $this->locateImage($url, $isLogo);
        if ($source === null) {
            $this->counts['images']['unmapped']++;
            $this->notes[] = "Image not found: {$url}".($this->options['images_base_url'] ? '' : ' (not in the folder; pass --images-base-url to download it)');

            return null;
        }
        if (str_ends_with(strtolower(parse_url($source, PHP_URL_PATH) ?: $source), '.svg')) {
            $this->counts['images']['unmapped']++;
            $this->notes[] = "SVG not accepted by the image pipeline (raster only: JPG/PNG/WebP): {$url}".($isLogo ? ' — upload a PNG logo in the dashboard or pass --logo=file.png' : '');

            return null;
        }
        if ($this->options['dry_run']) {
            $this->counts['images']['created']++;

            return null;
        }

        $temp = null;
        try {
            if (preg_match('~^https?://~i', $source)) {
                $temp = $this->download($source);
                $file = $temp;
            } else {
                if (filesize($source) > self::MAX_IMAGE_BYTES) {
                    throw new RuntimeException('larger than 5 MB');
                }
                $file = $source;
            }
            $path = $this->images->store(new UploadedFile($file, basename($file), null, null, true), $profile);
            $this->storedImages[] = $path;
            $this->counts['images']['created']++;

            return $path;
        } catch (\Throwable $e) {
            $this->counts['images']['unmapped']++;
            $this->notes[] = "Image skipped ({$url}): ".($e instanceof ValidationException ? 'not a JPG/PNG/WebP up to 5 MB' : $e->getMessage());

            return null;
        } finally {
            if ($temp) {
                @unlink($temp);
            }
        }
    }

    /** A local file inside the legacy folder (or the --logo file), or a URL to download; null when there is none. */
    private function locateImage(string $url, bool $isLogo): ?string
    {
        if (preg_match('~^https?://~i', $url)) {
            return $url;
        }
        if ($isLogo && $this->options['logo'] === $url) {
            return is_file($url) ? $url : null;
        }
        $relative = ltrim(str_replace('\\', '/', parse_url($url, PHP_URL_PATH) ?: $url), '/');
        $candidates = [$relative];
        // an SVG logo may have a raster twin next to it (logo.png / logo.webp)
        if ($isLogo && str_ends_with(strtolower($relative), '.svg')) {
            $base = substr($relative, 0, -4);
            array_unshift($candidates, "$base.png", "$base.webp", "$base.jpg", "$base.jpeg");
        }
        $root = realpath($this->folder);
        foreach ($candidates as $candidate) {
            $full = realpath($this->folder.'/'.$candidate);
            if ($full && $root && str_starts_with($full, $root.DIRECTORY_SEPARATOR) && is_file($full)) {
                return $full;
            }
        }
        if ($this->options['images_base_url'] && ! str_contains($relative, '..')) {
            return $this->options['images_base_url'].'/'.implode('/', array_map('rawurlencode', explode('/', $relative)));
        }

        return null;
    }

    /** Downloads one image into a temp file: http(s) only, a timeout, image/* only (no SVG), at most 5 MB. */
    private function download(string $url): string
    {
        $response = Http::timeout(self::DOWNLOAD_TIMEOUT)->connectTimeout(5)->accept('image/*')->get($url);
        if (! $response->successful()) {
            throw new RuntimeException('HTTP '.$response->status());
        }
        $type = strtolower((string) $response->header('Content-Type'));
        if (! str_starts_with($type, 'image/') || str_contains($type, 'svg')) {
            throw new RuntimeException("not an image ({$type})");
        }
        if ((int) $response->header('Content-Length') > self::MAX_IMAGE_BYTES || strlen($response->body()) > self::MAX_IMAGE_BYTES) {
            throw new RuntimeException('larger than 5 MB');
        }
        $temp = tempnam(sys_get_temp_dir(), 'legacy-img');
        file_put_contents($temp, $response->body());

        return $temp;
    }

    // ---------------------------------------------------------------- legacy reading

    /** @return list<array<string, mixed>> all rows of a legacy table ([] when the table does not exist) */
    private function rows(string $table, ?string $orderBy = null): array
    {
        if (! $this->hasTable($table)) {
            return [];
        }
        $columns = array_column($this->legacy->query("PRAGMA table_info(\"$table\")")->fetchAll(), 'name');
        $order = collect(explode(',', (string) $orderBy))->map(fn ($c) => trim($c))->filter(fn ($c) => in_array($c, $columns, true))
            ->map(fn ($c) => "\"$c\"")->implode(', ');

        return $this->legacy->query("SELECT * FROM \"$table\"".($order !== '' ? " ORDER BY $order" : ''))->fetchAll();
    }

    private function hasTable(string $table): bool
    {
        $q = $this->legacy->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?");
        $q->execute([$table]);

        return (bool) $q->fetchColumn();
    }

    // ---------------------------------------------------------------- helpers

    /** @param  array{address:string, phones:string, hours:string}  $fallback */
    private function fillEmpty(?Branch $branch, array $fallback): void
    {
        if (! $branch) {
            return;
        }
        $values = array_filter([
            'address' => $branch->address === '' ? Str::limit($fallback['address'], 255, '') : '',
            'phone' => $branch->phone === '' && $fallback['phones'] !== '' ? $this->firstPhone($fallback['phones']) : '',
            'working_hours' => $branch->working_hours === '' ? Str::limit($fallback['hours'], 255, '') : '',
        ]);
        if ($values) {
            $branch->update($values);
            $this->notes[] = 'No legacy branches: the restaurant address/phone went to the branch "'.$branch->name.'".';
        }
    }

    /** @param  array<string, mixed>  $row */
    private function slug(array $row): string
    {
        $slug = Str::limit(Str::slug((string) ($row['slug'] ?? '') ?: (string) ($row['name_en'] ?? '')), 40, '');

        return $slug !== '' ? $slug : 'branch-'.(int) ($row['id'] ?? 0);
    }

    private function firstPhone(string $phones): string
    {
        $first = trim((string) (preg_split('/[\/,|،]+/u', $phones)[0] ?? ''));

        return substr((string) preg_replace('/[^0-9+]/', '', $first), 0, 20);
    }

    private function text(mixed $value): string
    {
        return trim((string) ($value ?? ''));
    }

    private function key(mixed $name): string
    {
        return mb_strtolower(trim((string) ($name ?? '')));
    }
}
