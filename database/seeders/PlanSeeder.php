<?php

namespace Database\Seeders;

use App\Models\AddonPrice;
use App\Models\Plan;
use Illuminate\Database\Seeder;

/**
 * The plans and add-ons. Safe to re-run on production (`php artisan db:seed --class=PlanSeeder --force`):
 *  - plans and add-ons that already exist keep what the super admin edited (prices, names, features); only
 *    features/limits added to the catalogue since are filled in with this default;
 *  - the public line-up is Free, Complete and Enterprise (contact sales); the older Starter, Basic, Pro and Business
 *    plans are hidden from the pricing page and new upgrades but stay as they are for whoever subscribes to them;
 *  - feature packs are no longer sold (existing holders keep theirs); WhatsApp credits, the featured listing, the
 *    extra branch and the 5-users pack stay on sale. Nothing here deletes a plan or touches a subscription.
 */
class PlanSeeder extends Seeder
{
    /** Older plans, kept for their subscribers only. */
    public const LEGACY = ['starter', 'basic', 'pro', 'business'];

    /** Add-ons still on sale under the new line-up; every other feature pack is switched off. */
    public const STILL_SOLD = ['wa_500', 'wa_2000', 'featured_listing', 'branches', 'staff', 'menu_items', 'tables'];

    /** The operations features as they were when the older plans were made (delivery zones was one of them). */
    private const LEGACY_OPERATIONS = ['pos', 'shifts', 'customer_display', 'kitchen_display', 'kitchen_stations', 'call_center', 'delivery_zones', 'kiosk', 'inventory'];

    public function run(): void
    {
        $cycles = ['6' => 10, '12' => 17];

        // Starter = the menu to view only (no ordering), for the smallest cafes.
        $starter = ['excel_import', 'feedback'];
        $basic = array_merge($starter, ['qr_ordering', 'order_board', 'full_branding']);
        $pro = array_merge($basic, ['online_ordering', 'scheduled_orders', 'daily_reports', 'menu_themes', 'printed_menu',
            'offers', 'upsell', 'ai_import', 'customers', 'loyalty_stamps', 'custom_domain', 'whatsapp_notifications', 'reservations']);
        // Business = every menu feature; operations were add-ons. A featured directory listing is paid placement.
        // A feature added to the catalogue later (e.g. corporate_vouchers, backups, knowledge_base) is switched on here for Business, Complete
        // and Enterprise on the next run, and stays off for the smaller plans; nothing a plan already had changes.
        $business = array_values(array_diff(array_keys(Plan::FEATURES), self::LEGACY_OPERATIONS, ['featured_listing']));
        // Complete = every module except operations (POS, kitchen, kiosk, call center, inventory) and per-branch
        // prices, which an extra branch switches on. Enterprise = everything.
        $complete = array_values(array_diff(array_keys(Plan::FEATURES), Plan::addonFeatures(), ['branch_pricing', 'featured_listing']));
        $enterprise = array_keys(Plan::FEATURES);

        $plans = [
            // Free = the menu online with the egmenu badge, no ordering; what a restaurant lands on when a trial or
            // a subscription ends unpaid (App\Billing\Lifecycle), so nothing ever goes offline.
            ['code' => 'free', 'name' => 'مجانية', 'name_en' => 'Free', 'monthly_price' => 0, 'sort_order' => -1,
                'tagline' => 'منيو QR أونلاين لمحلك من غير أي تكلفة، لحد ما تحتاج أكتر',
                'tagline_en' => 'Your QR menu online at no cost, until you need more',
                'features' => ['excel_import'],
                'limits' => ['branches' => 1, 'menu_items' => 30, 'staff' => 1, 'tables' => 5, 'ai_imports_per_month' => 0]],
            // 1,250 a month shown against the regular 2,500 (the launch discount). A plan that already exists keeps the
            // prices the super admin set, crossed-out price included (clearing it ends the launch period).
            ['code' => 'full', 'name' => 'الكاملة', 'name_en' => 'Complete', 'monthly_price' => 1250, 'original_price' => 2500, 'sort_order' => 10, 'is_featured' => true,
                'cycles' => ['6' => 10, '12' => 20],
                'tagline' => 'كل حاجة للمطعم أو الكافيه: الطلبات والتوصيل والحجز والولاء والمحفظة والتسويق والتقارير',
                'tagline_en' => 'Everything for one place: ordering, delivery, bookings, loyalty, wallet, marketing and reports',
                'features' => $complete,
                'limits' => ['branches' => 1, 'menu_items' => null, 'staff' => 8, 'tables' => null, 'ai_imports_per_month' => 20]],
            ['code' => 'enterprise', 'name' => 'Enterprise', 'name_en' => 'Enterprise', 'monthly_price' => 0, 'sort_order' => 20, 'contact_sales' => true,
                'cycles' => [],
                'tagline' => 'للسلاسل والمحلات الكبيرة: الكاشير والمطبخ والكشك والكول سنتر والمخزون وفروع أكتر، بسعر حسب احتياجك',
                'tagline_en' => 'For chains and busy places: POS, kitchen, kiosk, call center, inventory and more branches, priced for you',
                'features' => $enterprise,
                'limits' => ['branches' => null, 'menu_items' => null, 'staff' => null, 'tables' => null, 'ai_imports_per_month' => null]],
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
            ['code' => 'pro', 'name' => 'احترافية', 'name_en' => 'Pro', 'monthly_price' => 399, 'sort_order' => 2,
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
                'monthly_price' => $p['monthly_price'], 'original_price' => $p['original_price'] ?? null,
                'cycle_discounts' => $p['cycles'] ?? $cycles, 'sort_order' => $p['sort_order'],
                'is_featured' => $p['is_featured'] ?? false, 'contact_sales' => $p['contact_sales'] ?? false,
            ]);
            foreach (Plan::FEATURES as $key => $_) {
                $plan->features()->firstOrCreate(['key' => $key], ['type' => 'feature', 'value' => in_array($key, $p['features'], true) ? 1 : 0]);
            }
            foreach (Plan::LIMITS as $key => $_) {
                // null = unlimited, so `??` must not be used here
                $plan->features()->firstOrCreate(['key' => $key], ['type' => 'limit', 'value' => array_key_exists($key, $p['limits']) ? $p['limits'][$key] : 0]);
            }
        }

        // the older plans leave the pricing page (their subscribers keep them exactly as they are)
        Plan::query()->whereIn('code', self::LEGACY)->update(['is_public' => false, 'is_featured' => false]);
        // the free plan's first name was «مجاني»; the line-up calls it «مجانية» (a name edited by hand is kept)
        Plan::query()->where('code', 'free')->where('name', 'مجاني')->update(['name' => 'مجانية']);

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
                'name' => 'التشغيل الكامل (وفّر 25٪)', 'name_en' => 'Full operations (save 25%)',
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
            // limit packs: `credits` = how many units one pack adds (50 items, 10 tables)
            ['key' => 'menu_items', 'type' => 'limit', 'credits' => 50, 'monthly_price' => 29, 'sort_order' => 7, 'name' => '50 صنف إضافي', 'name_en' => '50 extra menu items'],
            ['key' => 'tables', 'type' => 'limit', 'credits' => 10, 'monthly_price' => 29, 'sort_order' => 8, 'name' => '10 ترابيزات إضافية', 'name_en' => '10 extra tables'],
            // every module on its own, on top of any plan — the free one included
            ['key' => 'ordering_pack', 'type' => 'feature', 'monthly_price' => 99, 'sort_order' => 3,
                'name' => 'الطلب من الترابيزة', 'name_en' => 'Table ordering',
                'description' => 'العميل يطلب من موبايله على الترابيزة، والطلبات تظهر لحظياً على لوحة الطلبات. لوحدها فوق أي باقة',
                'description_en' => 'Guests order from their phone at the table and orders show live on the order board. On its own, on top of any plan',
                'features' => ['qr_ordering', 'order_board']],
            ['key' => 'loyalty_pack', 'type' => 'feature', 'monthly_price' => 99, 'sort_order' => 3,
                'name' => 'كارت الولاء', 'name_en' => 'Loyalty card',
                'description' => 'كارت أختام أو نقاط على موبايل العميل، وقاعدة بيانات العملاء، وتقييماتهم. لوحده فوق أي باقة',
                'description_en' => 'A stamp or points card on the guest’s phone, the customer database and guest ratings. On its own, on top of any plan',
                'features' => ['loyalty_stamps', 'loyalty_points', 'customers', 'feedback']],
            ['key' => 'branding_pack', 'type' => 'feature', 'monthly_price' => 49, 'sort_order' => 3,
                'name' => 'هوية المنيو والتصميمات', 'name_en' => 'Menu branding & themes',
                'description' => 'ألوانك وخطوطك ولوجوك، تصميمات المنيو الجاهزة، المنيو المطبوع PDF، وإخفاء شارة egmenu',
                'description_en' => 'Your colours, fonts and logo, the ready menu themes, the printable PDF menu and no egmenu badge',
                'features' => ['full_branding', 'menu_themes', 'printed_menu', 'hide_badge']],
            ['key' => 'offers_pack', 'type' => 'feature', 'monthly_price' => 49, 'sort_order' => 4,
                'name' => 'العروض والكومبو', 'name_en' => 'Offers & combos',
                'description' => 'عروض بالوقت (هابي آور) ونوافذ إعلانية عند فتح المنيو، وكومبو واقتراحات «بيتاكل مع»',
                'description_en' => 'Timed offers (happy hour), popups when the menu opens, combos and “goes well with” pairings',
                'features' => ['offers', 'upsell']],
            ['key' => 'tv_pack', 'type' => 'feature', 'monthly_price' => 49, 'sort_order' => 4,
                'name' => 'شاشات المنيو TV', 'name_en' => 'TV menu screens',
                'description' => 'المنيو على شاشات المحل بتتحدث لوحدها مع الأسعار والعروض',
                'description_en' => 'Your menu on the shop’s screens, updating by itself with prices and offers',
                'features' => ['tv_screens']],
            ['key' => 'team_pack', 'type' => 'feature', 'monthly_price' => 49, 'sort_order' => 6,
                'name' => 'الفريق والتقارير اليومية', 'name_en' => 'Team & daily reports',
                'description' => 'صلاحيات مخصصة لكل موظف، وسجل العمليات، والتقارير اليومية',
                'description_en' => 'Custom permissions per staff member, the audit log and daily reports',
                'features' => ['staff_roles', 'audit_log', 'daily_reports']],
            ['key' => 'multibranch_pack', 'type' => 'feature', 'monthly_price' => 49, 'sort_order' => 6,
                'name' => 'أسعار لكل فرع', 'name_en' => 'Per-branch prices',
                'description' => 'سعر وتوفر مختلف لكل فرع من نفس المنيو (مع فرع إضافي)',
                'description_en' => 'A different price and availability per branch of the same menu (with an extra branch)',
                'features' => ['branch_pricing']],
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
                'name' => '500 رسالة واتساب', 'name_en' => '500 WhatsApp messages',
                'description' => 'رصيد 500 رسالة لرسائل الطلبات والحملات، بيتدفع مرة واحدة ومالوش تاريخ انتهاء',
                'description_en' => 'Credit for 500 order or campaign messages, paid once, never expires'],
            ['key' => 'wa_2000', 'type' => 'credits', 'credits' => 2000, 'monthly_price' => 449, 'sort_order' => 10,
                'name' => '2000 رسالة واتساب', 'name_en' => '2,000 WhatsApp messages',
                'description' => 'رصيد 2000 رسالة (أوفر 25٪)، بيتدفع مرة واحدة ومالوش تاريخ انتهاء',
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
            ['key' => 'cards_pack', 'type' => 'feature', 'monthly_price' => 199, 'sort_order' => 7, 'min_plan' => 'basic',
                'name' => 'بطاقات الولاء المتقدمة', 'name_en' => 'Advanced loyalty cards',
                'description' => 'المحفظة وكروت الهدايا + كاش باك نسبة من كل طلب تتجمع في رصيد العميل، وباقات مسبقة الدفع (10 قهوة بـ150 ج صالحة شهر)، ومستويات عضوية (سيلفر/جولد/VIP) بخصم تلقائي. موجودة في باقة بيزنس',
                'description_en' => 'Wallet & gift cards + cashback on every order into the guest\'s balance, prepaid packages (10 coffees for 150 EGP, valid a month) and membership tiers (Silver/Gold/VIP) with an automatic discount. Included in Business',
                'features' => ['wallet', 'cashback', 'prepaid_packages', 'membership', 'customers']],
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

        // with Complete and Enterprise, feature packs are no longer sold to new customers (holders keep theirs)
        AddonPrice::query()->whereNotIn('key', self::STILL_SOLD)->where('type', 'feature')->update(['is_active' => false]);
        // an extra branch on the Complete plan: 600 a month, with its own staff accounts and per-branch prices
        AddonPrice::query()->where('key', 'branches')->update([
            'monthly_price' => 600, 'credits' => 1, 'is_active' => true, 'min_plan' => 'full', 'features' => ['branch_pricing'],
            'name' => 'فرع إضافي', 'name_en' => 'Extra branch',
            'description' => 'فرع زيادة بنفس المنيو وأسعاره الخاصة، ومعاه 8 مستخدمين. لحد 5 فروع، وأكتر من كده باقة Enterprise',
            'description_en' => 'One more branch with the same menu and its own prices, with 8 more users. Up to 5 branches; more is the Enterprise plan',
        ]);
        // extra users come five at a time
        AddonPrice::query()->where('key', 'staff')->update([
            'monthly_price' => 100, 'credits' => 5, 'is_active' => true,
            'name' => '5 مستخدمين', 'name_en' => '5 users',
            'description' => '5 حسابات موظفين زيادة على باقتك', 'description_en' => '5 more staff accounts on top of your plan',
        ]);
    }
}
