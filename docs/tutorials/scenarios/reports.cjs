// التقارير: التبويبات، فترة جاهزة أو من تاريخ لتاريخ، الأرقام والرسوم، المتقدم، والتصدير.
const day = (n) => new Date(Date.now() - n * 86400000).toISOString().slice(0, 10);
module.exports = {
    id: 'reports', title: 'التقارير', as: 'owner', start: 'app:/reports',
    async run(t) {
        await t.say('التقارير: مبيعاتك وطلباتك وأكتر حاجة بتتباع، لأي فترة ولأي فرع.', 3200);
        await t.point('[data-area-tabs]', 'التبويبات: المبيعات، تقفيل اليوم (Z)، فاوتشرات الشركات، وتقييمات العملاء.', 3600);
        await t.click((p) => p.getByRole('link', { name: 'آخر 7 أيام' }), 'اختار فترة جاهزة: مثلاً آخر 7 أيام.', 1800);
        await t.fill('input[name=from]', day(30), 'أو اختار من تاريخ...');
        await t.fill('input[name=to]', day(0), '...لتاريخ.');
        await t.click((p) => p.getByRole('button', { name: 'تظهر' }), 'ودوس «تظهر».', 1800);
        await t.point((p) => p.locator('.stat-card').first().locator('xpath=..'), 'أهم الأرقام: المبيعات، عدد الطلبات، متوسط الطلب، واللي لسه متدفعش.', 3600);
        await t.scroll((p) => p.locator('h2', { hasText: 'ساعات الزحمة' }), 140);
        await t.point((p) => p.locator('h2', { hasText: 'ساعات الزحمة' }).locator('xpath=..'), 'ساعات الزحمة: تعرف تزود الشغالين إمتى.', 3000);
        await t.scroll((p) => p.locator('h2', { hasText: /الأكتر مبيع/ }), 140);
        await t.point((p) => p.locator('h2', { hasText: 'طرق الدفع' }).locator('xpath=..'), 'طرق الدفع، والطلبات جاية منين، ونوعها.', 3000);
        await t.point((p) => p.locator('h2', { hasText: /الأكتر مبيع/ }).locator('xpath=..'), 'والأصناف الأكثر مبيعاً بالكمية والمبلغ.', 3000);
        await t.click('[data-reports-advanced-link]', 'وفي «متقدم»: مقارنة بالفترة اللي قبلها، والطلبات حسب اليوم والساعة، والعملاء الجداد والراجعين.', 4000);
        await t.scroll('[data-reports-advanced-link]', 120, 900);
        await t.point((p) => p.getByRole('link', { name: /تصدير الطلبات/ }), 'وعايز الطلبات في Excel؟ دوس «تصدير الطلبات (CSV)».', 3400);
    },
};
