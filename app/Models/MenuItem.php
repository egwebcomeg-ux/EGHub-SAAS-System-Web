<?php

namespace App\Models;

use App\Models\Concerns\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A dish/drink on the menu. Hidden when inactive; visible but not orderable when sold out. A sold-out may carry
 * `available_from`: once that moment passes the item counts as available again without anyone touching it.
 */
class MenuItem extends TenantModel
{
    protected function casts(): array
    {
        return [
            'price' => 'float',
            'cost' => 'float',
            'badges' => 'array',
            'allergens' => 'array',
            'is_active' => 'boolean',
            'is_sold_out' => 'boolean',
            'is_featured' => 'boolean',
            'available_from' => 'datetime',
            'spicy_level' => 'integer',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function optionGroups(): HasMany
    {
        return $this->hasMany(ItemOptionGroup::class)->orderBy('sort_order');
    }

    /** Items suggested with this one ("goes well with"). */
    public function pairings(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'item_pairings', 'menu_item_id', 'paired_item_id');
    }

    /** Parts of a combo with their quantity (pivot). Empty for a normal item. */
    public function comboParts(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'combo_items', 'combo_id', 'menu_item_id')->withPivot('quantity');
    }

    public function branchOverrides(): HasMany
    {
        return $this->hasMany(BranchItem::class);
    }

    /** Food labels shown on the menu (stored in `badges`): key => [icon, arabic, english]. */
    public const TAGS = [
        'vegetarian' => ['🌱', 'نباتي', 'Vegetarian'],
        'vegan' => ['🥬', 'نباتي صرف', 'Vegan'],
        'spicy' => ['🌶', 'حار', 'Spicy'],
        'new' => ['✨', 'جديد', 'New'],
        'bestseller' => ['🔥', 'الأكثر طلباً', 'Best seller'],
        'gluten_free' => ['🌾', 'خالي من الجلوتين', 'Gluten-free'],
        'nuts' => ['🥜', 'فيه مكسرات', 'Contains nuts'],
        'sugar_free' => ['🍃', 'من غير سكر', 'Sugar-free'],
    ];

    /** The fixed allergen list (stored as keys in `allergens`): key => [icon, arabic, english]. */
    public const ALLERGENS = [
        'gluten' => ['🌾', 'جلوتين', 'Gluten'],
        'dairy' => ['🥛', 'ألبان', 'Dairy'],
        'eggs' => ['🥚', 'بيض', 'Eggs'],
        'nuts' => ['🌰', 'مكسرات', 'Nuts'],
        'peanuts' => ['🥜', 'فول سوداني', 'Peanuts'],
        'sesame' => ['🫘', 'سمسم', 'Sesame'],
        'soy' => ['🫛', 'صويا', 'Soy'],
        'fish' => ['🐟', 'سمك', 'Fish'],
        'shellfish' => ['🦐', 'قشريات بحرية', 'Shellfish'],
        'celery' => ['🥬', 'كرفس', 'Celery'],
        'mustard' => ['🌭', 'مسطردة', 'Mustard'],
        'sulphites' => ['🍷', 'سلفيت', 'Sulphites'],
    ];

    /** Hotness 0 (not spicy) … 3. */
    public const MAX_SPICY = 3;

    /** Presets for "sold out until" (quick toggle and item form). */
    public const SOLD_OUT_UNTIL = ['', 'end_of_day', 'tomorrow', 'hours_2', 'custom'];

    /** @return list<array{key:string, icon:string, label:string}> */
    public function tags(): array
    {
        $en = app()->getLocale() === 'en';

        return collect($this->badges ?? [])->filter(fn ($k) => isset(self::TAGS[$k]))->values()
            ->map(fn ($k) => ['key' => $k, 'icon' => self::TAGS[$k][0], 'label' => self::TAGS[$k][$en ? 2 : 1]])->all();
    }

    /** @return list<array{key:string, icon:string, label:string}> */
    public function allergenList(): array
    {
        $en = app()->getLocale() === 'en';

        return collect($this->allergens ?? [])->filter(fn ($k) => is_string($k) && isset(self::ALLERGENS[$k]))->unique()->values()
            ->map(fn ($k) => ['key' => $k, 'icon' => self::ALLERGENS[$k][0], 'label' => self::ALLERGENS[$k][$en ? 2 : 1]])->all();
    }

    /** "🌶🌶" for level 2, "" for 0. */
    public function spicyIcons(): string
    {
        return str_repeat('🌶', max(0, min(self::MAX_SPICY, (int) $this->spicy_level)));
    }

    /**
     * Turns "gluten, dairy" / "جلوتين، ألبان" / "Gluten | Dairy" (keys or labels in either language) into keys.
     *
     * @return list<string>
     */
    public static function allergenKeys(string $text): array
    {
        $byLabel = [];
        foreach (self::ALLERGENS as $key => [, $ar, $en]) {
            $byLabel[$key] = $key;
            $byLabel[mb_strtolower($ar)] = $key;
            $byLabel[mb_strtolower($en)] = $key;
        }
        $out = [];
        foreach (preg_split('/\s*[|،,;\n]\s*/u', trim($text)) ?: [] as $part) {
            $key = $byLabel[mb_strtolower(trim($part))] ?? null;
            if ($key && ! in_array($key, $out, true)) {
                $out[] = $key;
            }
        }

        return $out;
    }

    /** Sold out right now: the flag is on and its "until" (if any) has not passed. */
    public function isSoldOutNow(): bool
    {
        return $this->is_sold_out && ! ($this->available_from && $this->available_from->isPast());
    }

    /** The moment a timed sold-out ends (null = until further notice, or not sold out). */
    public function soldOutUntil(): ?Carbon
    {
        return $this->isSoldOutNow() && $this->available_from ? $this->available_from : null;
    }

    /** When a sold-out marked now with $preset ends; null = until further notice. $custom is the local time typed. */
    public static function availableFromPreset(string $preset, ?string $custom = null): ?Carbon
    {
        return match ($preset) {
            'end_of_day' => now()->endOfDay(),
            'tomorrow' => now()->addDay()->startOfDay()->addHours(6), // back on the morning menu, not at midnight
            'hours_2' => now()->addHours(2),
            'custom' => $custom ? Carbon::parse($custom, config('app.timezone')) : null,
            default => null,
        };
    }

    /** Name for the current language, including an extra menu language (see tx()). */
    public function displayName(): string
    {
        return tx($this, 'name');
    }
}
