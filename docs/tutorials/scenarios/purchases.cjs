// المشتريات: مورد جديد، فاتورة شراء بأسطرها، الترحيل للمخزن، وكشف حساب المورد.
module.exports = {
    id: 'purchases', title: 'الموردين والمشتريات', as: 'owner', start: 'app:/inventory/suppliers',
    async run(t) {
        await t.say('البضاعة اللي بتشتريها بتدخل المخزن من فاتورة شراء، ومعاها حساب كل مورد.', 3200);
        const add = 'form[action$="/inventory/suppliers"]';
        await t.fill(`${add} input[name=name]`, 'شركة البن المصري', 'نضيف مورد: الاسم...');
        await t.fill(`${add} input[name=phone]`, '0225551234', '...والتليفون...');
        await t.fill(`${add} textarea[name=notes]`, 'بن ولبن وكوبايات - الدفع آخر الشهر', '...وملاحظة: بيبيع إيه وبيتحاسب إزاي.');
        await t.click(`${add} button`, 'ودوس «إضافة مورّد».', 1400);
        await t.click((p) => p.locator('tr').filter({ hasText: 'شركة البن المصري' }).getByRole('link', { name: 'فاتورة شراء جديدة' }), 'جت البضاعة؟ دوس «فاتورة شراء جديدة» قدام المورد.', 1600);
        await t.fill('input[name=reference]', 'INV-5521', 'اكتب رقم فاتورة المورد.');
        const line = async (n, label, qty, cost, text) => {
            await t.select(`select[name="lines[${n}][ingredient_id]"]`, { label }, text, 300);
            await t.fill(`input[name="lines[${n}][quantity]"]`, qty, '', 300);
            await t.fill(`input[name="lines[${n}][unit_cost]"]`, cost, '', 500);
        };
        await line(0, 'بن إسبريسو (جرام)', 5000, 0.75, 'وكل صنف: الكمية وسعر الوحدة. 5 كيلو بن...');
        await line(1, 'لبن (ملّي)', 20000, 0.035, '...و20 لتر لبن...');
        await line(2, 'كوباية تيك أواي 12 أونص (قطعة)', 300, 2.8, '...و300 كوباية.');
        await t.point('[data-purchase-post]', 'ممكن تحفظها مسودة وتكملها بعدين...', 2400);
        await t.click('[data-purchase-post]', '...أو «حفظ وتسجيل في المخزن»: البضاعة بتتضاف للمخزون.', 2400);
        await t.point('table', 'الفاتورة اتسجلت بإجماليها، ومتوسط تكلفة كل خامة اتحدّث لوحده.', 3600);
        await t.click((p) => p.getByRole('link', { name: 'شركة البن المصري' }).first(), 'ومن اسم المورد تفتح كشف حسابه.', 1800);
        await t.point('[data-supplier-total]', 'كل اللي اشتريته منه في الفترة اللي تختارها، فاتورة فاتورة.', 3200);
        await t.click((p) => p.getByRole('link', { name: 'المخزون', exact: true }).last(), 'وفي المخزون: الكميات زادت.', 1400);
        await t.point('table', 'البن واللبن والكوبايات موجودين دلوقتي بكمياتهم وتكلفتهم.', 3200);
    },
};
