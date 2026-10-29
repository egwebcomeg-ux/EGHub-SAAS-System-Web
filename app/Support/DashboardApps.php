<?php

namespace App\Support;

use App\Http\Controllers\Dashboard\CouponsController;
use App\Http\Controllers\Dashboard\DevicesController;
use App\Models\User;
use App\Services\Entitlements;
use App\Services\ModuleChoice;

/**
 * The one list of the dashboard's areas ("apps"). The sidebar (layouts/dashboard.blade.php) and the apps grid on the
 * home page (dashboard/home.blade.php) both read it, so a route, label, icon or visibility rule lives here once.
 *
 * An app is shown when its permission and its plan feature both allow it (App\Services\Entitlements). Once the owner
 * picked their modules (App\Services\ModuleChoice) the apps of the picked modules stay in their groups and the rest
 * wait under "more"; every link keeps working either way.
 *
 * @phpstan-type App array{route: string, label: string, icon: string, group: string, permission: string|bool|null, feature: ?string, fallback?: string}
 * @phpstan-type Group array{key: string, title: ?string, apps: list<App>}
 */
final class DashboardApps
{
    /** The ungrouped entry at the top of the sidebar: the apps screen itself. */
    public const GROUP_HOME = 'home';

    /** The pin that keeps the shifts entry only for someone who manages shifts without the POS (shifts are a POS tab). */
    private const POS_FALLBACK = 'pos-fallback';

    /** The grid icon of the apps screen. */
    public const APPS_ICON = 'M4 4h6v6H4zM14 4h6v6h-6zM4 14h6v6H4zM14 14h6v6h-6z';

    /** The group a page outside every app (security, modules, help…) belongs to in the rail and the app header. */
    public const GROUP_FALLBACK = 'management';

    /** @var array<string, string> group key => its colour (tokens --color-app-* in resources/css/app.css) */
    public const HUES = [
        'orders' => 'bg-app-orders', 'menu' => 'bg-app-menu', 'customers' => 'bg-app-customers',
        'operations' => 'bg-app-operations', 'management' => 'bg-app-management',
    ];

    /** @var array<string, string> group key => the icon of the group as an app (the app header strip) */
    public const GROUP_ICONS = [
        'orders' => 'M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4zM3 6h18M16 10a4 4 0 0 1-8 0',
        'menu' => 'M4 4h16v16H4zM4 9h16M9 9v11',
        'customers' => 'M17 20v-2a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v2M10 10a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM21 20v-2a4 4 0 0 0-3-3.9M16 2.1a4 4 0 0 1 0 7.8',
        'operations' => 'M21 8l-9-5-9 5v8l9 5 9-5zM3 8l9 5 9-5M12 13v8',
        'management' => 'M4 21v-7M4 10V3M12 21v-9M12 8V3M20 21v-5M20 12V3M1 14h6M9 8h6M17 16h6',
    ];

    /**
     * Pages that live inside another app: shifts, terminals and the Z report in the POS, stations under the kitchen
     * display, items in the menu, vouchers with the coupons, the TV and kiosks with the screens.
     *
     * @var array<string, list<string>>
     */
    public const WITHIN = [
        'dash.menu' => ['dash.items.*'],
        'dash.pos' => ['dash.shifts', 'dash.shifts.*', 'dash.terminals', 'dash.terminals.*', 'dash.zreport', 'dash.zreport.*'],
        'dash.kitchen' => ['dash.stations', 'dash.stations.*'],
        'dash.coupons' => ['dash.vouchers', 'dash.vouchers.*'],
        'dash.devices' => ['dash.tv', 'dash.tv.*', 'dash.kiosks', 'dash.kiosks.*'],
    ];

    /** Whether the current page is this app's page, one of its sub-pages, or a page that lives inside it (WITHIN). */
    public static function isActive(string $route): bool
    {
        return request()->routeIs($route)
            || ($route !== 'dash.home' && request()->routeIs($route.'.*'))
            || request()->routeIs(...(self::WITHIN[$route] ?? ['-']));
    }

    /**
     * The app (group) the current page belongs to, for the slim rail and the app header strip: its key, title and the
     * apps of the group this user may open. A page of an app under "more" brings its own entry along; a page outside
     * every app (security, modules, help…) shows the Management group.
     *
     * @param  array{groups: list<Group>, more: list<App>}  $apps  what forUser() returned
     * @return array{key: string, title: ?string, apps: list<App>, current: ?App}
     */
    public static function currentGroup(array $apps): array
    {
        $everything = array_merge(array_merge(...array_map(fn (array $g) => $g['apps'], $apps['groups'])), $apps['more']);
        $current = collect($everything)->first(fn (array $app) => $app['route'] !== 'dash.home' && self::isActive($app['route']));
        $key = $current['group'] ?? self::GROUP_FALLBACK;
        $inGroup = collect($apps['groups'])->firstWhere('key', $key)['apps'] ?? [];
        if ($current && ! in_array($current['route'], array_column($inGroup, 'route'), true)) {
            $inGroup[] = $current;
        }

        return ['key' => $key, 'title' => self::groups()[$key] ?? null, 'apps' => $inGroup, 'current' => $current];
    }

    /** @return array<string, ?string> group key => title, in sidebar order (the home group has no title) */
    public static function groups(): array
    {
        return [
            self::GROUP_HOME => null,
            'orders' => __('Orders & service'),
            'menu' => __('Menu'),
            'customers' => __('Customers & marketing'),
            'operations' => __('Operations'),
            'management' => __('Management'),
        ];
    }

    /**
     * Every area in sidebar order, before any rule is applied. permission: null (everyone), a bool worked out by the
     * page itself, or 'a|b' (any of them); feature: a plan feature, 'a|b' (either), or null.
     *
     * @return list<App>
     */
    public static function all(User $user): array
    {
        $app = fn (string $group, string $route, string $label, string $icon, string|bool|null $permission, ?string $feature) => [
            'route' => $route, 'label' => $label, 'icon' => $icon, 'group' => $group, 'permission' => $permission, 'feature' => $feature,
        ];

        return [
            $app(self::GROUP_HOME, 'dash.home', __('Apps'), self::APPS_ICON, null, null),

            $app('orders', 'dash.orders', __('Orders'), 'M9 5h10M9 12h10M9 19h10M4 5h.01M4 12h.01M4 19h.01', 'orders.view', 'order_board'),
            $app('orders', 'dash.pos', __('POS'), 'M3 4h18v12H3zM8 20h8M12 16v4', 'pos.use', 'pos'),
            $app('orders', 'dash.callcenter', __('Call center'), 'M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1.9.4 1.8.7 2.7a2 2 0 0 1-.5 2.1L8 9.8a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.4c.9.3 1.8.6 2.7.7a2 2 0 0 1 1.7 2z', 'callcenter.use', 'call_center'),
            $app('orders', 'dash.zones', __('Delivery zones'), 'M1 3h15v13H1zM16 8h4l3 3v5h-7zM5.5 21a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5zM18.5 21a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5z', 'delivery.manage', 'delivery_zones|online_ordering'),
            $app('orders', 'dash.drivers', __('Drivers'), 'M12 21s-7-6.2-7-11a7 7 0 0 1 14 0c0 4.8-7 11-7 11zM9 10l2 2 4-4', 'delivery.assign|delivery.drivers', 'delivery_zones|online_ordering'),
            $app('orders', 'dash.driver', __('My deliveries'), 'M5.5 21a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5zM18.5 21a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5zM8 18.5h8M3 13h9l3-5h3l3 5v5h-2', $user->hasRole('driver'), 'delivery_zones|online_ordering'),
            $app('orders', 'dash.kitchen', BusinessType::prepScreen(), 'M6 13.9A4 4 0 0 1 7 6a5 5 0 0 1 10 0 4 4 0 0 1 1 7.9V20H6zM6 17h12', 'kitchen.view', 'kitchen_display'),
            $app('orders', 'dash.pager', __('Guest pager'), 'M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9M10.3 21a1.94 1.94 0 0 0 3.4 0M2 8a10 10 0 0 1 2-5M22 8a10 10 0 0 0-2-5', 'pager.use', 'pager'),
            $app('orders', 'dash.shifts', __('Shifts'), 'M12 8v4l3 2M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0z', 'shifts.manage', 'shifts') + ['fallback' => self::POS_FALLBACK],
            $app('orders', 'dash.reservations', __('Reservations'), 'M8 2v4M16 2v4M3 9h18M5 4h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2zM9 15l2 2 4-4', 'reservations.manage', 'reservations'),

            $app('menu', 'dash.menu', __('Menu'), 'M4 4h16v16H4zM4 9h16M9 9v11', 'menu.manage', null),
            $app('menu', 'dash.tables', __('Tables & QR'), 'M4 4h6v6H4zM14 4h6v6h-6zM4 14h6v6H4zM14 14h2v2h-2zM18 18h2v2h-2z', 'tables.manage', null),

            $app('customers', 'dash.customers', __('Customers'), 'M17 20v-2a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v2M10 10a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM21 20v-2a4 4 0 0 0-3-3.9M16 2.1a4 4 0 0 1 0 7.8', 'customers.view', 'customers'),
            $app('customers', 'dash.loyalty.desk', __('Loyalty card'), 'M3 6h18v12H3zM7 10h.01M11 10h.01M15 10h.01M7 14h.01M11 14h.01', 'loyalty.stamp', 'loyalty_stamps|prepaid_packages|membership|cashback'),
            $app('customers', 'dash.marketing', __('Offers & popups'), 'M7 7h.01M3 11V3h8l10 10-8 8L3 11z', 'marketing.manage', 'offers'),
            $app('customers', 'dash.coupons', __('Coupons & vouchers'), 'M3 7a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v3a2 2 0 0 0 0 4v3a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-3a2 2 0 0 0 0-4zM9 9h.01M15 15h.01M15 9l-6 6', CouponsController::visibleFor($user), null),
            $app('customers', 'dash.feedback', __('Ratings'), 'M12 2.5l2.9 6 6.6.9-4.8 4.6 1.2 6.5L12 17.4l-5.9 3.1 1.2-6.5L2.5 9.4l6.6-.9z', 'customers.view', 'feedback'),
            $app('customers', 'dash.campaigns', __('Campaigns'), 'M3 11v2a1 1 0 0 0 1 1h2l5 4V6L6 10H4a1 1 0 0 0-1 1zM15.5 8.5a5 5 0 0 1 0 7M18.5 5.5a9 9 0 0 1 0 13', 'marketing.manage', 'campaigns'),
            $app('customers', 'dash.wallet', __('Wallet'), 'M20 12V8H6a2 2 0 0 1 0-4h12v4M4 6v12a2 2 0 0 0 2 2h14v-4M18 12a2 2 0 0 0 0 4h4v-4z', 'wallet.manage', 'wallet'),
            $app('customers', 'dash.corporate', __('Corporate contracts'), 'M4 21V5a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v16M16 9h2a2 2 0 0 1 2 2v10M2 21h20M8 7h4M8 11h4M8 15h4', 'contracts.manage', 'corporate_vouchers'),
            $app('customers', 'dash.corporate.bookings', __('Company visits'), 'M8 2v4M16 2v4M3 9h18M5 4h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2zM8 14h3M8 17h6', 'contracts.manage|reservations.manage', 'corporate_vouchers'),
            $app('customers', 'dash.redeem', __('Redeem vouchers'), 'M3 7a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v3a2 2 0 0 0 0 4v3a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-3a2 2 0 0 0 0-4zM8 12l2.5 2.5L16 9', 'vouchers.redeem', 'corporate_vouchers'),
            $app('customers', 'dash.kb', __('Knowledge base'), 'M4 19.5A2.5 2.5 0 0 1 6.5 17H20V3H6.5A2.5 2.5 0 0 0 4 5.5zM4 19.5A2.5 2.5 0 0 0 6.5 22H20v-5M9.5 8.5a2.5 2.5 0 1 1 3 2.4c-.6.2-1 .7-1 1.3M11.5 14.5h.01', 'kb.manage', 'knowledge_base'),

            $app('operations', 'dash.inventory', __('Inventory'), 'M21 8l-9-5-9 5v8l9 5 9-5zM3 8l9 5 9-5M12 13v8', 'inventory.manage', 'inventory'),
            $app('operations', 'dash.devices', __('Screens & devices'), 'M2 5h14v10H2zM6 19h6M9 15v4M18 8h4v12h-4zM20 17h.01', DevicesController::visibleFor($user), null),

            $app('management', 'dash.reports', __('Reports'), 'M4 20V10M10 20V4M16 20v-7M22 20H2', 'reports.view', 'daily_reports'),
            $app('management', 'dash.branches', __('Branches'), 'M12 21s-7-6.2-7-11a7 7 0 0 1 14 0c0 4.8-7 11-7 11zM12 12a2 2 0 1 0 0-4 2 2 0 0 0 0 4z', 'branches.manage', null),
            $app('management', 'dash.users', __('Users & roles'), 'M16 11a4 4 0 1 0-8 0M4 21a8 8 0 0 1 16 0', 'users.manage', null),
            $app('management', 'dash.audit', __('Audit log'), 'M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2M9 5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2M9 5h6M9 12h6M9 16h4', 'audit.view', 'audit_log'),
            $app('management', 'dash.domain', __('Your own domain'), 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18zM3.6 9h16.8M3.6 15h16.8M12 3a15 15 0 0 1 0 18M12 3a15 15 0 0 0 0 18', 'settings.manage', null),
            $app('management', 'dash.billing', __('Subscription'), 'M3 6h18v12H3zM3 10h18M7 15h3', 'billing.manage', null),
            $app('management', 'dash.backups', __('Backups'), 'M12 3C7 3 3 4.3 3 6v12c0 1.7 4 3 9 3s9-1.3 9-3V6c0-1.7-4-3-9-3zM3 6c0 1.7 4 3 9 3s9-1.3 9-3M3 12c0 1.7 4 3 9 3s9-1.3 9-3', 'backups.manage', null),
            $app('management', 'dash.settings', __('Settings'), 'M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-2.8 1.2V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-2.8-1.2l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1A1.7 1.7 0 0 0 3 15H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.2-2.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1A1.7 1.7 0 0 0 9 4.6V4.5a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 2.8 1.2l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1A1.7 1.7 0 0 0 21 11h.1a2 2 0 1 1 0 4z', 'settings.manage', null),
        ];
    }

    /**
     * What this user may open: the groups (a group with nothing allowed is left out) with the picked modules' apps,
     * and under `more` the allowed apps outside the owner's pick (empty while nothing was picked).
     *
     * @return array{groups: list<Group>, more: list<App>}
     */
    public static function forUser(User $user): array
    {
        $rights = Entitlements::current();
        $allowed = fn (array $app) => (is_bool($app['permission']) ? $app['permission'] : (! $app['permission'] || $user->canAny(explode('|', $app['permission']))))
            && (! $app['feature'] || $rights->allows($app['feature']));
        $all = self::all($user);
        $posOpen = $allowed(collect($all)->firstWhere('route', 'dash.pos'));
        $apps = array_filter($all, fn (array $app) => $allowed($app) && (($app['fallback'] ?? null) !== self::POS_FALLBACK || ! $posOpen));

        $hasChoice = ModuleChoice::hasChoice();
        $groups = [];
        $more = [];
        foreach (self::groups() as $key => $title) {
            $inGroup = array_values(array_filter($apps, fn (array $app) => $app['group'] === $key));
            if ($hasChoice) {
                array_push($more, ...array_filter($inGroup, fn (array $app) => ! ModuleChoice::focuses($app['route'])));
                $inGroup = array_values(array_filter($inGroup, fn (array $app) => ModuleChoice::focuses($app['route'])));
            }
            if ($inGroup !== []) {
                $groups[] = ['key' => $key, 'title' => $title, 'apps' => $inGroup];
            }
        }

        return ['groups' => $groups, 'more' => array_values($more)];
    }

    /**
     * The home page grid: forUser() without the home entry, every tile with its url, plus the owner's picked modules
     * (App\Services\ModuleChoice::shortcuts): a picked module marks the tile of its page (`module`), one whose page is
     * not an app of its own gets a tile in its group, and one the plan does not include shows locked, pointing to
     * billing (only for whoever manages the subscription). `picked` is false while nothing was picked yet.
     *
     * @return array{groups: list<array{key: string, title: ?string, apps: list<array>}>, more: list<array>, picked: bool}
     */
    public static function home(User $user): array
    {
        $apps = self::forUser($user);
        $groups = array_values(array_filter($apps['groups'], fn (array $g) => $g['key'] !== self::GROUP_HOME));
        $tile = fn (array $app) => $app + ['url' => route($app['route']), 'module' => null, 'locked' => false];
        $groups = array_map(fn (array $g) => ['key' => $g['key'], 'title' => $g['title'], 'apps' => array_map($tile, $g['apps'])], $groups);
        $more = array_map($tile, $apps['more']);
        $picked = ModuleChoice::hasChoice();

        foreach ($picked ? ModuleChoice::shortcuts($user) : [] as $shortcut) {
            $module = $shortcut['module'];
            $extra = null;
            // a locked module whose page already has a tile (Branches: the page is open, per-branch prices are not)
            // keeps that one tile instead of a second, locked one
            $page = ModuleChoice::PAGES[$module['slug']][0] ?? null;
            if ($shortcut['locked'] && $page && self::mark($groups, $more, route($page), $module['slug'])) {
                continue;
            }
            if ($shortcut['locked']) {
                $extra = ['route' => null, 'label' => $module['name'], 'icon' => null, 'landingIcon' => $module['icon'], 'group' => $module['group'],
                    'url' => $shortcut['url'], 'module' => $module['slug'], 'locked' => true];
            } elseif (! self::mark($groups, $more, $shortcut['url'], $module['slug'])) {
                $extra = ['route' => null, 'label' => $module['name'], 'icon' => null, 'landingIcon' => $module['icon'], 'group' => $module['group'],
                    'url' => $shortcut['url'], 'module' => $module['slug'], 'locked' => false];
            }
            if ($extra !== null) {
                $groups = self::append($groups, $extra);
            }
        }

        return ['groups' => $groups, 'more' => $more, 'picked' => $picked];
    }

    /** Marks the first tile that opens $url with the picked module's slug; false when no tile does. */
    private static function mark(array &$groups, array &$more, string $url, string $slug): bool
    {
        foreach ($groups as $gi => $g) {
            foreach ($g['apps'] as $ai => $app) {
                if ($app['url'] === $url) {
                    $groups[$gi]['apps'][$ai]['module'] ??= $slug;

                    return true;
                }
            }
        }
        foreach ($more as $ai => $app) {
            if ($app['url'] === $url) {
                $more[$ai]['module'] ??= $slug;

                return true;
            }
        }

        return false;
    }

    /** Adds a tile to its group, creating the group in sidebar order when none of its apps is open to this user. */
    private static function append(array $groups, array $tile): array
    {
        foreach ($groups as $gi => $g) {
            if ($g['key'] === $tile['group']) {
                $groups[$gi]['apps'][] = $tile;

                return $groups;
            }
        }
        $order = array_keys(self::groups());
        $groups[] = ['key' => $tile['group'], 'title' => self::groups()[$tile['group']] ?? null, 'apps' => [$tile]];
        usort($groups, fn (array $a, array $b) => array_search($a['key'], $order, true) <=> array_search($b['key'], $order, true));

        return $groups;
    }
}
