// صرف فاوتشر شركة: الكود، التحقق، اختيار الطلب المفتوح، الباقي كاش، والإيصال.
const tinker = require('../tinker.cjs');
let code = '';
module.exports = {
    id: 'redeem-voucher', title: 'صرف فاوتشر شركة', as: 'owner', start: 'app:/vouchers/redeem',
    prepare() {
        // an open shift (the rest of the bill is taken in cash) and an unused voucher of the demo company
        tinker.openShift();
        const out = tinker('echo "CODE=".App\\Models\\CorporateVoucher::whereHas("contract", fn ($q) => $q->where("name", "like", "%النيل%"))->where("balance", ">", 0)->orderBy("id")->value("code");');
        code = out.match(/CODE=([A-Z0-9]+)/)[1];
    },
    async run(t) {
        await t.say('صرف فاوتشرات الشركات: موظف الشركة جاي بالفاوتشر وعايز يدفع بيه.', 3200);
        await t.fill('[data-query]', `${code.slice(0, 5)}-${code.slice(5)}`, 'اكتب كود الفاوتشر (أو امسح الـ QR بالكاميرا، أو اكتب موبايل الموظف).');
        await t.click('[data-check]', 'ودوس «اعمل شيك».', 1800);
        await t.point('[data-results] li', 'الفاوتشر صالح واتضاف: الشركة والرصيد وطريقة الاستخدام والصلاحية.', 3600);
        await t.point('[data-orders]', 'هيدفع إيه؟ دي طلبات النهارده اللي لسه ما اتدفعتش.', 3000);
        await t.click((p) => p.locator('[data-orders] [data-order]').first(), 'اختار طلب الموظف.', 1600);
        await t.point('[data-summary]', 'الفاوتشر بيتخصم من الطلب، واللي فاضل بيبان هنا.', 3200);
        const cash = (p) => p.locator('[data-methods]:not(.hidden) [data-method="cash"]');
        if (await cash(t.page).count()) {
            await t.click(cash, 'الباقي هيدفعه إزاي؟ كاش مثلاً.', 1200);
        }
        await t.click('[data-submit]', 'ودوس «اصرف».', 2400);
        await t.point('[data-done-dialog] [data-done-total]', 'اتصرف! تقدر تطبع إيصال للموظف، والصرف بيتسجل في كشف حساب الشركة.', 3800);
    },
};
