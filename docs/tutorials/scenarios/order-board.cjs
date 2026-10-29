// لوحة الطلبات: الطلب الجديد، بيتحضر، جاهز، اتسلّم.
module.exports = {
    id: 'order-board', title: 'لوحة الطلبات', as: 'owner', start: 'app:/orders',
    async run(t) {
        await t.say('لوحة الطلبات بتجمع كل الطلبات: من الترابيزات والأونلاين والكاشير والكول سنتر.', 3200);
        await t.point('[data-section="new"]', 'الطلب الجديد بيظهر هنا مع جرس لحد ما حد يستلمه.');
        await t.point('[data-chips]', 'الفلاتر دي بتوريك المطبخ أو الجاهز أو نوع الطلب بس.');
        await t.click((p) => p.locator('[data-column="new"] [data-to="preparing"]').first(), 'دوس «بيتحضر» أول ما المطبخ يبدأ.');
        await t.click((p) => p.locator('[data-column="preparing"] [data-to="ready"]').first(), 'ولما يخلص دوس «جاهز»: العميل بيوصله إشعار على موبايله.');
        await t.click((p) => p.locator('[data-column="ready"] [data-to="served"]').first(), 'وبعد ما يستلمه دوس «اتسلّم».');
        await t.say('الإلغاء محتاج موافقة المدير، والطلب المدفوع بيترجع من الكاشير بس.', 3200);
    },
};
