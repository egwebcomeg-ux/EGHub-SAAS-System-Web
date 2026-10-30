<?php

namespace App\Models;

use App\Billing\CustomPlan;
use App\Billing\Lifecycle;
use App\Services\Assistant;
use App\Services\Entitlements;
use App\Services\TenantProvisioner;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * A subscription plan. Prices, features and limits are rows (plans / plan_features) edited from the super admin;
 * the constants below are only the catalogue of what a plan *can* switch on or cap, with their labels.
 */
class Plan extends Model
{
    /** Platform setting: last day of the launch price (Y-m-d), shown next to the crossed-out price. */
    public const LAUNCH_OFFER_ENDS_KEY = 'launch_offer_ends_at';

    /** Cycle lengths we sell, in months. */
    public const CYCLES = [1, 6, 12];

    /** key => [arabic label, english label, group] */
    public const FEATURES = [
        // ordering
        'qr_ordering' => ['الطلب من الترابيزة', 'Table ordering', 'orders'],
        'order_board' => ['لوحة الطلبات', 'Live order board', 'orders'],
        'online_ordering' => ['طلب أونلاين (تيك أواي وتوصيل) من غير عمولة', 'Online ordering (takeaway & delivery), no commission', 'orders'],
        'scheduled_orders' => ['طلب بميعاد (العميل يختار وقت الاستلام)', 'Scheduled orders (guest picks the time)', 'orders'],
        'reservations' => ['حجز ترابيزات أونلاين', 'Table reservations', 'orders'],
        'delivery_zones' => ['مناطق التوصيل ورسوم كل منطقة', 'Delivery zones and fees', 'orders'],
        'pager' => ['النداء على الزبون (موبايله هو البيجر)', 'Guest pager (the guest’s phone rings when the order is ready)', 'orders'],
        // operations: in the Enterprise plan only (older subscribers may still hold them as add-on packs)
        'pos' => ['كاشير POS', 'POS cashier', 'operations'],
        'shifts' => ['ورديات الكاشير والدرج', 'Cashier shifts & cash drawer', 'operations'],
        'customer_display' => ['شاشة العميل ولوحة أرقام الطلبات', 'Customer display & order-number board', 'operations'],
        'kitchen_display' => ['شاشة المطبخ', 'Kitchen display', 'operations'],
        'kitchen_stations' => ['أقسام مطبخ (شاشة لكل قسم)', 'Kitchen stations (screen per station)', 'operations'],
        'call_center' => ['شاشة الكول سنتر', 'Call-center screen', 'operations'],
        'kiosk' => ['كشك طلب ذاتي (تابلت عند الكاشير)', 'Self-order kiosk (tablet at the counter)', 'operations'],
        // menu
        'full_branding' => ['ألوان وخطوط ولوجو المطعم', 'Colors, fonts & logo', 'menu'],
        'menu_themes' => ['تصميمات منيو جاهزة', 'Menu themes', 'menu'],
        'printed_menu' => ['المنيو المطبوع PDF', 'Printable menu (PDF)', 'menu'],
        'tv_screens' => ['شاشات منيو TV', 'TV menu screens', 'menu'],
        'excel_import' => ['استيراد المنيو من Excel', 'Import menu from Excel', 'menu'],
        'ai_import' => ['تحويل صورة المنيو لأصناف (AI)', 'Menu photo to items (AI)', 'menu'],
        'branch_pricing' => ['أسعار وتوفر لكل فرع', 'Per-branch prices & availability', 'menu'],
        'extra_languages' => ['لغات منيو إضافية (فرنساوي، إيطالي، روسي...)', 'Extra menu languages (French, Italian, Russian...)', 'menu'],
        // marketing
        'offers' => ['عروض بالوقت ونوافذ إعلانية', 'Timed offers & popups', 'marketing'],
        'upsell' => ['كومبو واقتراحات «بيتاكل مع»', 'Combos & pairings', 'marketing'],
        'feedback' => ['تقييمات العملاء', 'Guest feedback', 'marketing'],
        'customers' => ['قاعدة بيانات العملاء', 'Customer database', 'marketing'],
        'loyalty_stamps' => ['كارت ولاء ديجيتال (أختام)', 'Digital stamp card', 'marketing'],
        'loyalty_points' => ['نقاط ولاء', 'Loyalty points', 'marketing'],
        'whatsapp_notifications' => ['رسائل واتساب للعميل (استلام الطلب، جاهز، في الطريق)', 'WhatsApp updates to guests (order received, ready, on the way)', 'marketing'],
        'campaigns' => ['حملات واتساب وكوبونات خصم', 'WhatsApp campaigns & coupons', 'marketing'],
        'wallet' => ['محفظة رصيد مسبق وكروت هدايا', 'Prepaid wallet & gift cards', 'marketing'],
        'cashback' => ['كاش باك على المحفظة مع كل طلب', 'Cashback to the wallet on every order', 'marketing'],
        'prepaid_packages' => ['باقات مسبقة الدفع (اشتراك ١٠ قهوة مثلاً)', 'Prepaid packages (e.g. a 10-coffee pass)', 'marketing'],
        'membership' => ['مستويات عضوية بخصم تلقائي', 'Membership tiers with an automatic discount', 'marketing'],
        'corporate_vouchers' => ['تعاقدات الشركات (فاوتشرات مدفوعة مقدماً لموظفيها)', 'Corporate contracts (prepaid vouchers for a company\'s staff)', 'marketing'],
        'knowledge_base' => ['الأسئلة والأجوبة (للفريق، وأسئلة شائعة للعملاء على المنيو)', 'Knowledge base (for the team, and an FAQ for guests on the menu)', 'marketing'],
        // management
        'daily_reports' => ['تقارير يومية', 'Daily reports', 'management'],
        'advanced_reports' => ['تقارير متقدمة', 'Advanced reports', 'management'],
        'inventory' => ['مخزون وتكلفة وصفات', 'Inventory & recipe costing', 'operations'],
        'staff_roles' => ['صلاحيات موظفين مخصصة', 'Custom staff permissions', 'management'],
        'audit_log' => ['سجل العمليات', 'Audit log', 'management'],
        'backups' => ['نسخة احتياطية من بيانات المطعم (أسبوعية وعند الطلب)', 'Restaurant data backups (weekly and on demand)', 'management'],
        // platform
        'custom_domain' => ['دومين خاص', 'Custom domain', 'platform'],
        'hide_badge' => ['إخفاء شارة eghub', 'Remove eghub badge', 'platform'],
        'priority_support' => ['أولوية في الدعم', 'Priority support', 'platform'],
        'featured_listing' => ['ظهور مميز في دليل المطاعم', 'Featured in the restaurant directory', 'platform'],
    ];

    /** key => [arabic label, english label] — value null = unlimited */
    public const LIMITS = [
        'branches' => ['الفروع', 'Branches'],
        'menu_items' => ['الأصناف', 'Menu items'],
        'staff' => ['المستخدمين', 'Staff accounts'],
        'tables' => ['الترابيزات', 'Tables'],
        'ai_imports_per_month' => ['تحويل صور المنيو شهرياً', 'AI menu imports / month'],
        // TV screens a restaurant may pair (App\Models\TvScreen); more are bought per screen (add-on `screens`)
        'screens' => ['شاشات العرض', 'TV screens'],
    ];

    public const GROUPS = [
        'orders' => ['الطلبات', 'Ordering'],
        'menu' => ['المنيو', 'Menu'],
        'marketing' => ['التسويق والعملاء', 'Marketing & guests'],
        'management' => ['الإدارة', 'Management'],
        'platform' => ['المنصة', 'Platform'],
        'operations' => ['التشغيل (في باقة Enterprise)', 'Operations (in the Enterprise plan)'],
    ];

    /** The operations features (POS, kitchen, kiosk, call center, inventory): only the Enterprise plan has them. */
    public static function addonFeatures(): array
    {
        return array_keys(array_filter(self::FEATURES, fn ($f) => $f[2] === 'operations'));
    }

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'monthly_price' => 'float',
            'original_price' => 'float',
            'cycle_discounts' => 'array',
            'is_public' => 'boolean',
            'is_featured' => 'boolean',
            'contact_sales' => 'boolean',
            'archived_at' => 'datetime',
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

    /**
     * The cycle discounts. The custom plan ("صمم باقتك") has none of its own: it always gives the Complete plan's, so
     * paying 6 or 12 months at once takes the same share off whichever plan the restaurant builds.
     */
    protected function cycleDiscounts(): Attribute
    {
        return Attribute::get(function (?string $value): array {
            if ($this->code === CustomPlan::CODE) {
                return CustomPlan::cycleDiscounts();
            }

            return $value ? (array) json_decode($value, true) : [];
        });
    }

    /** The plan a restaurant builds from modules (App\Billing\CustomPlan): priced by what it ticks, not by a monthly price. */
    public function isCustom(): bool
    {
        return $this->code === CustomPlan::CODE;
    }

    /** Sold by talking to sales (Enterprise): no public price, no self-subscribe, no dashboard invoice. */
    public function isContactSales(): bool
    {
        return (bool) $this->contact_sales;
    }

    /** Whether a restaurant may pick this plan and get an invoice for it from its own dashboard. */
    public function isSelfServe(): bool
    {
        return ! $this->isContactSales() && $this->is_public && ! $this->isArchived();
    }

    /**
     * Plans that can still be offered or assigned: not archived. Every place that offers a plan (pricing, signup,
     * billing, the module picker, the admin plan pickers) goes through this; App\Models\Subscription refuses to be
     * moved onto an archived plan.
     */
    public function scopeOffered(Builder $query): void
    {
        $query->whereNull('archived_at');
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /** The plans the platform falls back to (free after an unpaid period, the trial plan): never archived. */
    public function isEssential(): bool
    {
        return in_array($this->code, [Lifecycle::FREE_PLAN, Entitlements::FALLBACK_PLAN, TenantProvisioner::TRIAL_PLAN], true);
    }

    /**
     * What stops this plan from being archived: restaurants whose subscription (trial, active, past due) is on it,
     * and open invoices for it (paying one would put a restaurant on the plan). Archiving is refused while any is
     * left, so nobody's plan or price changes under them; they renew, switch or are moved by hand first.
     *
     * @return array{tenants: Collection<int, string>, open_invoices: int}
     */
    public function archiveBlockers(): array
    {
        return [
            'tenants' => Subscription::query()->where('plan_id', $this->id)->where('status', '!=', 'canceled')->orderBy('tenant_id')->pluck('tenant_id'),
            'open_invoices' => Invoice::query()->where('plan_id', $this->id)->where('status', 'open')->count(),
        ];
    }

    public function canBeArchived(): bool
    {
        $blockers = $this->archiveBlockers();

        return ! $this->isEssential() && $blockers['tenants']->isEmpty() && $blockers['open_invoices'] === 0;
    }

    /** Retires the plan: off the pricing page and out of every plan picker. Call canBeArchived() first. */
    public function archive(): void
    {
        $this->update(['archived_at' => now(), 'is_public' => false, 'is_featured' => false]);
    }

    /** Back in the admin plan pickers; it stays off the pricing page until the admin shows it there again. */
    public function unarchive(): void
    {
        $this->update(['archived_at' => null]);
    }

    /**
     * WhatsApp link to the sales team with a ready message about this plan (the sales number from the super admin
     * settings, else the support number); the services page when neither is set.
     */
    public function contactUrl(?string $restaurant = null): string
    {
        $number = Assistant::salesWhatsApp();
        if ($number === '') {
            return route('services').'#request';
        }
        $message = $restaurant
            ? __('Hello, I would like to know about the :plan plan for :restaurant.', ['plan' => $this->displayName(), 'restaurant' => $restaurant])
            : __('Hello, I would like to know about the :plan plan for my place.', ['plan' => $this->displayName()]);

        return 'https://wa.me/'.$number.'?text='.rawurlencode($message);
    }

    /**
     * Last day of the launch price (platform setting launch_offer_ends_at, set by EgWeb in admin › settings); null
     * when no date is set.
     */
    public static function launchOfferEndsAt(): ?CarbonInterface
    {
        $ends = trim((string) PlatformSetting::get(self::LAUNCH_OFFER_ENDS_KEY, ''));
        if ($ends === '') {
            return null;
        }
        try {
            return Carbon::parse($ends)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Whether the crossed-out regular price shows next to this plan's price: it must be higher, and the launch offer
     * must not be over (once launch_offer_ends_at has passed, it is hidden on its own).
     */
    public function showsLaunchDiscount(): bool
    {
        if ((float) $this->original_price <= (float) $this->monthly_price) {
            return false;
        }
        $ends = self::launchOfferEndsAt();

        return $ends === null || local_today() <= $ends->toDateString();
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
