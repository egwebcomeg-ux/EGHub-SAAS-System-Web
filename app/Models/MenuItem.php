<?php

namespace App\Models;

use App\Models\Concerns\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A dish/drink on the menu. Hidden when inactive; visible but not orderable when sold out. */
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

    /** @return list<array{key:string, icon:string, label:string}> */
    public function tags(): array
    {
        $en = app()->getLocale() === 'en';

        return collect($this->badges ?? [])->filter(fn ($k) => isset(self::TAGS[$k]))->values()
            ->map(fn ($k) => ['key' => $k, 'icon' => self::TAGS[$k][0], 'label' => self::TAGS[$k][$en ? 2 : 1]])->all();
    }

    /** Name for the current language, including an extra menu language (see tx()). */
    public function displayName(): string
    {
        return tx($this, 'name');
    }
}
