# EgMenu: تقرير إعادة الاستخدام (المرحلة 0)

> التاريخ: 2026-10-13 · الحالة: **في انتظار الموافقة. مفيش أي كود اتكتب.**
> كل فولدرات المصادر اتقرت بس، ومفيش فيها أي تعديل.

## المصادر اللي اتقرت

| المسار | الوصف |
|---|---|
| `C:\www\sushi\davaya-platform` | الـ SaaS الأساسي: Laravel 13، multi-tenant، باقات، دفع. **المصدر الرئيسي للهيكل.** |
| `C:\www\sushi\davaya` | نسخة صيدلية واحدة، PHP خام. مرجع للوظائف بس، ومش هنستخدم منها كود. |
| `C:\www\sushi\scratchcafe` | منيو QR لكافيه، PHP خام + SQLite. **أكمل مصدر لخصائص المنيو.** |
| `C:\www\sushi\desoky` | الأصل اللي scratchcafe اتعمل منه، ونفس الخصائص تقريباً. |
| `C:\www\sushi\kebap` | منيو QR فيه منيو مطبوع متصمم بالصور، وطلب واتساب فيه السعر والإجمالي. |

مسار الشغل الجديد: `C:\www\sushi\egmenu`

---

## 1. Davaya: الـ Stack

| البند | Davaya | قرار EgMenu |
|---|---|---|
| Backend | Laravel 13، PHP 8.3 | ✅ نفس الحاجة |
| قاعدة البيانات | MySQL 8 في الإنتاج، SQLite في التست | ✅ نفس الحاجة |
| Frontend | Blade + CSS مكتوب باليد، وTailwind 4 متسطّب بس مش مستخدم فعلياً | ⚠️ Blade + Tailwind 4 فعلي + Blade components |
| Multi-tenancy | `stancl/tenancy` v3، داتابيز واحدة + `tenant_id` | ✅ نفس الحاجة |
| الصلاحيات | `spatie/laravel-permission` بـ teams، والـ team هو الـ tenant | ✅ نفس الحاجة |
| Queue / Cron | database driver + `schedule:run` | ✅ نفس الحاجة |
| QR | `bacon/bacon-qr-code` | ✅ نفس الحاجة |
| Push | VAPID web push (`minishlink/web-push`) | ✅ تنبيه الطلبات للموظفين |
| الاستضافة | VPS (Nginx + PHP-FPM + MySQL + Redis + Supervisor)، والـ deploy بيعمل releases + symlink | ✅ نفس الحاجة |
| التست | PHPUnit 12، حوالي 223 تست في 31 ملف | ✅ نفس الأسلوب |

**السكربتات القديمة (scratchcafe/desoky/kebap):** PHP خام + SQLite لمطعم واحد، فكودها مش هيتنقل. هناخد منها **الخصائص والمنطق** ونعيد كتابتها جوه Laravel.

---

## 2. إيه اللي هيتنقل زي ما هو

| المكوّن | المصدر في Davaya | ملاحظة |
|---|---|---|
| Base model للعزل | `app/Models/Concerns/TenantModel.php` (BelongsToTenant) | global scope وتعبئة `tenant_id` تلقائياً |
| Tenant helpers | `app/Support/TenantCache.php` و`TenantFiles.php` و`TenantBackup.php` | كاش وملفات وباك أب لكل tenant لوحده |
| فحص صلاحيات الباقة | `app/Services/Entitlements.php` + `PlanFeature` (feature/limit) | `allows()` و`limit()` و`canAdd()`، وده بالظبط الـ feature flags اللي الخطة طالباها |
| ضبط الباقة لما تتغير | `app/Billing/PlanEnforcer.php` | بيخفي اللي زاد عن حد الباقة الجديدة من غير ما يمسح داتا |
| دورة الاشتراك | `app/Billing/Lifecycle.php` + `Billing.php` | trial ← active ← past_due ← مهلة ← باقة مجانية، ومفيش داتا بتتمسح |
| الموديلات | `Plan`، `Subscription`، `Invoice`، `Payment`، `UsageCounter`، `PlatformCoupon`، `SubscriptionAddon` | الأسعار متخزنة في الداتابيز ✅ |
| التفعيل اليدوي | `AdminController::markPaid()` + `TenantsController::activate()` + إعدادات InstaPay | ✅ مطلوب في المرحلة 3 |
| بوابات الدفع | `app/Billing/Gateways/` (Paymob وKashier عن طريق `GatewayManager`) | جاهزة للربط بعدين ✅ |
| تجهيز tenant جديد | `app/Services/TenantProvisioner.php` + `SignupController` | بيعمل الـ tenant والمالك والتجربة والإعدادات |
| الرسائل | `app/Services/Messaging/` + `WhatsApp.php` | لطلبات واتساب في باقة Pro |
| حماية التست | `tests/TenantTestCase.php` + `TenantIsolationTest.php` | **أساس اختبار عزل البيانات** |
| أيقونات | `app/Support/icons.php` (SVG sprite) | |
| Deploy | `deploy.sh` + `docs/DEPLOYMENT.md` | |

---

## 3. إيه اللي محتاج تعديل

### 3.1 الرابط: `egmenu.app/{slug}` بدل الـ subdomain
Davaya بيعرف الـ tenant من الـ **host** بس (`IdentifyTenant::resolve()`)، وفيه روابط كتير مكتوبة ثابتة زي `/dashboard?p=` و`/api/store` و`/media/...`.

**الاقتراح:**
- **المنيو العام:** `egmenu.app/{slug}` و`egmenu.app/{slug}/t/{table}`. الـ tenant يتعرف من أول جزء في الرابط، وفيه قائمة slugs محجوزة.
- **الداشبورد:** `app.egmenu.app`. الـ tenant يتعرف من **المستخدم اللي عامل لوجين** مش من الرابط، فبنتجنب مشكلة الـ sessions المشتركة على دومين واحد.
- **دومين خاص (Business):** نسيب الـ host resolver بتاع Davaya شغال كخيار تاني.
- كل الروابط تتولد بـ `route()`، ومفيش روابط مكتوبة ثابتة.

### 3.2 الداشبورد
- Davaya بيستخدم `Dashboard/Router.php` (`?p=&a=`)، والصفحات `.php` خام، والـ actions بترمي exceptions. ده كان معمول عشان ينقل الكود القديم زي ما هو 1:1.
- **في EgMenu:** routes متسمّية، وcontrollers عادية، وBlade components، وTailwind.
- **مش هننقل** الـ Router ولا الصفحات الخام.

### 3.3 الطلبات
- Davaya عنده `Order` و`OrderItem` و`OrderStatusHistory`، وstate machine بـ `TRANSITIONS` و`canMoveTo()`، و`OrderService`.
- **هناخد النمط ده** ونعمل حالات جديدة: `new ← preparing ← ready ← served` + `cancelled`، ونربط الطلب بـ `table_id` و`branch_id`.
- حالات الصيدلية (روشتة، توصيل، بدائل) هتتشال.

### 3.4 التحديث اللحظي (لوحة الطلبات وشاشة المطبخ)
- Davaya بيعمل **polling كل 12 ثانية**، ومعاه صوت وweb push. مفيش WebSockets.
- **اقتراح الـ MVP:** polling كل 4–5 ثواني، وصوت، وweb push. من غير ما نضيف أي dependency.
- **بعدين:** Laravel Reverb (WebSockets) لشاشة المطبخ والـ POS. ⚠️ ده dependency كبيرة، فمحتاج موافقة.

### 3.5 الأدوار
- Davaya فيه owner وpharmacist وorder_handler وaccountant (`app/Support/Permissions.php`).
- **EgMenu:** Owner وManager وCashier وKitchen وWaiter. نفس الآلية، بصلاحيات جديدة، ونقدر نحدد الدور على فرع معين.

### 3.6 المخزون والولاء والتقارير (باقة Business)
Davaya عنده التلاتة:
- **المخزون:** `InventoryController` و`BranchStockOverride` و`InventoryAlerts`، مع استيراد من Excel.
- **الولاء:** `Loyalty.php` و`LoyaltyTransaction` (اكتساب واسترداد وإلغاء).
- **التقارير:** `ReportsController` مع تصدير CSV.

**التعديل المطلوب:** مخزون المطعم هو **خامات وتكلفة وصفات (recipes)**، مش منتجات جاهزة زي الصيدلية. يعني موديل جديد `Ingredient` و`RecipeLine` يخصم من المخزون لما الطلب يتسلّم. الولاء والتقارير هننقل منطقهم مع تعديل بسيط.

### 3.7 الدفع اليدوي
InstaPay موجود. **فودافون كاش مش موجود**، وهنضيفه كطريقة يدوية تانية (رقم + تعليمات + رقم مرجعي)، وده تعديل بسيط.

### 3.8 الـ Design system
- `public/css/tokens.css` و`docs/UI-GUIDELINES.md` فيهم: سلم الخطوط من 12 لـ 36، شبكة 4px، targets لا تقل عن 44px، وخطوط Almarai وCairo وInter.
- **هننقل القواعد** ونحولها لـ `@theme` في Tailwind. ألوان EgMenu: برتقالي + كحلي.
- نستخدم **logical properties** (`ms-`/`me-`)، لأن CSS بتاع Davaya لسه فيه left/right ثابتة.

---

## 4. خصائص المنيو اللي هتتنقل من scratchcafe / desoky / kebap (إعادة كتابة)

| الخاصية | المصدر | الباقة المقترحة |
|---|---|---|
| أقسام وأصناف + ترتيب بالسحب + صور WebP تلقائي | scratchcafe | Basic |
| أحجام (اختيار واحد) وإضافات (أكتر من اختيار) | scratchcafe (`item_options`) | Basic |
| عربي/إنجليزي + RTL، وعملة بالعربي والإنجليزي | scratchcafe | Basic |
| خلص / مخفي / Badges (المطعم يعرّفها بنفسه) | scratchcafe | Basic |
| بحث بالعربي والإنجليزي | scratchcafe | Basic |
| ألوان المطعم وخلفيته ولوجو وغلاف | scratchcafe | Basic |
| QR لكل ترابيزة + كروت للطباعة | scratchcafe + kebap (لون ولوجو وPNG) | Basic |
| فروع: سعر وتوفر و"خلص" لكل فرع (NULL = يرث) | scratchcafe (`branch_items`) | Business |
| ساعات عمل الفرع ولينك الخريطة وأقرب فرع | kebap + scratchcafe | Business |
| تغيير الأسعار بالجملة بنسبة % | scratchcafe | Pro |
| عروض بالوقت (خصم %، أيام وساعات، وتعدي نص الليل) | scratchcafe (`time_offers`) | Pro |
| Popups مجدولة | scratchcafe | Pro |
| "بيتاكل مع" + كومبو وحساب التوفير | scratchcafe | Pro |
| هدف السلة ("اطلب بـ X واكسب Y") | scratchcafe | Pro |
| تقسيم الفاتورة (خدمة وضريبة) | scratchcafe | Pro |
| تقييم + لينك Google review | scratchcafe | Pro |
| إحصائيات من غير بيانات شخصية | scratchcafe (`stats`) | Pro |
| طلب واتساب (صالة/تيك أواي/توصيل) | kebap + scratchcafe | Pro |
| منيو مطبوع: Auto-fit نصي | scratchcafe (`menu_print.php`) | Pro |
| منيو مطبوع متصمم بالصور + تحكم في الخط ومعاينة | kebap (`admin/print.php`) | Pro |
| شاشات TV + وضع Live | scratchcafe (`menu_screens.php`) | Business |
| سجل العمليات (Audit log) | scratchcafe | Business |

**إضافات مش موجودة في أي مشروع:**
- **الطلب من الترابيزة بجد**: يتسجل في الداتابيز ويظهر على لوحة الطلبات. ده جوهر الخطة.
- POS للكاشير.
- شاشة المطبخ (KDS).
- اختيار الخط.
- قوالب تصميم للمنيو.
- PWA.
- Allergens.

---

## 5. إيه اللي مش مناسب لـ EgMenu (مش هيتنقل)

- **كل حاجة خاصة بالصيدلية (حوالي 100 ملف):** `DrugCatalog` و`CustomerPrescription` و`Consultation*` و`Doctor` و`Insurer` و`Reminder` و`FamilyMember` و`MedicineIndex`/`MedicineSearch` و`EdaFile`، وكنترولرز التأمين والأطباء، وحالة الروشتة.
- **تطبيقات Flutter** (`mobile/patient` و`mobile/staff`). ممكن نرجع للـ staff wrapper بعدين.
- **تكاملات Odoo وBosta وStock API.**
- **تطبيق السائق/المندوب** (`Driver/*`). ممكن نرجعله لو ضفنا توصيل في Business.
- `Dashboard/Router.php` والصفحات الخام (التفاصيل في 3.2).
- `landing/home.blade.php` (713 سطر لصيدلية). هنعمل لاندنج جديدة.
- كود scratchcafe/desoky/kebap نفسه، بما فيه الـ SQLite والـ `admin.php` الكبير. هنعيد كتابته.

---

## 6. المخاطر والديون التقنية

| # | الخطر | الأثر | العلاج |
|---|---|---|---|
| 1 | **عزل البيانات في الرابط المبني على المسار** | لو اتعمل غلط، مطعم ممكن يشوف داتا مطعم تاني | `TenantModel` إجباري لكل موديل، وتست عزل لكل موديل جديد، والداشبورد على دومين منفصل |
| 2 | نسخ Davaya كله ومسح الصيدلية | بواقي كود ميت ومصطلحات صيدلية | **مشروع Laravel 13 نضيف** وننقل الموديولات المختارة بس (القسم 2) |
| 3 | الـ polling تحت ضغط | استهلاك سيرفر في مطاعم كتير ساعة الذروة | endpoint خفيف بـ `max_id` + كاش، وReverb بعدين |
| 4 | `OrderService` كبير (522 سطر) ومرتبط بالصيدلية | صعب نعيد استخدامه زي ما هو | ننقل النمط (state machine + history) مش الملف |
| 5 | الـ PDF من المتصفح بس (`window.print`) في المشاريع القديمة | مفيش ملف PDF تنزّله من السيرفر | نبدأ بـ print CSS زي ما هو، وBrowsershot/Chrome headless بعدين (dependency، محتاج موافقة) |
| 6 | الـ badges والخط والـ intro كانوا ثابتين لكل كافيه | | كلهم يبقوا إعدادات لكل tenant |
| 7 | فولدر egmenu مش git repo | | `git init` أول خطوة في المرحلة 1 |
| 8 | تعارض الأسعار | فيه اقتراحين (199/399/799 و149/399/799) | **هنمشي على الخطة: 149 / 399 / 799** ومتخزنة في الداتابيز |

---

## 7. خطة المرحلة 1 المقترحة (بعد الموافقة)

1. `git init` + مشروع Laravel 13 نضيف + `.env.example`.
2. ننقل: `TenantModel` وstancl config وspatie teams و`Entitlements` وموديلات الباقات و`TenantProvisioner` و`TenantTestCase`.
3. نعمل resolver للمسار `egmenu.app/{slug}` + قائمة slugs محجوزة + دومين الداشبورد `app.egmenu.app`.
4. الأدوار الخمسة + Super Admin منفصل (`PlatformAdmin`).
5. الموديلات المبدئية: Tenant وBranch وUser وCategory وMenuItem وItemOption وTable وOrder وOrderItem وPlan وSubscription وInvoice.
6. Tailwind 4 + tokens + RTL + i18n (ar/en).
7. تستات: عزل الـ tenants، وresolver المسار، وصلاحيات الأدوار.

---

## 8. أسئلة محتاجة قرارك

1. **مشروع نضيف + ننقل الموديولات المختارة** (اقتراحي)، ولا ننسخ Davaya كله ونمسح منه؟
2. **الداشبورد على `app.egmenu.app`** والمنيو على `egmenu.app/{slug}`، موافق؟
3. **التحديث اللحظي:** polling في الـ MVP وReverb بعدين، موافق؟
4. **فودافون كاش:** نضيفه كطريقة دفع يدوية جنب InstaPay؟
5. **توزيع الخصائص على الباقات** في القسم 4: موافق، ولا فيه حاجة عايز تنقلها لباقة تانية؟
6. **السيرفر والدومين:** `egmenu.app` محجوز؟ وهنستخدم نفس الـ VPS بتاع Davaya؟
