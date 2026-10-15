<?php

namespace App\Models;

use App\Models\Concerns\TenantModel;

/** A discount code a guest types when ordering (App\Services\Orders\Coupons). Codes are stored upper-case. */
class Coupon extends TenantModel
{
    protected function casts(): array
    {
        return [
            'value' => 'float', 'max_discount' => 'float', 'min_order' => 'float', 'expires_at' => 'datetime',
            'max_uses' => 'integer', 'uses' => 'integer', 'once_per_customer' => 'boolean', 'is_active' => 'boolean',
        ];
    }

    public static function normalize(string $code): string
    {
        return mb_strtoupper(trim($code));
    }

    /** "20%" or "50 EGP" */
    public function label(): string
    {
        return $this->type === 'percent' ? money($this->value).'%' : money($this->value).' '.__('EGP');
    }
}
