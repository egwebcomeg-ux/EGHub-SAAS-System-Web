// الحملات: حملة مجانية «من موبايلي» لمجموعة عملاء، بكوبون شخصي لكل عميل، وقائمة الإرسال.
module.exports = {
    id: 'campaigns', title: 'حملة لعملائك', subtitle: 'ببلاش من موبايلك', as: 'owner', start: 'app:/campaigns',
    async run(t) {
        await t.say('الحملات: ابعت رسالة لمجموعة من عملائك، وضيف كوبون خصم وشوف مين رجع.', 3200);
        await t.fill('#c-name', 'نورتونا', 'اكتب اسم للحملة.');
        await t.click('[data-via="manual"]', 'هتبعتها إزاي؟ «من موبايلي» ببلاش: بتبعت لكل عميل من واتساب موبايلك.', 1600);
        await t.click((p) => p.locator('[data-segments] label').filter({ hasText: 'جداد' }).first(), 'مين يوصله؟ اختار مجموعة: مثلاً العملاء الجداد.', 1400);
        await t.point('[data-audience]', 'هنا عدد العملاء اللي هتوصلهم الرسالة.', 2600);
        await t.fill('#c-message', 'أهلاً يا {name}، نورتنا في {restaurant}! خصم 15% على زيارتك الجاية بالكود {coupon}', 'اكتب الرسالة: {name} بيتغير لاسم كل عميل.', 600);
        await t.click('[data-personal-coupon-toggle]', 'وضيف كوبون شخصي لكل عميل: كود بيتستخدم مرة واحدة.', 1200);
        await t.point('[data-personal-coupon]', 'الخصم 15% وصالح 7 أيام، وتقدر تغيرهم.', 2800);
        await t.point('[data-message-preview]', 'ودي معاينة الرسالة زي ما العميل هيشوفها.', 3000);
        await t.click((p) => p.locator('[data-campaign-form] > button.btn-primary').last(), 'ودوس «اعمل الحملة».', 2200);
        await t.point('[data-outreach-stats]', 'قايمة الإرسال جاهزة: عدد العملاء، واللي اتبعتلهم، والطلبات والمبيعات اللي جت من الحملة.', 3600);
        await t.point((p) => p.locator('[data-outreach-row]').first(), 'كل عميل وكوده الشخصي.', 2600);
        await t.point((p) => p.locator('[data-outreach-send]').first(), 'دوس «ابعت»: الواتساب بيفتح على موبايلك والرسالة جاهزة، وإنت تدوس إرسال.', 3800);
        await t.say('والسطر بيتعلّم إنه اتبعت، فتكمل من مكان ما وقفت.', 3000);
    },
};
