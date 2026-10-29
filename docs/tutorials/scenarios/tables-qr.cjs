// الترابيزات و QR: إضافة ترابيزات، تصميم كارت الستاند، والطباعة.
module.exports = {
    id: 'tables-qr', title: 'الترابيزات وكروت QR', as: 'owner', start: 'app:/tables',
    async run(t) {
        await t.say('من هنا بتعمل لكل ترابيزة كارت QR: الزبون يمسحه يفتح المنيو ويطلب من مكانه.', 3400);
        await t.point((p) => p.locator('section.card').first(), 'ده رابط المنيو بتاعك: تقدر تبعته لأي حد أو تحطه على السوشيال.', 3000);
        await t.scroll('form[action$="/tables"]', 160);
        await t.fill('form[action$="/tables"] input[name=from]', 8, 'عشان تضيف ترابيزات: اكتب من رقم...');
        await t.fill('form[action$="/tables"] input[name=to]', 9, '...لحد رقم. هنا هنضيف ترابيزة 8 و 9.');
        await t.click('form[action$="/tables"] button', 'ودوس «إضافة».', 1600);
        const table8 = (p) => p.locator('.rounded-xl.border').filter({ hasText: 'ترابيزة 8' }).first();
        await t.scroll(table8, 200);
        await t.point(table8, 'الترابيزتين اتضافوا، ولكل واحدة كود QR خاص بيها.', 2800);
        await t.click((p) => table8(p).locator('summary'), 'من «تعديل» تغيّر الاسم أو عدد الكراسي.', 1200);
        await t.fill((p) => table8(p).locator('input[name=seats]'), 4, 'مثلاً: 4 كراسي.');
        await t.click((p) => table8(p).locator('form').first().locator('button'), 'واحفظ.', 1600);
        await t.page.evaluate(() => document.querySelectorAll('a[target=_blank]').forEach((a) => a.removeAttribute('target')));
        await t.scroll(table8, 200, 600);
        await t.click((p) => table8(p).getByRole('link', { name: 'QR', exact: true }), 'دلوقتي دوس «QR» عشان تطبع كارت الترابيزة.', 2000);
        await t.point('[data-stand]', 'ده شكل الكارت في الستاند، باسم المطعم ورقم الترابيزة.', 3000);
        await t.click((p) => p.locator('label').filter({ hasText: 'أكريليك كحلي' }).first(), 'اختار خامة الستاند...', 1800);
        await t.click((p) => p.locator('[data-accent]').nth(2), '...واللون اللي يناسب المكان: الكارت بيتغير قدامك.', 1800);
        await t.fill('input[name=wifi_name]', 'DemoCafe', 'ولو حابب تكتب اسم وباسورد الواي فاي على الكارت.');
        await t.fill('input[name=wifi_password]', '12345678', '');
        await t.click((p) => p.getByRole('button', { name: 'احفظ التصميم' }), 'احفظ التصميم عشان يتطبق على كل الكروت.', 1800);
        await t.point((p) => p.getByRole('button', { name: 'طباعة', exact: true }), 'وبعدين «طباعة»: أو «تحميل SVG» لو هتطبعها في مطبعة.', 3200);
    },
};
