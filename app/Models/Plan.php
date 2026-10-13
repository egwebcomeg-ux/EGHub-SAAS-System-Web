<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A subscription plan. Prices, features and limits are rows (plans / plan_features) edited from the super admin;
 * the constants below are only the catalogue of what a plan *can* switch on or cap, with their labels.
 */
class Plan extends Model
{
    /** Cycle lengths we sell, in months. */
    public const CYCLES = [1, 6, 12];

    /** key => [arabic label, english label, group] */
    public const FEATURES = [
        // ordering
        'qr_ordering'      => ['الطلب من الترابيزة', 'Table ordering', 'orders'],
        'order_board'      => ['لوحة الطلبات', 'Live order board', 'orders'],
        'whatsapp_orders'  => ['طلبات واتساب', 'WhatsApp orders', 'orders'],
        'pos'              => ['كاشير POS', 'POS cashier', 'orders'],
        'kitchen_display'  => ['شاشة المطبخ', 'Kitchen display', 'orders'],
        'kitchen_stations' => ['أقسام مطبخ (شاشة لكل قسم)', 'Kitchen stations (screen per station)', 'orders'],
        'customer_display' => ['شاشة العميل ولوحة أرقام الطلبات', 'Customer display & order-number board', 'orders'],
        'shifts'           => ['ورديات الكاشير والدرج', 'Cashier shifts & cash drawer', 'orders'],
        'scheduled_orders' => ['طلبات مجدولة', 'Scheduled orders', 'orders'],
        'call_center'      => ['شاشة الكول سنتر', 'Call-center screen', 'orders'],
        'delivery_zones'   => ['مناطق توصيل على الخريطة', 'Delivery zones on a map', 'orders'],
        // menu
        'full_branding'    => ['ألوان وخطوط ولوجو المطعم', 'Colors, fonts & logo', 'menu'],
        'menu_themes'      => ['تصميمات منيو جاهزة', 'Menu themes', 'menu'],
        'printed_menu'     => ['المنيو المطبوع PDF', 'Printable menu (PDF)', 'menu'],
        'tv_screens'       => ['شاشات منيو TV', 'TV menu screens', 'menu'],
        'excel_import'     => ['استيراد المنيو من Excel', 'Import menu from Excel', 'menu'],
        'ai_import'        => ['تحويل صورة المنيو لأصناف (AI)', 'Menu photo to items (AI)', 'menu'],
        'branch_pricing'   => ['أسعار وتوفر لكل فرع', 'Per-branch prices & availability', 'menu'],
        // marketing
        'offers'           => ['عروض بالوقت ونوافذ إعلانية', 'Timed offers & popups', 'marketing'],
        'upsell'           => ['كومبو واقتراحات «بيتاكل مع»', 'Combos & pairings', 'marketing'],
        'feedback'         => ['تقييمات العملاء', 'Guest feedback', 'marketing'],
        'customers'        => ['قاعدة بيانات العملاء', 'Customer database', 'marketing'],
        'loyalty_stamps'   => ['كارت ولاء ديجيتال (أختام)', 'Digital stamp card', 'marketing'],
        'loyalty_points'   => ['نقاط ولاء', 'Loyalty points', 'marketing'],
        // management
        'daily_reports'    => ['تقارير يومية', 'Daily reports', 'management'],
        'advanced_reports' => ['تقارير متقدمة', 'Advanced reports', 'management'],
        'inventory'        => ['مخزون وتكلفة وصفات', 'Inventory & recipe costing', 'management'],
        'staff_roles'      => ['صلاحيات موظفين مخصصة', 'Custom staff permissions', 'management'],
        'audit_log'        => ['سجل العمليات', 'Audit log', 'management'],
        // platform
        'custom_domain'    => ['دومين خاص', 'Custom domain', 'platform'],
        'hide_badge'       => ['إخفاء شارة egmenu', 'Remove egmenu badge', 'platform'],
        'priority_support' => ['أولوية في الدعم', 'Priority support', 'platform'],
    ];

    /** key => [arabic label, english label] — value null = unlimited */
    public const LIMITS = [
        'branches'             => ['الفروع', 'Branches'],
        'menu_items'           => ['الأصناف', 'Menu items'],
        'staff'                => ['المستخدمين', 'Staff accounts'],
        'tables'               => ['الترابيزات', 'Tables'],
        'ai_imports_per_month' => ['تحويل صور المنيو شهرياً', 'AI menu imports / month'],
    ];

    public const GROUPS = [
        'orders'     => ['الطلبات', 'Ordering'],
        'menu'       => ['المنيو', 'Menu'],
        'marketing'  => ['التسويق والعملاء', 'Marketing & guests'],
        'management' => ['الإدارة', 'Management'],
        'platform'   => ['المنصة', 'Platform'],
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'monthly_price'   => 'float',
            'original_price'  => 'float',
            'cycle_discounts' => 'array',
            'is_public'       => 'boolean',
            'is_featured'     => 'boolean',
        ];
    }

    public static function label(string $key): string
    {
        $l = self::FEATURES[$key] ?? self::LIMITS[$key] ?? [$key, $key];
        return app()->getLocale() === 'en' ? $l[1] : $l[0];
    }

    public function displayName(): string
    {
        return app()->getLocale() === 'en' && $this->name_en !== '' ? $this->name_en : $this->name;
    }

    public function features(): HasMany
    {
        return $this->hasMany(PlanFeature::class);
    }

    /** Price for a whole cycle after its discount, rounded to whole pounds (3,974 not 3,973.92). */
    public function priceFor(int $months): float
    {
        $pct = (float) ($this->cycle_discounts[(string) $months] ?? 0);
        return round($this->monthly_price * $months * (1 - $pct / 100));
    }

    /** @return array{features: array<string,bool>, limits: array<string,int|null>} */
    public function entitlements(): array
    {
        $out = ['features' => array_fill_keys(array_keys(self::FEATURES), false), 'limits' => array_fill_keys(array_keys(self::LIMITS), 0)];
        foreach ($this->features as $f) {
            if ($f->type === 'feature' && isset(self::FEATURES[$f->key])) {
                $out['features'][$f->key] = (bool) $f->value;
            } elseif ($f->type === 'limit' && array_key_exists($f->key, self::LIMITS)) {
                $out['limits'][$f->key] = $f->value; // null = unlimited
            }
        }
        return $out;
    }
}
