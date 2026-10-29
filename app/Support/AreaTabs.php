<?php

namespace App\Support;

use App\Models\User;
use App\Services\Entitlements;

/**
 * Areas whose pages share one tab bar (dashboard/partials/area-tabs.blade.php). Every tab keeps its own route and
 * middleware, and shows only to staff with its permission when the plan has its feature:
 * - corporate: the contracts, the company visits, redeeming company vouchers and their report. One "Corporate" app in
 *   the sidebar (App\Support\DashboardApps) opens the first tab the user may use.
 * - reports: the sales, the end-of-day Z report, the company vouchers report and the guests' ratings. The Z report and
 *   the vouchers report still live in their own apps (POS, Corporate); opened from these tabs (?in=reports) they
 *   show the reports tabs instead and count as the Reports app.
 *
 * @phpstan-type Tab array{key: string, label: string, name: string, route: string, url: string}
 */
final class AreaTabs
{
    /** The `in` query value of a page of another app opened from the reports tabs. */
    public const IN_REPORTS = 'reports';

    /** The pages of other apps that are tabs of the reports too. */
    private const REPORTS_GUESTS = ['dash.zreport', 'dash.corporate.report'];

    /**
     * @return list<Tab>
     */
    public static function for(string $area, User $user): array
    {
        return match ($area) {
            'corporate' => self::corporate($user),
            'reports' => self::reports($user),
        };
    }

    /** @return list<Tab> */
    public static function corporate(User $user): array
    {
        return self::allowed($user, [
            ['contracts', __('Contracts'), __('Corporate contracts'), 'dash.corporate', 'contracts.manage', 'corporate_vouchers'],
            ['visits', __('Visits'), __('Company visits'), 'dash.corporate.bookings', 'contracts.manage|reservations.manage', 'corporate_vouchers'],
            ['redeem', __('Redeem vouchers'), __('Redeem company vouchers'), 'dash.redeem', 'vouchers.redeem', 'corporate_vouchers'],
            ['report', __('Report'), __('Company vouchers report'), 'dash.corporate.report', 'contracts.manage|reports.view', 'corporate_vouchers'],
        ]);
    }

    /** @return list<Tab> */
    public static function reports(User $user): array
    {
        return self::allowed($user, [
            ['sales', __('Sales'), __('Reports'), 'dash.reports', 'reports.view', 'daily_reports'],
            ['zreport', __('End of day (Z)'), __('End of day (Z)'), 'dash.zreport', 'reports.view|shifts.manage', 'pos', ['in' => self::IN_REPORTS]],
            ['corporate', __('Company vouchers'), __('Company vouchers report'), 'dash.corporate.report', 'contracts.manage|reports.view', 'corporate_vouchers', ['in' => self::IN_REPORTS]],
            ['feedback', __('Ratings'), __('Ratings'), 'dash.feedback', 'reports.view|customers.view', 'feedback'],
        ]);
    }

    /** Whether this page of another app (the Z report, the vouchers report) was opened from the reports tabs. */
    public static function fromReports(): bool
    {
        return request()->query('in') === self::IN_REPORTS && request()->routeIs(...self::REPORTS_GUESTS);
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string, 6?: array<string, string>}>  $tabs  key, tab label, full name, route, permission ('a|b' = any), plan feature, query
     * @return list<Tab>
     */
    private static function allowed(User $user, array $tabs): array
    {
        $rights = Entitlements::current();
        $open = array_filter($tabs, fn (array $tab) => $user->canAny(explode('|', $tab[4])) && $rights->allows($tab[5]));

        return array_values(array_map(fn (array $tab) => [
            'key' => $tab[0], 'label' => $tab[1], 'name' => $tab[2], 'route' => $tab[3], 'url' => route($tab[3], $tab[6] ?? []),
        ], $open));
    }
}
