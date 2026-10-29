// الجرد: جرد جديد، كتابة الكميات اللي اتعدّت، الفرق بالكمية والفلوس، والترحيل.
module.exports = {
    id: 'inventory-count', title: 'جرد المخزون', as: 'owner', start: 'app:/inventory/counts',
    async run(t) {
        await t.say('الجرد: بتعدّ اللي على الرف، والسيستم يقارنه باللي المفروض يكون موجود.', 3200);
        await t.point('[data-new-count] input[name=scope]', 'تجرد كل حاجة، أو الخامات بس، أو التغليف بس، أو حاجات تختارها.', 3000);
        await t.fill('[data-new-count] input[name=note]', 'جرد آخر الشهر', 'اكتب ملاحظة للجرد...');
        await t.click('[data-new-count] button', '...ودوس «ابدأ الجرد».', 1800);
        await t.point('[data-count-sheet] thead', 'قدامك كل صنف واللي المفروض موجود منه، وإنت تكتب اللي عدّيته.', 3400);
        const counted = (name) => (p) => p.locator('[data-count-sheet] tr').filter({ hasText: name }).first().locator('input');
        await t.fill(counted('بن إسبريسو'), 4800, 'البن: لقينا 4800 جرام...');
        await t.fill(counted('لبن'), 20000, '...اللبن مظبوط...');
        await t.fill(counted('كوباية'), 290, '...والكوبايات 290.');
        await t.click((p) => p.locator('[data-count-sheet] button.btn-primary'), 'دوس «حفظ كمسودة» تشوف الفرق (وتقدر تكمل بعدين).', 1800);
        await t.point((p) => p.locator('[data-count-sheet] tr').filter({ hasText: 'بن إسبريسو' }).first(), 'الفرق بالكمية وبالفلوس: ناقص 200 جرام بن.', 3200);
        await t.point('[data-count-shortage]', 'وإجمالي الناقص والزيادة بالجنيه.', 2800);
        await t.click('[data-count-post]', 'لما تخلص، المدير يدوس «حفظ وترحيل»: المخزون بيتظبط على اللي اتعدّ.', 2400);
        await t.point('[data-count-sheet]', 'الجرد اترحّل واتقفل، واتسجل مين رحّله وإمتى.', 3000);
        await t.click((p) => p.getByRole('link', { name: 'الفروقات' }), 'وفي «الفروقات» تقارن الاستهلاك المتوقع من المبيعات باللي اتصرف فعلاً.', 3200);
    },
};
