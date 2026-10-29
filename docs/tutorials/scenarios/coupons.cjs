// الكوبونات: كوبون خصم عام بنسبة وحد أقصى وأقل طلب وتاريخ انتهاء، ومتابعة استخدامه.
const ends = new Date(Date.now() + 30 * 86400000).toISOString().slice(0, 10);
module.exports = {
    id: 'coupons', title: 'كود خصم', subtitle: 'الكوبونات والفاوتشرات', as: 'owner', start: 'app:/coupons',
    async run(t) {
        await t.say('الكوبونات والفاوتشرات: كل أكواد الخصم في مكان واحد، وقد إيه جابت طلبات ومبيعات.', 3400);
        const form = '[data-voucher-form] form';
        await t.click('[data-voucher-form] summary', 'نعمل كود خصم لكل الناس: دوس «كوبون عام جديد».', 1000);
        await t.fill(`${form} input[name=code]`, 'SUMMER20', 'اكتب الكود (أو سيبه فاضي والسيستم يعمله).');
        await t.fill(`${form} input[name=max_uses]`, 100, 'وعدد مرات الاستخدام كلها: مثلاً أول 100 بس.');
        await t.click((p) => p.locator(`${form} label`).filter({ hasText: '%' }).first(), 'نوع الخصم: نسبة.', 700);
        await t.fill(`${form} input[name=value]`, 20, 'القيمة: 20%...');
        await t.fill(`${form} input[name=max_discount]`, 50, '...بحد أقصى 50 جنيه للطلب.');
        await t.fill(`${form} input[name=min_order]`, 150, 'وأقل طلب يشتغل عليه.');
        await t.fill(`${form} input[name=expires_at]`, ends, 'ولحد إمتى.');
        await t.point(`${form} input[name=once_per_customer]`, 'و«مرة واحدة لكل عميل»: بيطلب موبايل العميل عشان ميتكررش.', 3000);
        await t.click(`${form} > button`, 'ودوس «اعمل الكوبون».', 1800);
        await t.point('[data-voucher="SUMMER20"]', 'الكود جاهز: العميل يكتبه في السلة أو الكاشير يدخّله، وهنا بتشوف استخداماته ومبيعاته.', 4200);
        await t.point((p) => p.locator('[data-voucher="SUMMER20"]').getByRole('button', { name: 'إيقاف' }), 'وتقدر توقفه في أي وقت.', 2600);
        await t.point('[data-give-form]', 'ولو عايز تدي عميل معين فاوتشر خاص بيه: من «ادّي فاوتشر لعميل».', 3200);
    },
};
