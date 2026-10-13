<?php

namespace Database\Seeders;

use App\Models\AddonPrice;
use App\Models\Plan;
use Illuminate\Database\Seeder;

/**
 * Initial plans. Safe to re-run: plans that already exist (edited from the super admin) are left untouched,
 * only features/limits added to the catalogue since are filled in with this default.
 */
class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $cycles = ['6' => 10, '12' => 17];

        $basic = ['qr_ordering', 'order_board', 'full_branding', 'excel_import', 'feedback'];
        $pro = array_merge($basic, ['whatsapp_orders', 'scheduled_orders', 'daily_reports', 'menu_themes', 'printed_menu',
            'offers', 'upsell', 'ai_import', 'customers', 'loyalty_stamps']);
        // Business = every menu feature; operations (POS, kitchen, call center, inventory) are add-ons for every plan.
        $business = array_values(array_diff(array_keys(Plan::FEATURES), Plan::addonFeatures()));

        $plans = [
            ['code' => 'basic', 'name' => 'أساسية', 'name_en' => 'Basic', 'monthly_price' => 149, 'sort_order' => 1,
                'tagline' => 'منيو QR وطلب من الترابيزة لمطعم أو كافيه بفرع واحد',
                'tagline_en' => 'QR menu and table ordering for a single-branch venue',
                'features' => $basic,
                'limits' => ['branches' => 1, 'menu_items' => 150, 'staff' => 2, 'tables' => 15, 'ai_imports_per_month' => 0]],
            ['code' => 'pro', 'name' => 'احترافية', 'name_en' => 'Pro', 'monthly_price' => 399, 'sort_order' => 2, 'is_featured' => true,
                'tagline' => 'طلب من الترابيزة وكارت ولاء وعروض ومنيو مطبوع',
                'tagline_en' => 'Table ordering, loyalty card, offers and a printed menu',
                'features' => $pro,
                'limits' => ['branches' => 1, 'menu_items' => null, 'staff' => 8, 'tables' => 50, 'ai_imports_per_month' => 5]],
            ['code' => 'business', 'name' => 'بيزنس', 'name_en' => 'Business', 'monthly_price' => 799, 'sort_order' => 3,
                'tagline' => 'فروع متعددة وأسعار لكل فرع ونقاط ولاء وصلاحيات كاملة',
                'tagline_en' => 'Multiple branches, per-branch prices, loyalty points and full permissions',
                'features' => $business,
                'limits' => ['branches' => 3, 'menu_items' => null, 'staff' => null, 'tables' => null, 'ai_imports_per_month' => 20]],
        ];

        foreach ($plans as $p) {
            $plan = Plan::query()->firstOrCreate(['code' => $p['code']], [
                'name' => $p['name'], 'name_en' => $p['name_en'], 'tagline' => $p['tagline'], 'tagline_en' => $p['tagline_en'],
                'monthly_price' => $p['monthly_price'], 'cycle_discounts' => $cycles, 'sort_order' => $p['sort_order'],
                'is_featured' => $p['is_featured'] ?? false,
            ]);
            foreach (Plan::FEATURES as $key => $_) {
                $plan->features()->firstOrCreate(['key' => $key], ['type' => 'feature', 'value' => in_array($key, $p['features'], true) ? 1 : 0]);
            }
            foreach (Plan::LIMITS as $key => $_) {
                // null = unlimited, so `??` must not be used here
                $plan->features()->firstOrCreate(['key' => $key], ['type' => 'limit', 'value' => array_key_exists($key, $p['limits']) ? $p['limits'][$key] : 0]);
            }
        }

        foreach ([
            ['key' => 'pos_pack', 'type' => 'feature', 'monthly_price' => 199, 'sort_order' => 1,
                'name' => 'الكاشير', 'name_en' => 'POS pack',
                'description' => 'نقطة بيع، وورديات ودرج كاش، وشاشة عميل، ولوحة أرقام الطلبات',
                'description_en' => 'Point of sale, shifts & cash drawer, customer display, order-number board',
                'features' => ['pos', 'shifts', 'customer_display']],
            ['key' => 'kitchen_pack', 'type' => 'feature', 'monthly_price' => 149, 'sort_order' => 2,
                'name' => 'شاشة المطبخ', 'name_en' => 'Kitchen display',
                'description' => 'شاشة طلبات للمطبخ بالوقت والألوان، وأقسام مطبخ (شاشة لكل قسم)',
                'description_en' => 'Kitchen order screen with timers, kitchen stations (a screen per station)',
                'features' => ['kitchen_display', 'kitchen_stations']],
            ['key' => 'callcenter_pack', 'type' => 'feature', 'monthly_price' => 199, 'sort_order' => 3,
                'name' => 'الكول سنتر والتوصيل', 'name_en' => 'Call center & delivery',
                'description' => 'شاشة كول سنتر بالبحث برقم العميل، ومناطق توصيل على الخريطة',
                'description_en' => 'Call-center screen with phone lookup, delivery zones on a map',
                'features' => ['call_center', 'delivery_zones', 'customers']],
            ['key' => 'inventory_pack', 'type' => 'feature', 'monthly_price' => 199, 'sort_order' => 4,
                'name' => 'المخزون', 'name_en' => 'Inventory',
                'description' => 'خامات وتكلفة وصفات وخصم تلقائي من المخزون',
                'description_en' => 'Ingredients, recipe costing and automatic stock deduction',
                'features' => ['inventory']],
            ['key' => 'branches', 'type' => 'limit', 'monthly_price' => 99, 'sort_order' => 5, 'name' => 'فرع إضافي', 'name_en' => 'Extra branch'],
            ['key' => 'staff', 'type' => 'limit', 'monthly_price' => 25, 'sort_order' => 6, 'name' => 'مستخدم إضافي', 'name_en' => 'Extra staff account'],
        ] as $a) {
            AddonPrice::query()->firstOrCreate(['key' => $a['key']], $a);
        }
    }
}
