<?php

namespace App\Billing;

use App\Models\AddonPrice;
use App\Models\PlatformSetting;

/**
 * The pricing rules the platform owner sets on admin › Plans & add-ons › Prices, next to the plan and module prices:
 * the custom plan's monthly minimum, the markup on an operations module taken without the Complete plan, and the
 * discount on a per-branch pack for every branch after the first. Kept in platform_settings; until one is saved the
 * launch values apply (CustomPlan::MINIMUM_MONTHLY, CustomPlan::OPERATIONS_MARKUP, AddonPrice::EXTRA_BRANCH_SHARE).
 */
final class PricingRules
{
    public const CUSTOM_MINIMUM = 'pricing.custom_minimum';

    public const OPERATIONS_MARKUP = 'pricing.operations_markup_pct';

    public const EXTRA_BRANCH_OFF = 'pricing.extra_branch_off_pct';

    /** Container key of the values read in this request or queue job (scoped: a long-running worker never keeps old ones). */
    public const CACHE = 'pricing.rules';

    /** The custom plan costs at least this a month (EGP). */
    public static function customMinimum(): float
    {
        return self::read(self::CUSTOM_MINIMUM, CustomPlan::MINIMUM_MONTHLY);
    }

    /** The markup on an operations module on the custom plan, as a share (0.25 = 25%). */
    public static function operationsMarkup(): float
    {
        return self::read(self::OPERATIONS_MARKUP, CustomPlan::OPERATIONS_MARKUP * 100) / 100;
    }

    /** What each branch after the first pays of a per-branch pack, as a share (0.6 = 40% off). */
    public static function extraBranchShare(): float
    {
        return 1 - self::read(self::EXTRA_BRANCH_OFF, (1 - AddonPrice::EXTRA_BRANCH_SHARE) * 100) / 100;
    }

    /** @param array{custom_minimum: float|int|string, operations_markup_pct: float|int|string, extra_branch_off_pct: float|int|string} $rules */
    public static function save(array $rules): void
    {
        PlatformSetting::put(self::CUSTOM_MINIMUM, (string) (float) $rules['custom_minimum']);
        PlatformSetting::put(self::OPERATIONS_MARKUP, (string) (float) $rules['operations_markup_pct']);
        PlatformSetting::put(self::EXTRA_BRANCH_OFF, (string) (float) $rules['extra_branch_off_pct']);
        self::flush();
    }

    public static function flush(): void
    {
        app()->forgetInstance(self::CACHE);
    }

    /** Read once per request or job: a price list asks for every pack. */
    private static function read(string $key, float $default): float
    {
        $cache = app(self::CACHE);
        if (! $cache->offsetExists($key)) {
            $value = PlatformSetting::get($key);
            $cache[$key] = is_numeric($value) ? (float) $value : $default;
        }

        return $cache[$key];
    }
}
