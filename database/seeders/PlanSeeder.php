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

        // Starter = the menu to view only (no ordering), for the smallest cafes.
        $starter = ['excel_import', 'feedback'];
        $basic = array_merge($starter, ['qr_ordering', 'order_board', 'full_branding']);
        $pro = array_merge($basic, ['online_ordering', 'scheduled_orders', 'daily_reports', 'menu_themes', 'printed_menu',
            'offers', 'upsell', 'ai_import', 'customers', 'loyalty_stamps', 'custom_domain']);
        // Business = every menu feature; operations (POS, kitchen, call center, inventory) are add-ons for every plan.
        $business = array_values(array_diff(array_keys(Plan::FEATURES), Plan::addonFeatures()));

        $plans = [
            ['code' => 'starter', 'name' => 'مبتدئ', 'name_en' => 'Starter', 'monthly_price' => 99, 'sort_order' => 0,
                'tagline' => 'منيو QR بشكل محترم لكافيه صغير: العميل يشوف المنيو والأسعار',
                'tagline_en' => 'A good-looking QR menu for a small cafe: guests browse the menu and prices',
                'features' => $starter,
                'limits' => ['branches' => 1, 'menu_items' => 60, 'staff' => 1, 'tables' => 10, 'ai_imports_per_month' => 0]],
            ['code' => 'basic', 'name' => 'أساسية', 'name_en' => 'Basic', 'monthly_price' => 199, 'sort_order' => 1,
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
            ['key' => 'pos_pack', 'type' => 'feature', 'monthly_price' => 199, 'sort_order' => 1, 'per_branch' => true, 'min_plan' => 'basic',
                'name' => 'الكاشير', 'name_en' => 'POS pack',
                'description' => 'نقطة بيع، وورديات ودرج كاش، وشاشة عميل، ولوحة أرقام الطلبات',
                'description_en' => 'Point of sale, shifts & cash drawer, customer display, order-number board',
                'features' => ['pos', 'shifts', 'customer_display']],
            ['key' => 'kitchen_pack', 'type' => 'feature', 'monthly_price' => 149, 'sort_order' => 2, 'per_branch' => true, 'min_plan' => 'basic',
                'name' => 'شاشة المطبخ', 'name_en' => 'Kitchen display',
                'description' => 'شاشة طلبات للمطبخ بالوقت والألوان، وأقسام مطبخ (شاشة لكل قسم)',
                'description_en' => 'Kitchen order screen with timers, kitchen stations (a screen per station)',
                'features' => ['kitchen_display', 'kitchen_stations']],
            ['key' => 'callcenter_pack', 'type' => 'feature', 'monthly_price' => 199, 'sort_order' => 3, 'min_plan' => 'basic',
                'name' => 'الكول سنتر والتوصيل', 'name_en' => 'Call center & delivery',
                'description' => 'شاشة كول سنتر بالبحث برقم العميل، ومناطق توصيل برسوم وحد أدنى لكل منطقة',
                'description_en' => 'Call-center screen with phone lookup, delivery zones with fees and minimums',
                'features' => ['call_center', 'delivery_zones', 'customers']],
            ['key' => 'inventory_pack', 'type' => 'feature', 'monthly_price' => 199, 'sort_order' => 4, 'per_branch' => true, 'min_plan' => 'basic',
                'name' => 'المخزون', 'name_en' => 'Inventory',
                'description' => 'خامات وتكلفة وصفات وخصم تلقائي من المخزون',
                'description_en' => 'Ingredients, recipe costing and automatic stock deduction',
                'features' => ['inventory']],
            ['key' => 'ops_bundle', 'type' => 'feature', 'monthly_price' => 549, 'sort_order' => 0, 'per_branch' => true, 'min_plan' => 'basic',
                'name' => 'التشغيل الكامل (وفّر ٢٥٪)', 'name_en' => 'Full operations (save 25%)',
                'description' => 'الكاشير وشاشة المطبخ والكول سنتر والمخزون مع بعض بسعر أقل',
                'description_en' => 'POS, kitchen display, call center and inventory together for less',
                'features' => ['pos', 'shifts', 'customer_display', 'kitchen_display', 'kitchen_stations', 'call_center', 'delivery_zones', 'customers', 'inventory']],
            ['key' => 'online_pack', 'type' => 'feature', 'monthly_price' => 249, 'sort_order' => 5,
                'name' => 'الطلب الأونلاين', 'name_en' => 'Online ordering',
                'description' => 'تيك أواي وتوصيل من لينك المنيو بدل عمولة تطبيقات التوصيل، والطلب بميعاد',
                'description_en' => 'Takeaway and delivery from your menu link instead of app commissions, with scheduled orders',
                'features' => ['online_ordering', 'scheduled_orders']],
            ['key' => 'domain_pack', 'type' => 'feature', 'monthly_price' => 49, 'sort_order' => 5,
                'name' => 'دومين خاص', 'name_en' => 'Own domain',
                'description' => 'المنيو وأكواد QR على دومينك (mycafe.com). سعر حجز الدومين نفسه من egweb.com.eg',
                'description_en' => 'Your menu and QR codes on your own domain (mycafe.com). The domain itself is booked on egweb.com.eg',
                'features' => ['custom_domain']],
            ['key' => 'branches', 'type' => 'limit', 'monthly_price' => 99, 'sort_order' => 5, 'name' => 'فرع إضافي', 'name_en' => 'Extra branch'],
            ['key' => 'staff', 'type' => 'limit', 'monthly_price' => 25, 'sort_order' => 6, 'name' => 'مستخدم إضافي', 'name_en' => 'Extra staff account'],
        ] as $a) {
            AddonPrice::query()->firstOrCreate(['key' => $a['key']], $a);
        }
    }
}
