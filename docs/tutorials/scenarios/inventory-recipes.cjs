// المخزون والوصفات: خامة، تعبئة وتغليف، وصفة صنف بكوباية تيك أواي بس، وتكلفة الأكل (Food cost).
module.exports = {
    id: 'inventory-recipes', title: 'المخزون وتكلفة الوصفات', as: 'owner', start: 'app:/inventory',
    async run(t) {
        await t.say('المخزون: كل طلب بيخصم مكونات وصفته من المخزون لوحده، وتعرف تكلفة كل صنف.', 3200);
        const add = 'form[action$="/inventory/ingredients"]';
        await t.fill(`${add} input[name=name]`, 'بن إسبريسو', 'نضيف خامة: الاسم...');
        await t.select(`${add} select[name=unit]`, 'g', '...والوحدة: جرام.');
        await t.fill(`${add} input[name=cost_per_unit]`, 0.8, 'تكلفة الجرام (بتتحدث لوحدها من فواتير الشراء)...');
        await t.fill(`${add} input[name=min_stock]`, 1000, '...ونبّهني لو قلت عن كيلو.');
        await t.click(`${add} button`, 'ودوس «إضافة خامة».', 1400);
        await t.fill(`${add} input[name=name]`, 'كوباية تيك أواي 12 أونص', 'ودلوقتي الكوباية...');
        await t.select(`${add} select[name=kind]`, 'packaging', '...نوعها «تعبئة وتغليف»...');
        await t.select(`${add} select[name=unit]`, 'pcs', '...بالقطعة...', 500);
        await t.fill(`${add} input[name=cost_per_unit]`, 3, '...بـ 3 جنيه.');
        await t.click(`${add} button`, 'وضيفها.', 1400);
        await t.point('table', 'الخامات والتغليف في جدول واحد بالموجود والتكلفة.', 2600);
        await t.click((p) => p.getByRole('link', { name: 'الوصفات والتكلفة' }), 'نروح «الوصفات والتكلفة».', 1400);
        await t.click((p) => p.locator('tr').filter({ hasText: 'لاتيه' }).first().getByRole('link'), 'ونعمل وصفة اللاتيه.', 1400);
        await t.select('select[name="lines[0][ingredient_id]"]', { label: 'بن إسبريسو (جرام)' }, 'الحصة الواحدة: 18 جرام بن...', 400);
        await t.fill('input[name="lines[0][quantity]"]', 18, '', 400);
        await t.select('select[name="lines[1][ingredient_id]"]', { label: 'لبن (ملّي)' }, '...و200 ملّي لبن...', 400);
        await t.fill('input[name="lines[1][quantity]"]', 200, '', 400);
        await t.select('select[name="lines[2][ingredient_id]"]', { label: 'كوباية تيك أواي 12 أونص (قطعة)' }, '...وكوباية.', 400);
        await t.fill('input[name="lines[2][quantity]"]', 1, '', 400);
        await t.click('input[name="lines[2][takeaway_only]"]', 'علّم الكوباية «تيك أواي وتوصيل بس»: طلب الصالة مش بياخد كوباية.', 1200);
        await t.point('select[name="lines[0][option_id]"]', 'ولو سطر بيتخصم مع اختيار بس (زي «كبير») اختاره من هنا.', 2600);
        await t.click((p) => p.getByRole('button', { name: 'حفظ الوصفة' }), 'واحفظ الوصفة.', 1600);
        await t.point('form .flex.flex-wrap.gap-4', 'السعر، وتكلفة المكونات، ونسبة التكلفة، والتكلفة مع تغليف التيك أواي.', 3600);
        await t.click((p) => p.getByRole('link', { name: 'رجوع' }), 'نرجع لكل الوصفات.', 1200);
        await t.point((p) => p.locator('tr').filter({ hasText: 'لاتيه' }).first(), 'كل صنف: سعره وتكلفته ونسبة التكلفة ومكسبك في الحصة.', 3200);
        await t.point('[data-food-cost-target]', 'وحدد النسبة المستهدفة: أي صنف فوقها بيتعلّم عشان تراجع سعره أو وصفته.', 3400);
    },
};
