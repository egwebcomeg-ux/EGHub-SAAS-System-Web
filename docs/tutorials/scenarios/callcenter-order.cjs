// الكول سنتر: البحث برقم المتصل، منطقة التوصيل والعنوان، الأصناف، وتسجيل الطلب.
module.exports = {
    id: 'callcenter-order', title: 'طلب من الكول سنتر', as: 'owner', start: 'app:/call-center',
    async run(t) {
        await t.say('شاشة الكول سنتر: العميل بيتصل وإنت بتسجّل طلبه الدليفري في أقل من دقيقة.', 3200);
        await t.fill('[data-customer-phone]', '01000000001', 'أول حاجة اكتب رقم المتصل...');
        await t.click('[data-lookup]', '...ودوس «بحث».', 1600);
        await t.point('[data-caller]', 'عميل قديم: اسمه وعدد طلباته وآخر طلب وكارت الولاء ظاهرين قدامك.', 3600);
        await t.click('[data-type="delivery"]', 'اختار «توصيل».', 1000);
        await t.select('[data-zone-root]', 'مدينة نصر', 'اختار المنطقة...');
        await t.select('[data-zone-sub]', { index: 1 }, '...والشارع.');
        await t.point('[data-zone-facts]', 'رسوم التوصيل وأقل طلب والوقت المتوقع بيظهروا لوحدهم.', 3000);
        await t.fill('[data-address]', 'عمارة 12، الحي السابع، الدور 3، شقة 5', 'واكتب العنوان بالتفصيل: بيتحفظ للمرة الجاية.');
        await t.click((p) => p.locator('[data-items] button').filter({ hasText: 'لاتيه' }).first(), 'دلوقتي الأصناف: دوس على الصنف.', 1000);
        await t.click((p) => p.locator('[data-options-dialog] label, [data-options-dialog] button').filter({ hasText: 'كبير' }).first(), 'اختار الحجم...', 600);
        await t.click('[data-options-add]', '...وضيف.', 900);
        await t.click((p) => p.locator('[data-items] button').filter({ hasText: 'تشيز كيك' }).first(), 'وضيف الباقي بنفس الطريقة.', 700);
        await t.click((p) => p.locator('[data-items] button').filter({ hasText: 'براونيز' }).first(), '', 900);
        await t.fill('[data-cc-order] [data-notes]', 'يتصل قبل ما يوصل', 'ملاحظة للطيار أو المطبخ لو فيه.');
        await t.point('[data-cc-footer] [data-totals]', 'الإجمالي شامل رسوم التوصيل.', 2800);
        await t.click('[data-cc-footer] [data-send]', 'ودوس «تسجيل الطلب».', 2600);
        await t.go('app:/orders', 'الطلب نزل على لوحة الطلبات وراح للمطبخ على طول.');
        await t.scroll('[data-chips]', 90);
        await t.click('[data-chip="delivery"]', 'وتلاقيه في فلتر «توصيل» باسم العميل وعنوانه.', 3400);
    },
};
