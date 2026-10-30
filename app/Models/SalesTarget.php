<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One person's targets for one month (YYYY-MM); missing months use the ramp of App\Support\SalesPlan. */
class SalesTarget extends Model
{
    protected $table = 'crm_targets';

    protected $guarded = ['id'];

    /** metric => [arabic, english] */
    public const METRICS = [
        'calls_per_day' => ['مكالمات في اليوم', 'Calls a day'],
        'appointments' => ['مواعيد زيارة', 'Visits booked'],
        'visits' => ['زيارات', 'Visits'],
        'places' => ['أماكن جديدة', 'New places'],
        'trials' => ['تسجيلات تجربة', 'Trial sign-ups'],
        'paid' => ['اشتراكات مدفوعة', 'Paid subscriptions'],
    ];

    protected function casts(): array
    {
        return array_fill_keys(array_keys(self::METRICS), 'integer');
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class, 'admin_id');
    }

    public static function label(string $metric): string
    {
        return Lead::pick(self::METRICS[$metric] ?? [$metric, $metric]);
    }
}
