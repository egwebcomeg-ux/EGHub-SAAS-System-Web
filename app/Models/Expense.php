<?php

namespace App\Models;

use App\Models\Concerns\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Money a restaurant spent outside the shift drawer; the drawer's own expenses stay on App\Models\CashMovement. */
class Expense extends TenantModel
{
    /** category => [arabic, english] */
    public const CATEGORIES = [
        'rent' => ['إيجار', 'Rent'],
        'salaries' => ['مرتبات', 'Salaries'],
        'bills' => ['كهربا ومياه وغاز ونت', 'Utilities'],
        'supplies' => ['مشتريات وخامات', 'Supplies'],
        'marketing' => ['إعلانات وتسويق', 'Marketing'],
        'maintenance' => ['صيانة', 'Maintenance'],
        'other' => ['حاجات تانية', 'Other'],
    ];

    protected function casts(): array
    {
        return ['amount' => 'float', 'spent_on' => 'date'];
    }

    public function categoryLabel(): string
    {
        $label = self::CATEGORIES[$this->category] ?? null;

        return $label ? tr($label[0], $label[1]) : (string) $this->category;
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
