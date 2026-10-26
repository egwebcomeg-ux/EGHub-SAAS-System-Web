<?php

namespace App\Services\Crm;

use App\Billing\OnlinePayments;
use App\Models\CallScriptItem;
use App\Models\Plan;
use App\Models\PlatformAdmin;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantProvisioner;
use App\Support\CallScriptDefaults;
use App\Support\SiteServices;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The sales call script as the caller reads it: the active items of each kind, with their placeholders filled from
 * the lead and the live prices (plans, the menu-entry service, the payment methods switched on), so the script never
 * states a number the platform does not charge. The defaults (App\Support\CallScriptDefaults) are installed by
 * Database\Seeders\CallScriptSeeder or the editor's "add the default script" button: only missing keys are added,
 * so a rerun never overwrites what the owner edited, and a default the owner hid stays hidden.
 */
final class CallScript
{
    /**
     * placeholder => [arabic, english] what it becomes on a call (shown in the editor).
     */
    public const PLACEHOLDERS = [
        '{owner}' => ['الاسم الأول لصاحب المحل', 'The owner\'s first name'],
        '{restaurant}' => ['اسم المحل', 'The place\'s name'],
        '{caller}' => ['اسمك الأول', 'Your first name'],
        '{menu_link}' => ['لينك المنيو بتاعه', 'Their menu link'],
        '{plan}' => ['باقته دلوقتي', 'Their current plan'],
        '{trial_days}' => ['الأيام الباقية من التجربة', 'Trial days left'],
        '{trial_length}' => ['مدة التجربة عند التسجيل', 'Trial length at sign-up'],
        '{free_items}' => ['عدد أصناف الباقة المجانية', 'Items in the Free plan'],
        '{full_price}' => ['سعر الباقة الكاملة في الشهر', 'Complete plan price per month'],
        '{full_year_price}' => ['سعر الباقة الكاملة للسنة', 'Complete plan price per year'],
        '{year_discount}' => ['خصم الدفع السنوي %', 'Yearly payment discount %'],
        '{menu_setup_price}' => ['سعر خدمة إدخال المنيو', 'Menu entry service price'],
        '{how_to_pay}' => ['طرق الدفع المتاحة دلوقتي', 'Payment methods switched on now'],
    ];

    /**
     * Active items grouped by kind, in the order of CallScriptItem::KINDS.
     *
     * @return array<string, Collection<int, CallScriptItem>>
     */
    public function sections(): array
    {
        $items = CallScriptItem::query()->active()->ordered()->get()->groupBy('kind');
        $out = [];
        foreach (array_keys(CallScriptItem::KINDS) as $kind) {
            $out[$kind] = $items->get($kind, collect())->values();
        }

        return $out;
    }

    /**
     * What each placeholder becomes for this lead and caller. Prices are read from the plans and services as they are
     * now; a missing plan leaves "—" rather than a guessed number.
     *
     * @return array<string, string>
     */
    public function context(Tenant $tenant, ?User $owner, PlatformAdmin $caller): array
    {
        $plans = Plan::query()->whereIn('code', ['free', 'full'])->with('features')->get()->keyBy('code');
        $full = $plans->get('full');
        $free = $plans->get('free');
        $sub = $tenant->subscription;
        $freeItems = $free?->entitlements()['limits']['menu_items'] ?? null;
        $menuSetup = SiteServices::find('menu_setup');

        return [
            '{owner}' => $owner ? Str::before(trim($owner->name), ' ') : '',
            '{restaurant}' => $tenant->name,
            '{caller}' => Str::before(trim($caller->name), ' '),
            '{menu_link}' => $tenant->url(),
            '{plan}' => $sub?->plan?->displayName() ?? '—',
            '{trial_days}' => $sub && $sub->status === 'trialing' ? (string) $sub->daysLeft() : '0',
            '{trial_length}' => (string) TenantProvisioner::TRIAL_DAYS,
            '{free_items}' => $freeItems === null ? '—' : (string) $freeItems,
            '{full_price}' => $full ? money($full->monthly_price) : '—',
            '{full_year_price}' => $full ? money($full->priceFor(12)) : '—',
            '{year_discount}' => $full ? money($full->cycle_discounts['12'] ?? 0) : '—',
            '{menu_setup_price}' => isset($menuSetup['price']) ? money($menuSetup['price']) : '—',
            '{how_to_pay}' => app(OnlinePayments::class)->howToPay(),
        ];
    }

    /** @param array<string, string> $context */
    public static function fill(string $text, array $context): string
    {
        return strtr($text, $context);
    }

    /**
     * Adds the default items whose key is missing. @return int how many were added
     */
    public static function installDefaults(): int
    {
        $existing = CallScriptItem::query()->pluck('key')->all();
        $added = 0;
        foreach (CallScriptDefaults::items() as $i => $item) {
            if (in_array($item['key'], $existing, true)) {
                continue;
            }
            CallScriptItem::query()->create($item + ['sort_order' => ($i + 1) * 10, 'is_active' => true]);
            $added++;
        }

        return $added;
    }

    /** Whether $key is one of the default items (those are hidden, never deleted, so a rerun of the seeder keeps them away). */
    public static function isDefault(string $key): bool
    {
        return in_array($key, array_column(CallScriptDefaults::items(), 'key'), true);
    }
}
