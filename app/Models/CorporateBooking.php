<?php

namespace App\Models;

use App\Models\Concerns\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A company visit on a corporate contract: asked through the company's private link or entered by staff, then
 * confirmed or declined by a manager. Confirmed, it holds a meals voucher for the visit day (on the rep's mobile)
 * and a voucher with the rest of the budget for the company; closed after the visit, the meals left go to the rest.
 */
class CorporateBooking extends TenantModel
{
    /** status => [arabic, english] */
    public const STATUSES = [
        'requested' => ['مستني موافقة المدير', 'Waiting for a manager'],
        'confirmed' => ['متأكد', 'Confirmed'],
        'declined' => ['مرفوض', 'Declined'],
        'cancelled' => ['ملغي', 'Cancelled'],
        'closed' => ['الزيارة اتقفلت', 'Visit closed'],
    ];

    protected function casts(): array
    {
        return [
            'visit_at' => 'datetime', 'budget' => 'float', 'meals_amount' => 'float', 'voucher_amount' => 'float',
            'decided_at' => 'datetime', 'closed_at' => 'datetime', 'party_size' => 'integer',
        ];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(CorporateContract::class, 'corporate_contract_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function vouchers(): HasMany
    {
        return $this->hasMany(CorporateVoucher::class);
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /** The budget per guest, as the company sent it. */
    public function perPerson(): float
    {
        return $this->party_size > 0 ? round($this->budget / $this->party_size, 2) : 0.0;
    }

    public function statusLabel(): string
    {
        return CorporateContract::pick(self::STATUSES, $this->status);
    }
}
