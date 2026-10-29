<?php

namespace App\Support;

use App\Models\User;

/**
 * Dashboard permissions and the default permission set of each staff role. With the `staff_roles` plan feature the
 * owner may add permissions to one staff member on top of their role (spatie direct permissions, see
 * App\Http\Controllers\Dashboard\UsersController); the owner-only ones are never handed out that way.
 */
final class Permissions
{
    /** key => [arabic, english] */
    public const ALL = [
        'orders.view' => ['عرض الطلبات', 'View orders'],
        'orders.manage' => ['تغيير حالة الطلبات', 'Update orders'],
        'pos.use' => ['تسجيل طلبات (كاشير / ويتر)', 'Take orders (POS / waiter)'],
        'pos.discount' => ['عمل خصم على الطلب', 'Give order discounts'],
        'pos.hold.discard' => ['إلغاء الطلبات المعلّقة', 'Discard held tickets'],
        'pos.refund' => ['مرتجع ورد فلوس (موافقة المدير)', 'Refunds and returns (manager approval)'],
        'callcenter.use' => ['شاشة الكول سنتر', 'Call-center screen'],
        'shifts.manage' => ['فتح وقفل الورديات والدرج', 'Open/close shifts & cash drawer'],
        'delivery.manage' => ['مناطق التوصيل', 'Delivery zones'],
        'delivery.assign' => ['تحديد الطيار لطلبات التوصيل', 'Assign drivers to delivery orders'],
        'delivery.drivers' => ['متابعة الطيارين وتسوية فلوسهم', 'Track drivers and settle their cash'],
        'delivery.drive' => ['شاشة الطيار (طلباتي للتوصيل)', 'Driver screen (my deliveries)'],
        'kitchen.view' => ['شاشة المطبخ', 'Kitchen display'],
        'pager.use' => ['النداء على الزبون (زرار «نادي» وشاشة النداء)', 'Guest pager (the “Call” button and the pager station)'],
        'menu.manage' => ['إدارة المنيو والأقسام', 'Manage menu'],
        'menu.availability' => ['تحديد الأصناف اللي خلصت', 'Mark items sold out'],
        'inventory.manage' => ['المخزون والوصفات', 'Inventory & recipes'],
        'tables.manage' => ['الترابيزات وأكواد QR', 'Tables & QR codes'],
        'branches.manage' => ['الفروع', 'Branches'],
        'customers.view' => ['العملاء', 'Customers'],
        'customers.import' => ['استيراد عملاء من ملف', 'Import customers from a file'],
        'loyalty.stamp' => ['إضافة أختام وصرف مكافآت', 'Give stamps & redeem rewards'],
        'loyalty.manage' => ['إعداد برنامج الولاء', 'Loyalty program setup'],
        'vouchers.give' => ['ادّي فاوتشرات خصم للعملاء', 'Give discount vouchers to guests'],
        'marketing.manage' => ['العروض والتسويق', 'Offers & marketing'],
        'reports.view' => ['التقارير', 'Reports'],
        'settings.manage' => ['الإعدادات والهوية', 'Settings & branding'],
        'users.manage' => ['المستخدمين والصلاحيات', 'Users & roles'],
        'billing.manage' => ['الاشتراك والفواتير', 'Subscription & billing'],
        'audit.view' => ['سجل العمليات', 'Audit log'],
        'wallet.manage' => ['المحفظة وكروت الهدايا', 'Wallet & gift cards'],
        'kiosk.manage' => ['الكشك (طلب ذاتي)', 'Self-order kiosks'],
        'reservations.manage' => ['الحجوزات', 'Reservations'],
        'contracts.manage' => ['تعاقدات الشركات (إنشاء وطباعة وتقارير)', 'Corporate contracts (create, print, reports)'],
        'vouchers.redeem' => ['صرف فاوتشرات الشركات (الكاشير وشاشة الصرف)', 'Redeem corporate vouchers (POS and the redeem screen)'],
        'vouchers.exception' => ['الموافقة على استثناء فاوتشر منتهي', 'Approve an expired voucher exception'],
        'backups.manage' => ['النسخ الاحتياطية (إنشاء وتحميل)', 'Backups (create & download)'],
        'kb.manage' => ['كتابة الأسئلة والأجوبة وتعديلها', 'Write and edit the knowledge base'],
        'kb.view' => ['قراءة الأسئلة والأجوبة (الكول سنتر والكاشير ولوحة الطلبات)', 'Read the knowledge base (call center, POS, order board)'],
    ];

    /**
     * Permissions only the owner role holds: the team, the subscription and the backups (a whole copy of the
     * restaurant's data). Never granted to a staff member on top of their role, whatever the plan allows.
     */
    public const OWNER_ONLY = ['users.manage', 'billing.manage', 'backups.manage'];

    /** Permission prefix => [arabic, english] group label, in the order the team page lists them. */
    public const GROUPS = [
        'orders' => ['الطلبات', 'Orders'],
        'pos' => ['الكاشير', 'POS'],
        'shifts' => ['الورديات', 'Shifts'],
        'callcenter' => ['الكول سنتر', 'Call center'],
        'delivery' => ['التوصيل', 'Delivery'],
        'kitchen' => ['المطبخ', 'Kitchen'],
        'pager' => ['النداء على الزبون', 'Guest pager'],
        'menu' => ['المنيو', 'Menu'],
        'inventory' => ['المخزون', 'Inventory'],
        'tables' => ['الترابيزات', 'Tables'],
        'branches' => ['الفروع', 'Branches'],
        'customers' => ['العملاء', 'Customers'],
        'loyalty' => ['الولاء', 'Loyalty'],
        'vouchers' => ['الفاوتشرات', 'Vouchers'],
        'contracts' => ['تعاقدات الشركات', 'Corporate contracts'],
        'wallet' => ['المحفظة', 'Wallet'],
        'marketing' => ['التسويق', 'Marketing'],
        'reservations' => ['الحجوزات', 'Reservations'],
        'kb' => ['الأسئلة والأجوبة', 'Knowledge base'],
        'kiosk' => ['الكشك', 'Kiosk'],
        'reports' => ['التقارير', 'Reports'],
        'audit' => ['سجل العمليات', 'Audit log'],
        'settings' => ['الإعدادات', 'Settings'],
        'users' => ['المستخدمين', 'Users'],
        'billing' => ['الاشتراك', 'Subscription'],
        'backups' => ['النسخ الاحتياطية', 'Backups'],
    ];

    /** role => [labels, permissions ('*' = everything), hint shown under the role on the team page] */
    public const ROLES = [
        'owner' => [
            'label' => ['المالك', 'Owner'],
            'permissions' => '*',
            'hint' => ['كل حاجة، ومنها الفريق والصلاحيات والاشتراك', 'Everything, including the team, permissions and the subscription'],
        ],
        'manager' => [
            'label' => ['مدير', 'Manager'],
            'permissions' => ['orders.view', 'orders.manage', 'pos.use', 'pos.discount', 'pos.hold.discard', 'pos.refund', 'callcenter.use', 'shifts.manage',
                'kitchen.view', 'menu.manage', 'menu.availability', 'inventory.manage', 'tables.manage', 'branches.manage', 'delivery.manage',
                'customers.view', 'customers.import', 'loyalty.stamp', 'loyalty.manage', 'vouchers.give', 'marketing.manage', 'reports.view', 'settings.manage', 'audit.view',
                'wallet.manage', 'kiosk.manage', 'reservations.manage', 'contracts.manage', 'vouchers.redeem', 'vouchers.exception',
                'delivery.assign', 'delivery.drivers', 'kb.manage', 'kb.view', 'pager.use'],
            'hint' => ['كل الشغل اليومي والتقارير والإعدادات، من غير المستخدمين والاشتراك', 'Day-to-day work, reports and settings, without users and the subscription'],
        ],
        'cashier' => [
            'label' => ['كاشير', 'Cashier'],
            'permissions' => ['orders.view', 'orders.manage', 'pos.use', 'shifts.manage', 'menu.availability', 'customers.view', 'loyalty.stamp', 'wallet.manage',
                'vouchers.redeem', 'delivery.assign', 'kb.view', 'pager.use'],
            'hint' => ['الكاشير والورديات والطلبات، وصرف الفاوتشرات وتحديد الطيار', 'The POS, shifts and orders, redeeming vouchers and assigning drivers'],
        ],
        'callcenter' => [
            'label' => ['كول سنتر', 'Call center'],
            'permissions' => ['orders.view', 'orders.manage', 'callcenter.use', 'customers.view', 'loyalty.stamp', 'delivery.assign', 'kb.view'],
            'hint' => ['طلبات التليفون والعملاء وتحديد الطيار', 'Phone orders, the customer list and assigning drivers'],
        ],
        'kitchen' => [
            'label' => ['مطبخ', 'Kitchen'],
            'permissions' => ['kitchen.view', 'menu.availability', 'pager.use'],
            'hint' => ['شاشة المطبخ وتحديد الأصناف اللي خلصت والنداء على الزبون بس', 'The kitchen display, marking items sold out and calling the guest, nothing else'],
        ],
        'waiter' => [
            'label' => ['ويتر', 'Waiter'],
            'permissions' => ['orders.view', 'orders.manage', 'pos.use', 'menu.availability', 'kb.view', 'pager.use'],
            'hint' => ['تسجيل طلبات الترابيزات ومتابعتها', 'Taking table orders and following them'],
        ],
        // a delivery driver sees only the orders given to them (App\Http\Controllers\Dashboard\DriverController)
        'driver' => [
            'label' => ['طيار توصيل', 'Delivery driver'],
            'permissions' => ['delivery.drive'],
            'hint' => ['شاشة الطيار على الموبايل: طلباته للتوصيل بس', 'The driver screen on the phone: their own deliveries only'],
        ],
    ];

    /**
     * Roles that approve what changes stock or money after the fact (posting a stock count, cancelling a posted
     * purchase). A staff member given a permission on top of their role does the daily work, not these.
     */
    public const MANAGER_ROLES = ['owner', 'manager'];

    public static function isManager(User $user): bool
    {
        return $user->hasAnyRole(self::MANAGER_ROLES);
    }

    public static function label(string $permission): string
    {
        return self::pick(self::ALL[$permission] ?? [$permission, $permission]);
    }

    public static function roleLabel(string $role): string
    {
        return self::pick(self::ROLES[$role]['label'] ?? [$role, $role]);
    }

    public static function roleHint(string $role): string
    {
        return self::pick(self::ROLES[$role]['hint'] ?? ['', '']);
    }

    public static function groupLabel(string $group): string
    {
        return self::pick(self::GROUPS[$group] ?? [$group, $group]);
    }

    /** @return string[] */
    public static function of(string $role): array
    {
        $p = self::ROLES[$role]['permissions'] ?? [];

        return $p === '*' ? array_keys(self::ALL) : $p;
    }

    /** @return list<string> the permissions a staff member may get on top of their role */
    public static function grantable(): array
    {
        return array_values(array_diff(array_keys(self::ALL), self::OWNER_ONLY));
    }

    /**
     * The grantable permissions by group, in GROUPS order (a prefix missing from GROUPS makes its own group).
     *
     * @return array<string, array{label: string, permissions: list<string>}>
     */
    public static function grouped(): array
    {
        $byGroup = [];
        foreach (self::grantable() as $key) {
            $byGroup[strtok($key, '.')][] = $key;
        }
        $order = array_keys(self::GROUPS);
        $position = fn (string $group) => ($i = array_search($group, $order, true)) === false ? PHP_INT_MAX : $i;
        uksort($byGroup, fn (string $a, string $b) => $position($a) <=> $position($b));

        $out = [];
        foreach ($byGroup as $group => $keys) {
            $out[$group] = ['label' => self::groupLabel($group), 'permissions' => $keys];
        }

        return $out;
    }

    /** @param array{0: string, 1: string} $pair */
    private static function pick(array $pair): string
    {
        return app()->getLocale() === 'en' ? $pair[1] : $pair[0];
    }
}
