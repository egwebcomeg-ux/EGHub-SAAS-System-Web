// كشك الطلب: إضافة كشك (تابلت عند الكاونتر)، الزبون بيطلب بنفسه، والطلب بيوصل لوحة الطلبات برقمه.
const tinker = require('../tinker.cjs');

module.exports = {
    id: 'kiosk', title: 'كشك الطلب الذاتي', as: 'owner', start: 'app:/devices?tab=kiosk',
    // one kiosk on the page, even when the video is recorded again
    prepare: () => tinker('App\\Models\\Kiosk::query()->delete(); echo "kiosks ok";'),
    voice: {
        'كشك الطلب الذاتي': 'أهلاً بيك في إي جي هَب. في الفيديو ده هنشوف إزاي تحط تابلت عند الكاونتر، والعميل يطلب ويدفع بنفسه.',
        'من «الشاشات والأجهزة» ← «كشك الطلب»: سمّي الكشك.': 'من الشاشات والأجهزة، تبويب كشك الطلب. اكتب اسم الكشك.',
        'اختار: أكل هنا، تيك أواي، أو الاتنين.': 'واختار أنواع الطلب: أكل هنا، أو تيك أواي، أو الاتنين.',
        'ولو حد ساب الطلب، الكشك بيرجع للأول لوحده بعد الثواني دي.': 'ولو حد ساب الطلب في النص، الكشك بيرجع لأول شاشة لوحده بعد الثواني دي.',
        'دوس «إضافة كشك».': 'اضغط إضافة كشك.',
        'افتح لينك الكشك على التابلت، أو امسح الـ QR بتاعه.': 'افتح لينك الكشك على التابلت، أو امسح الكيو آر بتاعه.',
        'الزبون بيدوس «ابدأ الطلب»...': 'العميل بيضغط ابدأ الطلب.',
        '...ويختار الصنف واختياراته...': 'ويختار الصنف واختياراته.',
        '...ويفتح السلة ويكمّل.': 'وبعدين يفتح السلة ويكمّل.',
        'أكل هنا ولا تيك أواي، واسمه عشان تناديه.': 'يختار أكل هنا ولا تيك أواي، ويكتب اسمه علشان تناديه.',
        'يدوس «أكّد طلبي»: وياخد رقم طلبه على الشاشة.': 'يضغط أكّد طلبي، وياخد رقم طلبه على الشاشة.',
        'والطلب وصل لوحة الطلبات والمطبخ على طول.': 'والطلب وصل لوحة الطلبات والمطبخ على طول.',
        'خلصنا ✅': 'بالسهولة دي الطابور يخلص أسرع، والكاشير يرتاح. ابدأ مجاناً من إي جي هَب دوت آب.',
    },
    async run(t) {
        const form = 'form[action$="/kiosks"]';
        await t.fill(`${form} input[name=name]`, 'تابلت الكاونتر', 'من «الشاشات والأجهزة» ← «كشك الطلب»: سمّي الكشك.');
        await t.point(`${form} input[name="order_types[]"]`, 'اختار: أكل هنا، تيك أواي، أو الاتنين.', 2600);
        await t.point(`${form} input[name=idle_seconds]`, 'ولو حد ساب الطلب، الكشك بيرجع للأول لوحده بعد الثواني دي.', 2800);
        await t.click(`${form} button`, 'دوس «إضافة كشك».', 1800);
        const open = (p) => p.getByRole('link', { name: /افتح الكشك/ }).first();
        await t.point(open, 'افتح لينك الكشك على التابلت، أو امسح الـ QR بتاعه.', 2800);
        await t.go(await open(t.page).getAttribute('href'));
        // the start button pulses for ever, so it never holds still long enough to be tapped
        await t.page.addStyleTag({ content: '.k-pulse { animation: none !important; }' });
        await t.click((p) => p.getByRole('button', { name: 'ابدأ الطلب' }), 'الزبون بيدوس «ابدأ الطلب»...', 1600);
        await t.click('[data-items] button[data-item]:not([disabled])', '...ويختار الصنف واختياراته...', 1600);
        await t.click('[data-item-add]', '', 1400);
        await t.click('[data-open-cart]', '...ويفتح السلة ويكمّل.', 1400);
        await t.click('[data-checkout]', '', 1400);
        // with one order type the kiosk does not ask which
        if (await t.page.locator('[data-checkout-dialog] [data-type]').first().isVisible()) {
            await t.click('[data-checkout-dialog] [data-type]', '', 800);
        }
        await t.fill('[data-checkout-dialog] [data-name]', 'يوسف', 'أكل هنا ولا تيك أواي، واسمه عشان تناديه.');
        await t.click('[data-place]', 'يدوس «أكّد طلبي»: وياخد رقم طلبه على الشاشة.', 3200);
        await t.go('app:/orders', 'والطلب وصل لوحة الطلبات والمطبخ على طول.');
        await t.point((p) => p.getByText('يوسف').last(), 'والطلب وصل لوحة الطلبات والمطبخ على طول.', 3000);
    },
};
