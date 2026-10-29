// بيع تيك أواي من الكاشير: فتح الوردية، الأصناف، الدفع كاش والباقي.
module.exports = {
    id: 'pos-sale', title: 'البيع من الكاشير', as: 'owner', start: 'app:/pos',
    async run(t) {
        await t.say('دي شاشة الكاشير: الأصناف على اليمين والفاتورة على الشمال.');
        await t.click((p) => p.getByText('فتح وردية').first(), 'قبل أول بيعة في اليوم افتح الوردية.');
        await t.fill('input[name=opening_cash]', 500, 'اكتب الفلوس اللي في الدرج وإنت بتبدأ.');
        await t.click((p) => p.locator('form').filter({ has: p.locator('input[name=opening_cash]') }).locator('button').first(), 'وافتح الوردية.');
        await t.go('app:/pos', 'نرجع لشاشة الكاشير.');
        await t.click((p) => p.locator('[data-items] button').filter({ hasText: 'لاتيه' }).first(), 'دوس على الصنف عشان يتضاف للفاتورة.');
        await t.click((p) => p.locator('[data-options-dialog] label, [data-options-dialog] button').filter({ hasText: 'كبير' }).first(), 'لو الصنف ليه اختيارات (زي الحجم) اختار منها.');
        await t.click('[data-options-add]', 'وضيف.');
        await t.click((p) => p.locator('[data-items] button').filter({ hasText: 'تشيز كيك' }).first(), 'ضيف أي أصناف تانية بنفس الطريقة.');
        await t.point('[data-totals]', 'الإجمالي بيتحسب لوحده.');
        await t.click('[data-pay]', 'اضغط «دفع».');
        await t.click((p) => p.locator('[data-method]').filter({ hasText: 'كاش' }).first(), 'اختار طريقة الدفع: كاش.');
        await t.fill('[data-tendered]', 300, 'اكتب المبلغ اللي العميل اداهولك، والسيستم يحسب الباقي.');
        await t.point('[data-change]', 'ده الباقي اللي ترجعه للعميل.');
        await t.click('[data-pay-confirm]', 'وأكد الدفع.');
        await t.say('البيعة اتسجلت في الوردية: تقدر تطبع الإيصال أو تبعته للعميل على واتساب.', 3500);
    },
};
