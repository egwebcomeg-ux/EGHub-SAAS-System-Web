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
            'offers', 'upsell', 'ai_import', 'customers', 'loyalty_stamps', 'custom_domain', 'whatsapp_notifications', 'reservations']);
        // Business = every menu feature; operations (POS, kitchen, call center, inventory) are add-ons for every plan.
        // A featured directory listing is paid placement, sold only as its own add-on.
        $business = array_values(array_diff(array_keys(Plan::FEATURES), Plan::addonFeatures(), ['featured_listing']));

        $plans = [
            // Free = the menu online with the egmenu badge, no ordering; what a restaurant lands on when a trial or
            // a subscription ends unpaid (App\Billing\Lifecycle), so nothing ever goes offline.
            ['code' => 'free', 'name' => 'مجاني', 'name_en' => 'Free', 'monthly_price' => 0, 'sort_order' => -1,
                'tagline' => 'منيو QR أونلاين لمحلك من غير أي تكلفة، لحد ما تحتاج أكتر',
                'tagline_en' => 'Your QR menu online at no cost, until you need more',
                'features' => ['excel_import'],
                'limits' => ['branches' => 1, 'menu_items' => 30, 'staff' => 1, 'tables' => 5, 'ai_imports_per_month' => 0]],
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
            ['key' => 'whatsapp_pack', 'type' => 'feature', 'monthly_price' => 99, 'sort_order' => 7,
                'name' => 'رسائل واتساب للعملاء', 'name_en' => 'WhatsApp guest updates',
                'description' => 'رسالة واتساب للعميل لما طلبه يوصل ويجهز ويخرج للتوصيل، وتأكيد الحجز والمكافآت (الرسائل من رصيد الواتساب)',
                'description_en' => 'WhatsApp messages when an order is received, ready or on its way, plus bookings and rewards (messages use WhatsApp credits)',
                'features' => ['whatsapp_notifications']],
            ['key' => 'marketing_pack', 'type' => 'feature', 'monthly_price' => 199, 'sort_order' => 8,
                'name' => 'حملات التسويق', 'name_en' => 'Marketing campaigns',
                'description' => 'حملات واتساب لعملائك حسب آخر زيارة وعدد الزيارات وأعياد الميلاد، مع كوبونات خصم (الرسائل من رصيد الواتساب)',
                'description_en' => 'WhatsApp campaigns to your guests by last visit, visits and birthdays, with discount coupons (messages use WhatsApp credits)',
                'features' => ['campaigns', 'customers']],
            ['key' => 'wa_500', 'type' => 'credits', 'credits' => 500, 'monthly_price' => 149, 'sort_order' => 9,
                'name' => '٥٠٠ رسالة واتساب', 'name_en' => '500 WhatsApp messages',
                'description' => 'رصيد ٥٠٠ رسالة لرسائل الطلبات والحملات، بيتدفع مرة واحدة ومالوش تاريخ انتهاء',
                'description_en' => 'Credit for 500 order or campaign messages, paid once, never expires'],
            ['key' => 'wa_2000', 'type' => 'credits', 'credits' => 2000, 'monthly_price' => 449, 'sort_order' => 10,
                'name' => '٢٠٠٠ رسالة واتساب', 'name_en' => '2,000 WhatsApp messages',
                'description' => 'رصيد ٢٠٠٠ رسالة (أوفر ٢٥٪)، بيتدفع مرة واحدة ومالوش تاريخ انتهاء',
                'description_en' => 'Credit for 2,000 messages (save 25%), paid once, never expires'],
            ['key' => 'languages_pack', 'type' => 'feature', 'monthly_price' => 99, 'sort_order' => 5,
                'name' => 'لغات منيو إضافية', 'name_en' => 'Extra menu languages',
                'description' => 'المنيو بالفرنساوي والإيطالي والألماني والروسي والإسباني والتركي والصيني، وترجمة بضغطة بالذكاء الاصطناعي',
                'description_en' => 'Your menu in French, Italian, German, Russian, Spanish, Turkish and Chinese, with one-click AI translation',
                'features' => ['extra_languages']],
            ['key' => 'reports_pack', 'type' => 'feature', 'monthly_price' => 149, 'sort_order' => 5,
                'name' => 'تقارير متقدمة', 'name_en' => 'Advanced reports',
                'description' => 'أداء الأصناف وخريطة الزحمة ومقارنة الفروع والفترات وتصدير CSV وتقرير أسبوعي بالإيميل',
                'description_en' => 'Item performance, busy-hours heat map, branch and period comparison, CSV exports and a weekly email report',
                'features' => ['advanced_reports', 'daily_reports']],
            ['key' => 'wallet_pack', 'type' => 'feature', 'monthly_price' => 149, 'sort_order' => 7, 'min_plan' => 'basic',
                'name' => 'المحفظة وكروت الهدايا', 'name_en' => 'Wallet & gift cards',
                'description' => 'العميل يشحن رصيد عندك ويدفع منه في المحل أو أونلاين، وكروت هدايا مطبوعة بكود وQR يضيفها لرصيده. موجودة في باقة بيزنس',
                'description_en' => 'Guests top up a balance and pay from it in the shop or online, plus printed gift cards with a code and QR they add to their wallet. Included in Business',
                'features' => ['wallet', 'customers']],
            ['key' => 'featured_listing', 'type' => 'feature', 'monthly_price' => 199, 'sort_order' => 8,
                'name' => 'ظهور مميز في دليل المطاعم', 'name_en' => 'Featured directory listing',
                'description' => 'مطعمك يظهر في أول دليل egmenu.app/restaurants وصفحة مدينتك ونوع أكلك بعلامة «مميز» (5 أماكن بالدور في كل صفحة). لازم يكون ظاهر في الدليل',
                'description_en' => 'Your place shows first in egmenu.app/restaurants, your city and cuisine pages with a "Featured" badge (5 places take turns per page). Your place must be listed in the directory',
                'features' => ['featured_listing']],
            ['key' => 'kiosk_pack', 'type' => 'feature', 'monthly_price' => 149, 'sort_order' => 7, 'per_branch' => true, 'min_plan' => 'basic',
                'name' => 'كشك الطلب الذاتي', 'name_en' => 'Self-order kiosk',
                'description' => 'تابلت عند الكاشير: العميل يختار ويطلب بنفسه وياخد رقم طلبه ويدفع عند الكاشير',
                'description_en' => 'A tablet at the counter: guests order by themselves, get their order number and pay at the cashier',
                'features' => ['kiosk']],
            ['key' => 'reservations_pack', 'type' => 'feature', 'monthly_price' => 149, 'sort_order' => 8, 'min_plan' => 'basic',
                'name' => 'حجز الترابيزات', 'name_en' => 'Table reservations',
                'description' => 'العميل يحجز ترابيزة من لينك المنيو بميعاد وعدد أفراد، وانت تأكد وتتابع الحجوزات من لوحة الطلبات',
                'description_en' => 'Guests book a table from your menu link with a time and party size; you confirm and follow them from the order board',
                'features' => ['reservations']],
        ] as $a) {
            AddonPrice::query()->firstOrCreate(['key' => $a['key']], $a);
        }
    }
}
