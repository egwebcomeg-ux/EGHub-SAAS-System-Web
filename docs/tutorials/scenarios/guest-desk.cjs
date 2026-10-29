// مكتب العميل: البحث بالموبايل، ختم على كارت الولاء، وشحن رصيد المحفظة.
const { openShift } = require('../tinker.cjs');
module.exports = {
    id: 'guest-desk', title: 'مكتب العميل', subtitle: 'أختام الولاء ورصيد المحفظة', as: 'owner', start: 'app:/loyalty',
    prepare() {
        // taking money (a wallet top-up) needs an open cashier shift: open one for the owner if none is open
        openShift();
    },
    async run(t) {
        await t.say('مكتب العميل: كل حاجة تخص الضيف في مكان واحد، الأختام والرصيد والفاوتشرات.', 3200);
        await t.fill('#q', '01000000002', 'اكتب موبايل الضيف (أو امسح الـ QR اللي على كارته).');
        await t.click('[data-lookup] button', 'ودوس «بحث».', 1400);
        await t.point((p) => p.locator('section.card').filter({ has: p.locator('form[action*="/stamp"]') }).first(), 'ده الضيف: زياراته واللي صرفه وأختامه.', 3200);
        await t.fill('form[action*="/stamp"] input[name=note]', 'لاتيه', 'اشترى مشروب؟ اكتب ملاحظة لو حابب...');
        await t.click('form[action*="/stamp"] button', '...ودوس «ضيف ختم».', 1800);
        await t.point((p) => p.locator('.text-4xl').first(), 'الختم اتضاف: لما يكمّل الأختام بياخد المكافأة.', 3000);
        await t.point('[data-card-link]', 'وده لينك كارت الضيف: ابعتهوله واتساب يشوف أختامه ورصيده على موبايله.', 3400);
        await t.fill('#topup-amount', 200, 'شحن رصيد المحفظة: اكتب المبلغ...');
        await t.click((p) => p.locator('[data-wallet-form="topup"] label').filter({ hasText: 'كاش' }).first(), '...واختار هو دفع إزاي.', 900);
        await t.click('[data-wallet-form="topup"] button', 'ودوس «اشحن الرصيد» وأكّد إنك استلمت الفلوس.', 2600);
        await t.point('[data-wallet-result]', 'الرصيد الجديد ظهر، وتقدر تطبع إيصال الشحن.', 3000);
        await t.say('الضيف يدفع بالرصيد ده من الكاشير أو وهو بيطلب أونلاين.', 3000);
    },
};
