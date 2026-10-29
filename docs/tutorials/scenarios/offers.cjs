// العروض بالوقت: عرض «ساعة سعيدة» بخصم على قسم في ساعات وأيام معينة، وإزاي العرض بيظهر في المنيو.
module.exports = {
    id: 'offers', title: 'عرض بالوقت', subtitle: 'العروض والإعلانات', as: 'owner', start: 'app:/marketing',
    async run(t) {
        await t.say('العروض بالوقت: الأسعار بتنزل لوحدها في المنيو وعند الكاشير في المواعيد اللي تحددها.', 3400);
        const form = 'form[action$="/offers"]';
        await t.fill(`${form} input[name=title]`, 'ساعة سعيدة على المشروبات الباردة', 'نعمل عرض جديد: اكتب اسمه.', 600);
        await t.fill(`${form} input[name=discount_percent]`, 25, 'نسبة الخصم: 25%.');
        await t.select(`${form} select[name=category_id]`, { label: 'مشروبات باردة' }, 'على المنيو كله أو على قسم واحد: هنا المشروبات الباردة.');
        await t.fill(`${form} input[name=starts_at]`, '15:00', 'من الساعة 3 العصر...');
        await t.fill(`${form} input[name=ends_at]`, '18:00', '...لحد 6.');
        await t.click(`${form} input[name="days[]"][value="5"]`, 'والأيام: مثلاً شيل الجمعة.', 900);
        await t.point(`${form} input[name=ends_on]`, 'ولو العرض لفترة محددة، اختار يوم البداية والنهاية.', 2800);
        await t.click(`${form} button`, 'واحفظ العرض.', 1800);
        await t.point((p) => p.locator('.card').filter({ hasText: 'ساعة سعيدة على المشروبات الباردة' }).first(), 'العرض اتضاف: بيشتغل لوحده كل يوم من 3 لـ 6، وتقدر توقفه أو تمسحه.', 3800);
        await t.point((p) => p.locator('.card').filter({ hasText: 'خصم 10% على حلويات' }).first(), 'والعرض اللي شغال دلوقتي عليه علامة «شغال دلوقتي».', 3000);
        await t.phone('/demo-cafe', 'وفي المنيو العرض الشغال بيظهر لوحده.');
        await t.point((p) => t.inPhone(p).locator('[data-ticker]'), 'في شريط العروض فوق...', 2600);
        const cake = (p) => t.inPhone(p).locator('[data-category] [data-item]').filter({ hasText: 'تشيز كيك' }).last();
        await t.point(cake, '...وعلى الأصناف: السعر القديم مشطوب جنب السعر الجديد.', 3600);
    },
};
