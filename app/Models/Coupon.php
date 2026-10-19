<?php

namespace App\Models;

use App\Models\Concerns\TenantModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A discount code a guest types when ordering (App\Services\Orders\Coupons). Codes are stored upper-case.
 * Made by a campaign or an automation, or a loyalty voucher: one code (optionally held by one guest) or a
 * printed batch of single-use codes.
 */
class Coupon extends TenantModel
{
    public const SOURCES = ['campaign', 'automation', 'voucher', 'batch'];

    /** What the coupons page calls each code: kind => the sources behind it (a voucher splits by whether a guest holds it). */
    public const KINDS = ['campaign' => ['campaign', 'automation'], 'guest' => ['voucher'], 'batch' => ['batch'], 'public' => ['voucher']];

    /** Letters and digits that can't be misread on paper (no 0/O, 1/I/L). */
    private const CODE_ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    protected function casts(): array
    {
        return [
            'value' => 'float', 'max_discount' => 'float', 'min_order' => 'float', 'expires_at' => 'datetime', 'starts_at' => 'datetime',
            'max_uses' => 'integer', 'uses' => 'integer', 'once_per_customer' => 'boolean', 'is_active' => 'boolean', 'qualifying' => 'array',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /** The campaigns that sent this code (a campaign coupon, or a public one picked from the coupons list). */
    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class);
    }

    /** One kind of the coupons page (Coupon::KINDS). */
    public function scopeOfKind(Builder $query, string $kind): void
    {
        $query->whereIn('source', self::KINDS[$kind] ?? ['-']);
        if ($kind === 'guest') {
            $query->whereNotNull('customer_id');
        } elseif ($kind === 'public') {
            $query->whereNull('customer_id');
        }
    }

    /** campaign | guest | batch | public */
    public function kind(): string
    {
        return match ($this->source) {
            'campaign', 'automation' => 'campaign',
            'batch' => 'batch',
            default => $this->customer_id ? 'guest' : 'public',
        };
    }

    public static function kindLabel(string $kind): string
    {
        return [
            'campaign' => __('Campaign'), 'guest' => __('Voucher for a guest'), 'batch' => __('Printed batch'), 'public' => __('Public coupon'),
        ][$kind] ?? $kind;
    }

    /** Vouchers of the loyalty card (single or batch), not the campaign / automation codes. */
    public function scopeVouchers(Builder $query): void
    {
        $query->whereIn('source', ['voucher', 'batch']);
    }

    /** Still good to use now or later: on, not expired, uses left. */
    public function scopeUsable(Builder $query): void
    {
        $query->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->where(fn ($q) => $q->whereNull('max_uses')->orWhereColumn('uses', '<', 'max_uses'));
    }

    /** @return Collection<int, self> the guest's own vouchers still good to use (card page, counter desk) */
    public static function heldBy(Customer $customer): Collection
    {
        return self::query()->vouchers()->usable()->where('customer_id', $customer->id)->latest('id')->get();
    }

    public static function normalize(string $code): string
    {
        return mb_strtoupper(trim($code));
    }

    /** A readable code not used yet in this restaurant, e.g. ROSTO-7KQ2. */
    public static function generateCode(?string $prefix = null): string
    {
        $prefix = $prefix ?? self::defaultPrefix();
        for ($length = 4; ; $length++) {
            for ($try = 0; $try < 8; $try++) {
                $suffix = '';
                for ($i = 0; $i < $length; $i++) {
                    $suffix .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
                }
                $code = $prefix.'-'.$suffix;
                if (! self::query()->where('code', $code)->exists()) {
                    return $code;
                }
            }
        }
    }

    /** Up to 5 latin letters of the restaurant's address (slug), else EGM. */
    public static function defaultPrefix(): string
    {
        $letters = preg_replace('/[^A-Z]/', '', strtoupper((string) tenant()?->getTenantKey()));

        return substr($letters ?: 'EGM', 0, 5);
    }

    /** "20%" or "50 EGP" */
    public function label(): string
    {
        return $this->type === 'percent' ? money($this->value).'%' : money($this->value).' '.__('EGP');
    }

    /** "20% off" / "50 EGP off" — the big line of a printed voucher. */
    public function headline(): string
    {
        return $this->type === 'percent'
            ? __(':value% off', ['value' => money($this->value)])
            : __(':value EGP off', ['value' => money($this->value)]);
    }

    /**
     * The conditions in one line: minimum order, cap, qualifying items, once per guest.
     *
     * @param  array<int,string>|null  $names  names of the qualifying categories and items, when already loaded
     */
    public function conditions(?array $names = null): string
    {
        $parts = [];
        if ($this->min_order > 0) {
            $parts[] = __('on orders from :amount EGP', ['amount' => money($this->min_order)]);
        }
        if ($this->type === 'percent' && $this->max_discount) {
            $parts[] = __('up to :amount EGP', ['amount' => money($this->max_discount)]);
        }
        $names ??= $this->qualifyingNames();
        if ($names !== []) {
            $parts[] = __('on :items only', ['items' => implode('، ', array_slice($names, 0, 4)).(count($names) > 4 ? '…' : '')]);
        }
        if ($this->max_uses === 1) {
            $parts[] = __('one use');
        } elseif ($this->once_per_customer) {
            $parts[] = __('once per guest');
        }

        return implode(' · ', $parts);
    }

    /** @return list<string> */
    public function qualifyingNames(): array
    {
        $q = $this->qualifying ?? [];
        if (empty($q['categories']) && empty($q['items'])) {
            return [];
        }

        return array_values(array_merge(
            Category::query()->whereIn('id', $q['categories'] ?? [])->get()->map(fn (Category $c) => tr($c->name, $c->name_en))->all(),
            MenuItem::query()->whereIn('id', $q['items'] ?? [])->get()->map(fn (MenuItem $i) => $i->displayName())->all(),
        ));
    }

    public function hasQualifying(): bool
    {
        return ! empty($this->qualifying['categories']) || ! empty($this->qualifying['items']);
    }

    /** active | paused | used | expired | scheduled */
    public function state(): string
    {
        return match (true) {
            ! $this->is_active => 'paused',
            $this->expires_at !== null && $this->expires_at->isPast() => 'expired',
            $this->max_uses !== null && $this->uses >= $this->max_uses => 'used',
            $this->starts_at !== null && $this->starts_at->isFuture() => 'scheduled',
            default => 'active',
        };
    }

    /** The menu with this code filled in the cart. */
    public function menuUrl(): string
    {
        return tenant()->url('?coupon='.rawurlencode($this->code));
    }
}
