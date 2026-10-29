// المنيو: قسم جديد، صنف بسعر واختيارات (حجم/إضافات)، وإخفاؤه لو خلص.
module.exports = {
    id: 'menu-items', title: 'إضافة أصناف المنيو', as: 'owner', start: 'app:/menu',
    async run(t) {
        await t.say('دي صفحة المنيو: الأقسام وجوّاها الأصناف بأسعارها.', 2800);
        await t.click((p) => p.locator('summary').filter({ hasText: 'إضافة قسم' }).first(), 'نبدأ بقسم جديد: دوس «إضافة قسم».');
        await t.fill('form[action$="/menu/categories"] input[name=name]', 'مخبوزات', 'اكتب اسم القسم بالعربي.');
        await t.fill('form[action$="/menu/categories"] input[name=name_en]', 'Bakery', 'وبالإنجليزي لو المنيو بلغتين.');
        await t.click('form[action$="/menu/categories"] button', 'واحفظ القسم.');
        const section = (p) => p.locator('[data-category-section]').filter({ has: p.locator('h2', { hasText: 'مخبوزات' }) });
        await t.click((p) => section(p).getByRole('link', { name: /صنف/ }), 'القسم اتضاف. دوس «+ صنف» عشان تضيف أول صنف فيه.');
        await t.fill('input[name=name]', 'كرواسون', 'اسم الصنف.');
        await t.fill('input[name=name_en]', 'Croissant', '');
        await t.fill('textarea[name=description]', 'كرواسون زبدة طازة من الفرن', 'ووصف قصير بيظهر للعميل.');
        await t.fill('input[name=price]', 45, 'السعر بالجنيه.');
        await t.click('[data-add-group]', 'عايز اختيارات؟ دوس «إضافة مجموعة».');
        await t.fill('[data-group] [data-name=name]', 'الحشو', 'اسم المجموعة: مثلاً «الحشو».');
        await t.click('[data-group] [data-name=is_required]', 'وخليها إجباري: العميل لازم يختار.');
        await t.fill('[data-group] [data-option] [data-name=name]', 'سادة', 'أول اختيار: سادة بدون زيادة.');
        await t.fill('[data-group] [data-option] [data-name=price]', 0, '');
        await t.click('[data-group] [data-add-option]', 'ضيف اختيار تاني.');
        await t.fill((p) => p.locator('[data-group] [data-option] [data-name=name]').nth(1), 'شوكولاتة', 'شوكولاتة...');
        await t.fill((p) => p.locator('[data-group] [data-option] [data-name=price]').nth(1), 15, '...بزيادة 15 جنيه على السعر.');
        await t.click('[data-primary-save]', 'واحفظ الصنف.');
        await t.point((p) => section(p).locator('[data-item-row]').filter({ hasText: 'كرواسون' }), 'الكرواسون نزل في المنيو على طول.', 3000);
        await t.say('ولو خلص في يوم، دوس «متاح» يتقفل من المنيو لحد ما ترجّعه.', 3400);
    },
};
