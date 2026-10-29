// المستخدمين والصلاحيات: إضافة كاشير بدوره وفرعه، وصلاحية إضافية فوق الدور.
module.exports = {
    id: 'users-roles', title: 'إضافة موظف', subtitle: 'المستخدمين والصلاحيات', as: 'owner', start: 'app:/users',
    async run(t) {
        await t.say('المستخدمين والصلاحيات: كل موظف بحسابه، وبيشوف ويعمل اللي دوره يسمح بيه بس.', 3400);
        const form = 'form[action$="/users"]';
        await t.fill(`${form} input[name=name]`, 'سارة محمود', 'نضيف كاشير: الاسم...');
        await t.fill(`${form} input[name=email]`, 'sara@demo.test', '...والإيميل اللي هتدخل بيه...');
        await t.fill(`${form} input[name=phone]`, '01077778888', '...وموبايلها.');
        await t.select(`${form} select[name=role]`, 'waiter', 'الدور: كل دور ليه صلاحيات جاهزة، مثلاً «ويتر»...');
        await t.point(`${form} [data-role-permissions]`, 'وتحته بالظبط هو يقدر يعمل إيه.', 2600);
        await t.select(`${form} select[name=role]`, 'cashier', 'إحنا عايزين «كاشير»: الكاشير والورديات والطلبات.');
        await t.select(`${form} select[name=branch_id]`, { index: 1 }, 'والفرع اللي بتشتغل فيه.');
        await t.fill(`${form} input[name=password]`, 'Sara@2026', 'وكلمة سر مبدئية (تقدر تغيرها بعدين).');
        await t.click(`${form} [data-extra-permissions] summary`, 'عايز تديها حاجة زيادة عن دورها؟ «صلاحيات إضافية».', 1400);
        await t.click(`${form} [data-permission="reports.view"]`, 'مثلاً: تشوف التقارير.', 1400);
        await t.click(`${form} > button.btn-primary`, 'ودوس «إضافة مستخدم».', 1800);
        await t.point((p) => p.locator('tr').filter({ hasText: 'سارة محمود' }).first(), 'سارة اتضافت: كاشير في الفرع الرئيسي، وصلاحية زيادة واحدة.', 3400);
        await t.point((p) => p.locator('tr').filter({ hasText: 'سارة محمود' }).first().locator('summary').first(), 'ومن «تعديل» تغير دورها أو توقف حسابها لو سابت الشغل.', 3200);
    },
};
