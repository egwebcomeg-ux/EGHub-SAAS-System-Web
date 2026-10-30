<?php

namespace App\Support;

/**
 * Permissions of the platform admin team (admin.<domain>) and the default set of each role. Completely apart from
 * the restaurant staff permissions (App\Support\Permissions, spatie roles on the "web" guard): stored on
 * platform_admins.role / platform_admins.permissions and checked by App\Http\Middleware\AdminAccess.
 */
final class AdminPermissions
{
    /** key => [arabic, english] */
    public const ALL = [
        'tenants.view' => ['عرض المطاعم وبياناتها', 'View restaurants'],
        'tenants.manage' => ['إدارة المطاعم ومستخدميها (إيقاف، كلمات السر، التحقق)', 'Manage restaurants and their users'],
        'tenants.impersonate' => ['الدخول على لوحة أي مطعم بحساب صاحبه للدعم الفني (من غير كلمة السر، ساعة بالكتير)', 'Open a restaurant dashboard as its owner for support (no password, one hour at most)'],
        'billing.manage' => ['الاشتراكات والفواتير والرصيد', 'Subscriptions, invoices & credits'],
        'plans.manage' => ['الباقات والإضافات والأسعار', 'Plans, add-ons & prices'],
        'services.manage' => ['طلبات الخدمات', 'Service requests'],
        'content.manage' => ['المدونة وآراء العملاء', 'Blog & testimonials'],
        'assistant.view' => ['أسئلة الزوار للمساعد', 'Assistant questions'],
        'settings.manage' => ['إعدادات الدفع والهوية والشكل', 'Payment settings & branding'],
        'admins.manage' => ['فريق الإدارة والصلاحيات', 'Admin team & permissions'],
        'crm.view' => ['متابعة العملاء: عرض العملاء الجداد والمكالمات', 'Customer follow-up: see leads and calls'],
        'crm.manage' => ['متابعة العملاء: تسجيل المكالمات وتغيير المرحلة والتعيين', 'Customer follow-up: log calls, change stages, assign'],
        'crm.discount' => ['متابعة العملاء: إعطاء خصم على الفاتورة الجاية (في حدود النسبة المسموحة)', 'Customer follow-up: give a discount on the next invoice (within the allowed %)'],
        'crm.visits' => ['الزيارات الميدانية: زياراته وتقاريرها والأماكن اللي معاه بس', 'Field visits: own visits, reports and assigned places only'],
        'crm.import' => ['استيراد أماكن من الخريطة أو ملف', 'Import places from the map or a file'],
        'crm.team' => ['فريق المبيعات: الأداء والتارجت والعمولات وخطة الفريق', 'Sales team: performance, targets, commissions and the pay plan'],
    ];

    /** Default most % each role may give on a call when the admin has no own limit (the owner: TenantDiscount::CAP). */
    public const DISCOUNT_DEFAULTS = ['manager' => 20, 'sales' => 10];

    /** role => [label, permissions ('*' = everything), description] */
    public const ROLES = [
        'owner' => [
            'label' => ['المالك', 'Owner'],
            'permissions' => '*',
            'hint' => ['كل حاجة، ومنها إضافة أعضاء الفريق وتغيير صلاحياتهم', 'Everything, including the admin team'],
        ],
        'manager' => [
            'label' => ['مدير', 'Manager'],
            'permissions' => ['tenants.view', 'tenants.manage', 'billing.manage', 'plans.manage', 'services.manage', 'content.manage', 'assistant.view', 'crm.view', 'crm.manage', 'crm.discount', 'crm.import', 'crm.team'],
            'hint' => ['كل الشغل اليومي من غير إعدادات المنصة وفريق الإدارة', 'Day-to-day work, without platform settings and the team'],
        ],
        'support' => [
            'label' => ['دعم فني', 'Support'],
            'permissions' => ['tenants.view', 'tenants.manage', 'services.manage', 'assistant.view', 'crm.view', 'crm.manage'],
            'hint' => ['يساعد المطاعم: كلمات السر، التحقق، طلبات الخدمات', 'Helps restaurants: passwords, verification, service requests'],
        ],
        'sales' => [
            'label' => ['مبيعات', 'Sales'],
            'permissions' => ['tenants.view', 'crm.view', 'crm.manage', 'crm.discount', 'crm.import'],
            'hint' => ['يكلم العملاء الجداد والأماكن اللي على الخريطة، ويحجز زيارات للمندوب، ويدي خصم في حدود نسبته', 'Calls new sign-ups and mapped places, books visits for the field rep, gives discounts within their limit'],
        ],
        'field_sales' => [
            'label' => ['مندوب ميداني', 'Field rep'],
            'permissions' => ['crm.view', 'crm.visits'],
            'hint' => ['بيزور الأماكن ويكتب تقرير الزيارة ويضيف أماكن جديدة، وبيشوف بس اللي معاه', 'Visits places, reports each visit and adds new places; sees only his own'],
        ],
        'finance' => [
            'label' => ['حسابات', 'Finance'],
            'permissions' => ['tenants.view', 'billing.manage'],
            'hint' => ['الفواتير وتأكيد التحويلات وتجديد الاشتراكات', 'Invoices, payment confirmation, renewals'],
        ],
        'content' => [
            'label' => ['محتوى', 'Content'],
            'permissions' => ['content.manage', 'assistant.view'],
            'hint' => ['المدونة وآراء العملاء وأسئلة المساعد', 'Blog, testimonials and assistant questions'],
        ],
    ];

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

    /** @return list<string> */
    public static function of(string $role): array
    {
        $permissions = self::ROLES[$role]['permissions'] ?? [];

        return $permissions === '*' ? array_keys(self::ALL) : $permissions;
    }

    /** @param array{0: string, 1: string} $pair */
    private static function pick(array $pair): string
    {
        return app()->getLocale() === 'en' ? $pair[1] : $pair[0];
    }
}
