<?php

namespace App\Models;

use App\Models\Concerns\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** "Every delivery order takes 1 delivery bag": packaging taken once per order of a type, with the order's recipes. */
class PackagingRule extends TenantModel
{
    /** order type => [arabic, english] */
    public const ORDER_TYPES = [
        'takeaway' => ['كل طلب تيك أواي', 'Per takeaway order'],
        'delivery' => ['كل طلب توصيل', 'Per delivery order'],
    ];

    protected function casts(): array
    {
        return ['quantity' => 'float'];
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function orderTypeLabel(): string
    {
        $l = self::ORDER_TYPES[$this->order_type] ?? [$this->order_type, $this->order_type];

        return app()->getLocale() === 'en' ? $l[1] : $l[0];
    }
}
