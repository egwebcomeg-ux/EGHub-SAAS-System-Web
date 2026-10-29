// الحجوزات: تفعيل الحجز من لينك المنيو، الزبون بيحجز من موبايله، تأكيد الحجز، وحجز بالتليفون.
const tomorrow = new Date(Date.now() + 86400000).toISOString().slice(0, 10);
module.exports = {
    id: 'reservations', title: 'حجز الترابيزات', as: 'owner', start: 'app:/reservations',
    async run(t) {
        await t.say('صفحة الحجوزات: بتشوف حجوزات كل يوم، والجاي في الأسبوعين الجايين.', 3000);
        const settings = 'form[action$="/reservations/settings"]';
        await t.scroll(settings, 200);
        await t.click(`${settings} input[name=reservations_enabled]`, 'من «إعدادات الحجز» فعّل: العملاء يقدروا يحجزوا من لينك المنيو.', 1000);
        await t.point(`${settings} input[name=auto_confirm]`, 'وسيب «أكّد أوتوماتيك» مقفولة لو عايز تراجع كل حجز بنفسك.', 3000);
        await t.point(`${settings} input[name=max_party], ${settings} input[name=duration_minutes]`, 'وتحدد مدة الترابيزة وأكبر عدد وعدد الكراسي المتاحة للحجز.', 3000);
        await t.click(`${settings} button`, 'واحفظ.', 1400);
        await t.phone(`/demo-cafe/reserve?date=${tomorrow}&party=4`, 'دلوقتي الزبون بيحجز من لينك المنيو على موبايله.');
        const ph = (p) => t.inPhone(p);
        await t.point((p) => ph(p).locator('[data-pick]'), 'بيختار اليوم وعدد الأفراد...', 2400);
        await t.click((p) => ph(p).locator('label.m-slot:not(.pointer-events-none)').nth(6), '...والمعاد من المواعيد الفاضية بس.', 1000);
        await t.fill((p) => ph(p).locator('input[name=name]'), 'مريم سامي', 'يكتب اسمه...');
        await t.fill((p) => ph(p).locator('input[name=phone]'), '01022223333', '...وموبايله.');
        await t.fill((p) => ph(p).locator('textarea[name=notes]'), 'عيد ميلاد', 'وملاحظة لو حابب.');
        await t.click((p) => ph(p).getByRole('button', { name: 'احجز الترابيزة' }), 'ويدوس «احجز الترابيزة».', 2600);
        await t.go('app:/reservations', 'الحجز وصلك في الحجوزات: مستني تأكيدك.');
        const row = (p) => p.locator('article').filter({ hasText: 'مريم سامي' }).first();
        await t.point(row, 'الاسم وعدد الأفراد والمعاد والملاحظة.', 3000);
        await t.click((p) => row(p).getByRole('button', { name: 'تأكيد' }), 'دوس «تأكيد»: والزبون بيشوف إن حجزه اتأكد.', 1800);
        await t.point((p) => row(p), 'ويوم الحجز، لما يوصل دوس «وصل وقعد»، ولو ماجاش «ماجاش».', 3200);
        await t.go(`app:/reservations?date=${tomorrow}`, 'ولو حد حجز بالتليفون: افتح يوم الحجز...');
        await t.select('form[action$="/reservations"] select[name=time]', { index: 4 }, '...وضيفه من «حجز جديد»: المعاد وعدد الأفراد والموبايل والاسم.');
        await t.fill('form[action$="/reservations"] input[name=party_size]', 3, '');
        await t.fill('form[action$="/reservations"] input[name=phone]', '01033334444', '');
        await t.fill('form[action$="/reservations"] input[name=name]', 'خالد فؤاد', '');
        await t.click('form[action$="/reservations"] button', 'واحفظ.', 1800);
        await t.point((p) => p.locator('article').filter({ hasText: 'خالد فؤاد' }).first(), 'الحجز اللي بتضيفه بنفسك بيبقى متأكد على طول.', 3000);
    },
};
