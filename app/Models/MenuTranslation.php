<?php

namespace App\Models;

use App\Models\Concerns\TenantModel;
use Illuminate\Database\Eloquent\Model;

/** Name/description of a menu row in an extra language (App\Support\MenuLocales). */
class MenuTranslation extends TenantModel
{
    /** translatable_type => model class */
    public const TYPES = [
        'category' => Category::class,
        'item' => MenuItem::class,
        'group' => ItemOptionGroup::class,
        'option' => ItemOption::class,
    ];

    /** The translatable_type of a model ("item" for a MenuItem), null when it cannot be translated. */
    public static function typeOf(Model $model): ?string
    {
        $type = array_search($model::class, self::TYPES, true);

        return $type === false ? null : $type;
    }
}
