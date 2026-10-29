// طلب الزبون من الترابيزة: يمسح الـ QR، يختار، يبعت، والطلب يظهر في لوحة الطلبات.
const tinker = require('../tinker.cjs');
let token = '';
module.exports = {
    id: 'guest-qr-order', title: 'طلب الزبون من الترابيزة', subtitle: 'يمسح الـ QR ويطلب من موبايله', as: 'owner', start: 'app:/tables',
    prepare() {
        // the QR of table 3 in the demo restaurant
        token = tinker('echo "TOKEN=".App\\Models\\DiningTable::where("name","3")->value("qr_token");').match(/TOKEN=([a-f0-9]{20})/)[1];
    },
    async run(t) {
        const ph = (p) => t.inPhone(p);
        await t.say('كل ترابيزة ليها كارت QR خاص بيها. تعالى نشوف الزبون بيطلب إزاي.', 3000);
        await t.phone(`/demo-cafe/t/${token}`, 'الزبون بيمسح الكود بكاميرا موبايله والمنيو بيفتح على طول.');
        await t.point((p) => ph(p).locator('[data-table-chip]'), 'المنيو عارف هو على أنهي ترابيزة لوحده.', 2800);
        await t.click((p) => ph(p).locator('[data-category] [data-item]').filter({ hasText: 'لاتيه' }).first(), 'يدوس على الصنف يشوف تفاصيله.', 1200);
        await t.click((p) => ph(p).locator('[data-sheet-groups] label').filter({ hasText: 'كبير' }).first(), 'يختار الحجم...', 1000);
        await t.click((p) => ph(p).locator('[data-add-to-cart]'), '...ويضيفه للطلب.', 1200);
        await t.click((p) => ph(p).locator('[data-category] *:has(> [data-quick-add])').filter({ hasText: 'تشيز كيك' }).first().locator('[data-quick-add]'), 'أو يضيف بسرعة بعلامة «+».', 1200);
        await t.click((p) => ph(p).locator('[data-open-cart]'), 'وبعدين يفتح طلبه.', 1400);
        await t.point((p) => ph(p).locator('[data-bill]'), 'يشوف الإجمالي، والخصم لو فيه عرض.', 2600);
        await t.fill((p) => ph(p).locator('[data-customer-name]'), 'سارة', 'يكتب اسمه (اختياري).');
        await t.click((p) => ph(p).locator('[data-send-order]'), 'ويدوس «ابعت الطلب».', 2600);
        await t.say('الطلب وصل! والزبون بيتابع حالته من موبايله لحد ما يجهز.', 3000);
        await t.go('app:/orders', 'وفي نفس اللحظة الطلب بيظهر عندك في لوحة الطلبات بجرس.');
        await t.scroll('[data-chips]', 90);
        await t.point((p) => p.locator('[data-column="new"] > *').filter({ hasText: 'سارة' }).first(), 'ده طلب ترابيزة 3 باسم الزبون والأصناف والاختيارات.', 3200);
        await t.click((p) => p.locator('[data-column="new"] > *').filter({ hasText: 'سارة' }).first().locator('[data-to="preparing"]'), 'دوس «بيتحضر» والزبون يشوفها على موبايله.', 2000);
        await t.click((p) => p.getByRole('link', { name: /الترابيزات/ }).first(), 'ومن «الترابيزات» تشوف حساب كل ترابيزة مفتوحة.', 2200);
        await t.point('#tables-grid [data-session]', 'ترابيزة 3 بقت مشغولة، وكل طلباتها بتتجمع في حساب واحد.', 3000);
        await t.click('#tables-grid [data-session]', 'دوس عليها تشوف الفاتورة، ومنها تقبض وتقفل الترابيزة.', 3600);
    },
};
