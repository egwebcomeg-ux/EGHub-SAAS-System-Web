// إعدادات الطلبات: الطلب من الترابيزة، سير الطلبات، الطلب الأونلاين، وتأكيد رقم العميل.
module.exports = {
    id: 'order-flow-settings', title: 'إعداد سير الطلبات', as: 'owner', start: 'app:/settings?tab=orders',
    async run(t) {
        await t.say('من الإعدادات ← الطلبات بتحدد الزباين بيطلبوا إزاي، والطلب بيمشي إزاي لحد ما يخلص.', 3400);
        await t.point('input[name=ordering_enabled]', 'ده المفتاح الرئيسي: الزباين يقدروا يطلبوا من المنيو.', 2800);
        await t.point((p) => p.locator('input[name=dine_in_mode]').first().locator('xpath=ancestor::div[contains(@class,"rounded-xl")][1]'), 'في الصالة: الضيف يبعت طلبه من الترابيزة، أو يعمل قايمة يوريها للويتر، أو يتفرج على المنيو بس.', 4200);
        await t.scroll('[data-order-flow]', 140);
        await t.point('[data-order-flow]', 'وهنا سير الطلبات حسب الشاشات اللي شغال بيها.', 3000);
        await t.select('select[name=order_flow]', 'auto', 'الأوتوماتيك هو المقترح: لو بتستخدم شاشة المطبخ الطلب بيروحلها، ولو لأ بيخلص أول ما يتدفع.');
        await t.fill('input[name=order_auto_serve_minutes]', 60, 'والطلب المدفوع اللي فاضل «جاهز» بيتقفل لوحده بعد الوقت ده.');
        await t.point('input[name=online_prep_minutes]', 'الطلب الأونلاين من لينك المنيو: تيك أواي أو توصيل، والوقت اللي بيجهز فيه.', 3200);
        await t.fill('input[name=online_auto_cancel_minutes]', 15, 'والطلب الأونلاين اللي محدش استلمه بيتلغي لوحده بعد الدقايق دي (0 = أبداً).', 1600);
        await t.point('[data-verify-phone]', 'وهنا تقدر تطلب تأكيد رقم العميل بكود على واتساب قبل طلبات الدليفري أو كل الطلبات الأونلاين.', 3600);
        await t.select('select[name=online_verify_phone]', 'delivery', 'مثلاً: طلبات الدليفري بس.');
        await t.click((p) => p.getByRole('button', { name: 'حفظ الإعدادات' }), 'واحفظ.', 1800);
    },
};
