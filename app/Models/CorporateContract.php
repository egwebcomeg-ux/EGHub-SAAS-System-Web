<?php

namespace App\Models;

use App\Models\Concerns\TenantModel;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A company's order of prepaid vouchers ("تعاقد شركة"): e.g. 300 vouchers of 200 EGP for its staff, valid until a
 * date, in every branch or some, at some days / hours. The vouchers themselves are CorporateVoucher rows.
 */
class CorporateContract extends TenantModel
{
    /** mode => [arabic, english] */
    public const MODES = [
        'single' => ['مرة واحدة (الباقي بيضيع)', 'One use (the rest is lost)'],
        'balance' => ['رصيد (الباقي يفضل للمرة الجاية)', 'Balance (the rest stays for next time)'],
    ];

    /** status => [arabic, english] */
    public const STATUSES = [
        'active' => ['شغال', 'Active'],
        'paused' => ['متوقف مؤقتاً', 'Paused'],
        'void' => ['ملغي', 'Void'],
    ];

    /** paid status => [arabic, english] */
    public const PAID = [
        'unpaid' => ['مش مدفوع', 'Unpaid'],
        'partial' => ['مدفوع جزء', 'Partly paid'],
        'paid' => ['مدفوع', 'Paid'],
    ];

    /** billing => [arabic, english]: the company pays first and draws, or draws and pays later (credit_limit caps it) */
    public const BILLING = [
        'prepaid' => ['مدفوع مقدماً', 'Prepaid'],
        'account' => ['على الحساب (يسدد على دفعات)', 'On account (pays in instalments)'],
    ];

    protected function casts(): array
    {
        return [
            'voucher_value' => 'float', 'quantity' => 'integer', 'expires_on' => 'date', 'branch_ids' => 'array', 'days' => 'array',
            'min_order' => 'float', 'paid_amount' => 'float', 'credit_limit' => 'float', 'extra_value' => 'float',
            'booking_notice_hours' => 'integer',
        ];
    }

    public function payments(): HasMany
    {
        return $this->hasMany(CorporatePayment::class);
    }

    public function billingLabel(): string
    {
        return self::pick(self::BILLING, $this->billing);
    }

    public function vouchers(): HasMany
    {
        return $this->hasMany(CorporateVoucher::class);
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(CorporateVoucherRedemption::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** What the company is charged for the whole contract: the printed vouchers and the confirmed visits. */
    public function totalValue(): float
    {
        return round($this->voucher_value * $this->quantity + (float) $this->extra_value, 2);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(CorporateBooking::class);
    }

    /** The company's private page (book a visit, see its account), or null until a manager makes one. */
    public function portalUrl(): ?string
    {
        return $this->portal_token ? tenant()->url('company/'.$this->portal_token) : null;
    }

    public static function paidStatusFor(float $paid, float $total): string
    {
        return $paid <= 0 ? 'unpaid' : ($paid + 0.004 >= $total ? 'paid' : 'partial');
    }

    public function allowsBranch(int $branchId): bool
    {
        return empty($this->branch_ids) || in_array($branchId, array_map('intval', $this->branch_ids), true);
    }

    /** Inside the allowed days and hours (an overnight window like 20:00–02:00 works too). */
    public function allowsTime(CarbonInterface $at): bool
    {
        $at = local_at($at);
        if (! empty($this->days) && ! in_array($at->dayOfWeek, array_map('intval', $this->days), true)) {
            return false;
        }
        if (! $this->hours_from || ! $this->hours_to) {
            return true;
        }
        $now = $at->format('H:i');

        return $this->hours_from <= $this->hours_to
            ? $now >= $this->hours_from && $now <= $this->hours_to
            : $now >= $this->hours_from || $now <= $this->hours_to;
    }

    /** "Sun, Mon · 12:00–16:00" for the refusal and the print, empty when there is no window. */
    public function windowLabel(): string
    {
        $parts = [];
        if (! empty($this->days)) {
            $names = self::dayNames();
            $parts[] = implode('، ', array_map(fn ($d) => $names[(int) $d] ?? '', $this->days));
        }
        if ($this->hours_from && $this->hours_to) {
            // isolated left-to-right so "08:00–23:00" keeps its order inside Arabic text
            $parts[] = "\u{2066}".$this->hours_from.'–'.$this->hours_to."\u{2069}";
        }

        return implode(' · ', $parts);
    }

    /** @return array<int,string> 0 = Sunday */
    public static function dayNames(): array
    {
        return [__('Sunday'), __('Monday'), __('Tuesday'), __('Wednesday'), __('Thursday'), __('Friday'), __('Saturday')];
    }

    public function modeLabel(): string
    {
        return self::pick(self::MODES, $this->mode);
    }

    public function statusLabel(): string
    {
        return self::pick(self::STATUSES, $this->status);
    }

    public function paidLabel(): string
    {
        return self::pick(self::PAID, $this->paid_status);
    }

    /** @param  array<string,array{0:string,1:string}>  $labels */
    public static function pick(array $labels, ?string $key): string
    {
        $l = $labels[$key] ?? [$key, $key];

        return app()->getLocale() === 'en' ? $l[1] : $l[0];
    }
}
