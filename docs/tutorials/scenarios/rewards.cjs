// المكافآت: أقسام الصفحة، برنامج الولاء، ترحيب العملاء الجداد (هدية التسجيل)، الكاش باك والعضوية والباقات والمحفظة.
const tinker = require('../tinker.cjs');

module.exports = {
    prepare() {
        // the scenario switches the welcome gift on
        tinker('App\\Services\\Settings::put(["welcome_reward_enabled" => "0"]);');
    },
    id: 'rewards', title: 'المكافآت', subtitle: 'الولاء والترحيب والكاش باك والباقات', as: 'owner', start: 'app:/rewards',
    async run(t) {
        await t.say('صفحة المكافآت: كل حاجة الضيف ممكن يكسبها في مكان واحد، وكل جزء بيتحفظ لوحده.', 3200);
        await t.point('[data-rewards-nav]', 'الأزرار دي بتوديك على أي جزء على طول.', 2800);
        await t.point('#program', 'برنامج الولاء: كارت أختام ديجيتال على موبايل الضيف، بالزيارة أو بالصنف أو نقاط.', 3600);
        await t.click('[data-rewards-nav-item="welcome"]', 'تعالى نشوف «ترحيب بالعملاء الجداد».', 1400);
        await t.point('[data-welcome-compare]', 'طريقتين: هدية على أول طلب أونلاين، أو رسالة شكر بعد أول طلب فيها كوبون للزيارة الجاية.', 4200);
        await t.scroll('[data-welcome-form]', 140);
        await t.click('[data-welcome-on]', 'فعّل هدية التسجيل...', 900);
        await t.select('#w-type', 'percent', '...اختار نوعها: خصم % على أول طلب أونلاين...');
        await t.fill('#w-value', 15, '...والقيمة: 15%.');
        await t.click('[data-welcome-form] button', 'واحفظ.', 1800);
        await t.scroll('[data-welcome-form]', 140);
        await t.point('[data-welcome-form]', 'خلاص: المنيو هيقول للضيف الجديد «سجّل وخد 15%»، والخصم بيتشال لوحده على أول طلب.', 3800);
        await t.click('[data-rewards-nav-item="cashback"]', 'وتحت كمان: الكاش باك...', 800);
        await t.point('#cashback', 'نسبة من كل طلب بترجع رصيد في محفظة الضيف يصرفه المرة الجاية.', 3200);
        await t.point('#tiers', 'ومستويات العضوية: فضي وذهبي وVIP بخصم تلقائي حسب زياراته أو اللي صرفه.', 3400);
        await t.click('[data-rewards-nav-item="packages"]', 'وآخر حاجة: الباقات مسبقة الدفع.', 800);
        await t.point('#packages', 'والباقات مسبقة الدفع: مثلاً 10 قهوة بسعر مخفض، الكاشير بيبيعها من مكتب العميل.', 3600);
    },
};
