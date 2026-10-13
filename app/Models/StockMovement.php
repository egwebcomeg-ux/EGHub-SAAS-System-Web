<?php

namespace App\Models;

use App\Models\Concerns\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One change of stock: purchase, sale (order), return (cancelled order), waste or a count correction. */
class StockMovement extends TenantModel
{
    public const TYPES = [
        'purchase' => ['استلام / شراء', 'Purchase'],
        'sale'     => ['بيع', 'Sale'],
        'return'   => ['رجوع طلب ملغي', 'Cancelled order returned'],
        'waste'    => ['هالك', 'Waste'],
        'count'    => ['جرد', 'Stock count'],
    ];

    protected function casts(): array
    {
        return ['quantity' => 'float', 'unit_cost' => 'float'];
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function typeLabel(): string
    {
        $l = self::TYPES[$this->type] ?? [$this->type, $this->type];
        return app()->getLocale() === 'en' ? $l[1] : $l[0];
    }
}
