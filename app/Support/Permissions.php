<?php

namespace App\Support;

/** Dashboard permissions and the default permission set of each staff role. */
final class Permissions
{
    /** key => [arabic, english] */
    public const ALL = [
        'orders.view' => ['عرض الطلبات', 'View orders'],
        'orders.manage' => ['تغيير حالة الطلبات', 'Update orders'],
        'pos.use' => ['تسجيل طلبات (كاشير / ويتر)', 'Take orders (POS / waiter)'],
        'pos.discount' => ['عمل خصم على الطلب', 'Give order discounts'],
        'callcenter.use' => ['شاشة الكول سنتر', 'Call-center screen'],
        'shifts.manage' => ['فتح وقفل الورديات والدرج', 'Open/close shifts & cash drawer'],
        'delivery.manage' => ['مناطق التوصيل', 'Delivery zones'],
        'kitchen.view' => ['شاشة المطبخ', 'Kitchen display'],
        'menu.manage' => ['إدارة المنيو والأقسام', 'Manage menu'],
        'menu.availability' => ['تحديد الأصناف اللي خلصت', 'Mark items sold out'],
        'inventory.manage' => ['المخزون والوصفات', 'Inventory & recipes'],
        'tables.manage' => ['الترابيزات وأكواد QR', 'Tables & QR codes'],
        'branches.manage' => ['الفروع', 'Branches'],
        'customers.view' => ['العملاء', 'Customers'],
        'customers.import' => ['استيراد عملاء من ملف', 'Import customers from a file'],
        'loyalty.stamp' => ['إضافة أختام وصرف مكافآت', 'Give stamps & redeem rewards'],
        'loyalty.manage' => ['إعداد برنامج الولاء', 'Loyalty program setup'],
        'vouchers.give' => ['إدّي فاوتشرات خصم للعملاء', 'Give discount vouchers to guests'],
        'marketing.manage' => ['العروض والتسويق', 'Offers & marketing'],
        'reports.view' => ['التقارير', 'Reports'],
        'settings.manage' => ['الإعدادات والهوية', 'Settings & branding'],
        'users.manage' => ['المستخدمين والصلاحيات', 'Users & roles'],
        'billing.manage' => ['الاشتراك والفواتير', 'Subscription & billing'],
        'audit.view' => ['سجل العمليات', 'Audit log'],
        'wallet.manage' => ['المحفظة وكروت الهدايا', 'Wallet & gift cards'],
        'kiosk.manage' => ['الكشك (طلب ذاتي)', 'Self-order kiosks'],
        'reservations.manage' => ['الحجوزات', 'Reservations'],
    ];

    /** role => [labels, permissions ('*' = everything)] */
    public const ROLES = [
        'owner' => [
            'label' => ['المالك', 'Owner'],
            'permissions' => '*',
        ],
        'manager' => [
            'label' => ['مدير', 'Manager'],
            'permissions' => ['orders.view', 'orders.manage', 'pos.use', 'pos.discount', 'callcenter.use', 'shifts.manage',
                'kitchen.view', 'menu.manage', 'menu.availability', 'inventory.manage', 'tables.manage', 'branches.manage', 'delivery.manage',
                'customers.view', 'customers.import', 'loyalty.stamp', 'loyalty.manage', 'vouchers.give', 'marketing.manage', 'reports.view', 'settings.manage', 'audit.view',
                'wallet.manage', 'kiosk.manage', 'reservations.manage'],
        ],
        'cashier' => [
            'label' => ['كاشير', 'Cashier'],
            'permissions' => ['orders.view', 'orders.manage', 'pos.use', 'shifts.manage', 'menu.availability', 'customers.view', 'loyalty.stamp', 'wallet.manage'],
        ],
        'callcenter' => [
            'label' => ['كول سنتر', 'Call center'],
            'permissions' => ['orders.view', 'orders.manage', 'callcenter.use', 'customers.view', 'loyalty.stamp'],
        ],
        'kitchen' => [
            'label' => ['مطبخ', 'Kitchen'],
            'permissions' => ['kitchen.view', 'menu.availability'],
        ],
        'waiter' => [
            'label' => ['ويتر', 'Waiter'],
            'permissions' => ['orders.view', 'orders.manage', 'pos.use', 'menu.availability'],
        ],
    ];

    public static function label(string $permission): string
    {
        $l = self::ALL[$permission] ?? [$permission, $permission];

        return app()->getLocale() === 'en' ? $l[1] : $l[0];
    }

    public static function roleLabel(string $role): string
    {
        $l = self::ROLES[$role]['label'] ?? [$role, $role];

        return app()->getLocale() === 'en' ? $l[1] : $l[0];
    }

    /** @return string[] */
    public static function of(string $role): array
    {
        $p = self::ROLES[$role]['permissions'] ?? [];

        return $p === '*' ? array_keys(self::ALL) : $p;
    }
}
