<?php

namespace App\Support;

use App\Models\PlatformAdmin;
use App\Models\PlatformSetting;
use App\Models\SalesTarget;
use Illuminate\Support\Carbon;

/**
 * The pay plan of the sales team (Tanta): base pay, commission, the per-visit appointment fee, target bonuses,
 * field allowances, phone lines and the target ramp of the first three months. The defaults below were agreed with
 * the owner; he may change any number on the team page (crm.team), stored as JSON in PlatformSetting `sales_plan`.
 * The team page, the commission estimate and the printed playbook all read from here, so they never disagree.
 */
final class SalesPlan
{
    public const SETTING = 'sales_plan';

    /** role on platform_admins => seat in the plan */
    public const SEATS = ['sales' => 'caller', 'field_sales' => 'field'];

    /** Gharbia centres outside Tanta (the 100 EGP allowance) */
    public const GHARBIA = ['المحلة الكبرى', 'المحلة', 'كفر الزيات', 'زفتى', 'السنطة', 'سمنود', 'بسيون', 'قطور'];

    /** Nearby cities outside the governorate (200 EGP, with prior approval) */
    public const OUTSIDE = ['شبين الكوم', 'كفر الشيخ', 'المنصورة', 'دمنهور'];

    public const DEFAULTS = [
        'base' => ['caller' => 2500, 'field' => 3000],
        'probation_months' => 3,
        'closer_pct' => 15, 'closer_min' => 75, 'closer_max' => 1500,
        'assist_pct' => 5, 'assist_min' => 25, 'assist_max' => 500,
        'retention_pct' => 5,
        'clawback_days' => 30,
        'payday' => 10,
        'per_visited_appointment' => 20,
        'bonus' => ['caller' => 500, 'field' => 750],
        'bonus_double_at' => 120,
        'allowance' => ['tanta' => 40, 'gharbia' => 100, 'outside' => 200],
        'phone' => ['caller' => 250, 'field' => 150],
        'review_below' => 60,
        'ramp' => [
            'caller' => [
                ['calls_per_day' => 50, 'appointments' => 40, 'trials' => 15, 'paid' => 2],
                ['calls_per_day' => 50, 'appointments' => 50, 'trials' => 20, 'paid' => 3],
                ['calls_per_day' => 50, 'appointments' => 60, 'trials' => 25, 'paid' => 4],
            ],
            'field' => [
                ['visits' => 60, 'places' => 40, 'paid' => 5],
                ['visits' => 75, 'places' => 40, 'paid' => 8],
                ['visits' => 85, 'places' => 40, 'paid' => 12],
            ],
        ],
    ];

    /** The numbers the owner may edit on the team page: dotted path => [arabic, english]. */
    public const EDITABLE = [
        'base.caller' => ['المرتب الأساسي: مبيعات التليفون', 'Base pay: telesales'],
        'base.field' => ['المرتب الأساسي: المندوب الميداني', 'Base pay: field rep'],
        'closer_pct' => ['عمولة اللي قفل البيعة (% من أول فاتورة)', 'Closer commission (% of the first invoice)'],
        'closer_min' => ['أقل عمولة للي قفل', 'Closer commission minimum'],
        'closer_max' => ['أعلى عمولة للي قفل', 'Closer commission maximum'],
        'assist_pct' => ['عمولة المساعد (% من أول فاتورة)', 'Assist commission (% of the first invoice)'],
        'assist_min' => ['أقل عمولة للمساعد', 'Assist commission minimum'],
        'assist_max' => ['أعلى عمولة للمساعد', 'Assist commission maximum'],
        'retention_pct' => ['عمولة الاستمرار (% من الفاتورة التانية والتالتة)', 'Retention (% of the 2nd and 3rd invoices)'],
        'per_visited_appointment' => ['لكل ميعاد اتزار فعلاً (مبيعات التليفون)', 'Per booked visit that happened (telesales)'],
        'bonus.caller' => ['مكافأة التارجت: مبيعات التليفون', 'Target bonus: telesales'],
        'bonus.field' => ['مكافأة التارجت: المندوب', 'Target bonus: field rep'],
        'bonus_double_at' => ['المكافأة تتضاعف من نسبة (%)', 'Bonus doubles from (%)'],
        'allowance.tanta' => ['بدل يوم زيارات جوه طنطا', 'Visit day allowance: Tanta'],
        'allowance.gharbia' => ['بدل يوم زيارات في مراكز الغربية', 'Visit day allowance: Gharbia centres'],
        'allowance.outside' => ['بدل يوم زيارات بره الغربية', 'Visit day allowance: outside Gharbia'],
        'phone.caller' => ['خط التليفون: مبيعات التليفون', 'Phone line: telesales'],
        'phone.field' => ['خط التليفون: المندوب', 'Phone line: field rep'],
        'clawback_days' => ['العمولة بترجع لو العميل استرد فلوسه خلال (يوم)', 'Clawback if refunded within (days)'],
        'payday' => ['العمولة بتتصرف يوم كام في الشهر اللي بعده', 'Commission paid on day'],
    ];

    /** @return array<string, mixed> */
    public static function all(): array
    {
        $saved = json_decode((string) PlatformSetting::get(self::SETTING, ''), true);

        return array_replace_recursive(self::DEFAULTS, is_array($saved) ? $saved : []);
    }

    public static function get(string $path): mixed
    {
        return data_get(self::all(), $path);
    }

    public static function number(string $path): float
    {
        return (float) self::get($path);
    }

    /**
     * Saves the edited numbers (only the editable ones, never negative).
     *
     * @param  array<string, mixed>  $values  dotted path => number
     */
    public static function save(array $values): void
    {
        $saved = json_decode((string) PlatformSetting::get(self::SETTING, ''), true);
        $saved = is_array($saved) ? $saved : [];
        foreach (array_keys(self::EDITABLE) as $path) {
            if (isset($values[$path]) && is_numeric($values[$path])) {
                data_set($saved, $path, max(0, round((float) $values[$path], 2)));
            }
        }
        PlatformSetting::put(self::SETTING, json_encode($saved, JSON_THROW_ON_ERROR));
    }

    /** caller | field | null for anyone outside the sales team. */
    public static function seat(PlatformAdmin $admin): ?string
    {
        return self::SEATS[(string) $admin->role] ?? null;
    }

    public static function seatLabel(?string $seat): string
    {
        return match ($seat) {
            'caller' => __('Telesales'),
            'field' => __('Field rep'),
            default => '—',
        };
    }

    /** The closer's commission on the first paid subscription invoice. */
    public static function closerCommission(float $amount): float
    {
        return self::clamp($amount * self::number('closer_pct') / 100, self::number('closer_min'), self::number('closer_max'));
    }

    /** The assist's commission on the first paid subscription invoice. */
    public static function assistCommission(float $amount): float
    {
        return self::clamp($amount * self::number('assist_pct') / 100, self::number('assist_min'), self::number('assist_max'));
    }

    /** The closer's share of the second and third paid invoices. */
    public static function retentionCommission(float $amount): float
    {
        return round($amount * self::number('retention_pct') / 100, 2);
    }

    /** The target bonus for reaching $percent of the target. */
    public static function bonus(string $seat, float $percent): float
    {
        if ($percent < 100) {
            return 0.0;
        }
        $bonus = self::number("bonus.$seat");

        return $percent >= self::number('bonus_double_at') ? $bonus * 2 : $bonus;
    }

    /** tanta | gharbia | outside for a visited place. */
    public static function zone(string $city, string $governorate = ''): string
    {
        $city = trim($city);
        $governorate = trim($governorate);
        foreach (self::OUTSIDE as $name) {
            if ($city !== '' && str_contains($city, $name)) {
                return 'outside';
            }
        }
        if ($governorate !== '' && ! str_contains($governorate, 'الغربية') && ! str_contains(mb_strtolower($governorate), 'gharbia')) {
            return 'outside';
        }
        foreach (self::GHARBIA as $name) {
            if ($city !== '' && str_contains($city, $name)) {
                return 'gharbia';
            }
        }

        return 'tanta';
    }

    public static function zoneLabel(string $zone): string
    {
        return match ($zone) {
            'gharbia' => __('Gharbia centres'),
            'outside' => __('Outside Gharbia'),
            default => __('Inside Tanta'),
        };
    }

    /**
     * Targets of $admin for $month (YYYY-MM): the saved row, or the ramp of his seat for his 1st/2nd/3rd+ month.
     *
     * @return array<string, int>
     */
    public static function targets(PlatformAdmin $admin, string $month): array
    {
        $empty = array_fill_keys(array_keys(SalesTarget::METRICS), 0);
        $row = SalesTarget::query()->where('admin_id', $admin->id)->where('month', $month)->first();
        if ($row) {
            return array_map('intval', $row->only(array_keys($empty)));
        }
        $seat = self::seat($admin);
        if ($seat === null) {
            return $empty;
        }
        $ramp = (array) self::get("ramp.$seat");

        return array_map('intval', array_replace($empty, (array) ($ramp[min(count($ramp), self::monthNumber($admin, $month)) - 1] ?? [])));
    }

    /** 1 for the month the admin joined, 2 for the next... */
    public static function monthNumber(PlatformAdmin $admin, string $month): int
    {
        $joined = local_at($admin->created_at ?? now())->startOfMonth();
        $at = Carbon::createFromFormat('Y-m-d', "$month-01", local_timezone())->startOfMonth();

        return max(1, (int) $joined->diffInMonths($at) + 1);
    }

    private static function clamp(float $value, float $min, float $max): float
    {
        return round(max($min, min($max > 0 ? $max : $value, $value)), 2);
    }
}
