<?php

namespace App\Models;

use App\Models\Concerns\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A self-order tablet at the counter (kiosk pack). It stays open on {menu}/kiosk/{token}; the token is random so
 * the link can't be guessed, and a new one retires the old link. Orders land in the kiosk's branch with source kiosk.
 */
class Kiosk extends TenantModel
{
    public const ORDER_TYPES = ['takeaway', 'dine_in'];

    /** @var array{idle_seconds:int, order_types:list<string>, require_phone:bool, print_number:bool} */
    public const DEFAULTS = ['idle_seconds' => 60, 'order_types' => ['takeaway'], 'require_phone' => false, 'print_number' => false];

    protected function casts(): array
    {
        return ['settings' => 'array', 'is_active' => 'boolean'];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return array{idle_seconds:int, order_types:list<string>, require_phone:bool, print_number:bool} */
    public function options(): array
    {
        $s = array_merge(self::DEFAULTS, (array) $this->settings);
        $types = array_values(array_intersect(self::ORDER_TYPES, (array) $s['order_types']));

        return [
            'idle_seconds' => max(15, min(600, (int) $s['idle_seconds'])),
            'order_types' => $types ?: ['takeaway'],
            'require_phone' => (bool) $s['require_phone'],
            'print_number' => (bool) $s['print_number'],
        ];
    }

    public function url(): string
    {
        return tenant()->url('kiosk/'.$this->token);
    }
}
