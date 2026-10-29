// شاشة المطبخ: الطلب من الكاشير يوصل للمطبخ، «ابدأ»، فلتر الأكل والمشروبات، «جاهز».
module.exports = {
    id: 'kitchen-display', title: 'شاشة المطبخ', subtitle: 'في الكافيه اسمها «شاشة البار»', as: 'owner', start: 'app:/pos',
    async run(t) {
        await t.say('أي طلب جديد (من الكاشير أو الترابيزة أو الأونلاين) بيروح لشاشة المطبخ لوحده.', 3200);
        await t.click((p) => p.locator('[data-items] button').filter({ hasText: 'آيس لاتيه' }).first(), 'مثلاً من الكاشير: آيس لاتيه...', 1000);
        await t.click((p) => p.locator('[data-items] button').filter({ hasText: 'براونيز' }).first(), '...وبراونيز.', 1000);
        await t.click((p) => p.locator('[data-ticket] summary').first(), 'ولو فيه ملاحظة للمطبخ اكتبها هنا.', 900);
        await t.fill('[data-ticket] [data-notes]', 'البراونيز سخنة', '');
        await t.click('[data-ticket] [data-send]', 'ودوس «ابعت للمطبخ».', 2000);
        await t.go('app:/kitchen', 'ودي شاشة المطبخ: بتشتغل على أي تابلت أو شاشة في المطبخ.');
        const ticket = (p) => p.locator('#kds article').filter({ hasText: 'البراونيز سخنة' }).first();
        await t.point(ticket, 'كل طلب في كارت: الرقم ونوعه والأصناف والملاحظات.', 3200);
        await t.point((p) => ticket(p).locator('header span').last(), 'والعداد ده بالدقايق: الكارت بيصفّر بعد 10 دقايق ويحمرّ بعد 20.', 3200);
        await t.click((p) => ticket(p).locator('[data-action="start"]'), 'أول ما تبدأ فيه دوس «ابدأ».', 1800);
        // the screen redraws every few seconds: a tap that landed on a redraw is tapped again
        if (await ticket(t.page).locator('[data-action="start"]').count()) {
            await ticket(t.page).locator('[data-action="start"]').click();
            await t.wait(1500);
        }
        await t.click('[data-chip="drinks"]', 'لو البار ليه شاشة لوحده: فلتر «مشروبات» يوريه المشروبات بس.', 2000);
        await t.click('[data-chip="all"]', 'و«الكل» يرجّع كل حاجة.', 1500);
        await t.click((p) => ticket(p).locator('[data-kind="drinks"]'), 'المشروبات خلصت؟ دوس «المشروبات جاهزة».', 1800);
        await t.click((p) => p.locator('#kds article').filter({ hasText: 'براونيز' }).first().locator('[data-kind="food"]'), 'ولما الأكل يخلص «الأكل جاهز»: الطلب يبقى جاهز في لوحة الطلبات.', 2400);
        await t.point('[data-fullscreen]', 'دوس «شاشة كاملة» عشان الشاشة تفضل مفتوحة على الطلبات بس.', 3000);
    },
};
