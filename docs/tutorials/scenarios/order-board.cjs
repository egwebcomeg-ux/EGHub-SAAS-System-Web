// لوحة الطلبات: الطلب الجديد، الفلاتر، بيتحضر، جاهز، اتسلّم، ووضع «مشغولين».
module.exports = {
    id: 'order-board', title: 'لوحة الطلبات', as: 'owner', start: 'app:/orders',
    async run(t) {
        await t.say('لوحة الطلبات بتجمع كل الطلبات: من الترابيزات والأونلاين والكاشير والكول سنتر.', 3200);
        await t.point('[data-live-dot]', 'الصفحة مباشرة: الطلبات بتظهر لوحدها من غير ما تعمل تحديث.', 2800);
        await t.point('[data-busy-box]', 'لو المطبخ مزحوم، اختار «مشغولين» والطلب الأونلاين يقف لحد الوقت اللي تحدده.', 3600);
        await t.scroll('[data-chips]', 90);
        await t.point('[data-chips]', 'الفلاتر دي بتوريك المطبخ أو الجاهز أو نوع الطلب بس.', 3000);
        await t.click('[data-chip="takeaway"]', 'مثلاً «تيك أواي»: يوريك طلبات التيك أواي بس.', 2200);
        await t.click('[data-chip="all"]', 'و«الكل» يرجّع كل الطلبات.', 1200);
        await t.point('[data-section="new"]', 'الطلب الجديد بيظهر هنا مع جرس لحد ما حد يستلمه.', 3000);
        await t.click((p) => p.locator('[data-column="new"] [data-to="preparing"]').first(), 'دوس «بيتحضر» أول ما المطبخ يبدأ.', 2000);
        await t.click((p) => p.locator('[data-column="preparing"] [data-to="ready"]').first(), 'ولما يخلص دوس «جاهز»: العميل بيوصله إشعار على موبايله.', 2000);
        await t.click((p) => p.locator('[data-column="ready"] [data-to="served"]').first(), 'وبعد ما يستلمه دوس «اتسلّم».', 2000);
        await t.fill('[data-board-search]', '12', 'تقدر تدوّر على أي طلب برقمه.', 2200);
        await t.fill('[data-board-search]', '', '', 800);
        await t.say('الإلغاء محتاج موافقة المدير، والطلب المدفوع بيترجع من الكاشير بس.', 3400);
    },
};
