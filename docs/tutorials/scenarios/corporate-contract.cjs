// تعاقدات الشركات: تعاقد جديد بفاوتشرات، طباعة الفاوتشرات، وتسجيل دفعة في كشف حساب الشركة.
const expires = new Date(Date.now() + 90 * 86400000).toISOString().slice(0, 10);
module.exports = {
    id: 'corporate-contract', title: 'تعاقد شركة', subtitle: 'فاوتشرات مدفوعة لموظفي الشركة', as: 'owner', start: 'app:/corporate',
    async run(t) {
        await t.say('تعاقدات الشركات: شركة بتشتري فاوتشرات لموظفينها، وإنت بتتابع الصرف والحساب.', 3400);
        await t.click('[data-new-contract]', 'دوس «تعاقد جديد».', 1400);
        const form = '[data-contract-form]';
        await t.fill(`${form} input[name=name]`, 'إيجي ويب للبرمجيات', 'اسم الشركة...');
        await t.fill(`${form} input[name=contact_name]`, 'منى', '...واسم المسؤول...');
        await t.fill(`${form} input[name=contact_phone]`, '01055556666', '...وموبايله.');
        await t.select(`${form} select[name=billing]`, 'prepaid', 'الشركة هتدفع إزاي: مقدماً أو آجل بحد أقصى.');
        await t.scroll(`${form} input[name=voucher_value]`, 200);
        await t.fill(`${form} input[name=voucher_value]`, 150, 'قيمة الفاوتشر: 150 جنيه...');
        await t.fill(`${form} input[name=quantity]`, 20, '...وعددها: 20 فاوتشر لـ 20 موظف.');
        await t.fill(`${form} input[name=expires_on]`, expires, 'صالحة لحد إمتى.');
        await t.fill(`${form} input[name=prefix]`, 'EGW', 'وبادئة السيريال: EGW-0001، EGW-0002...');
        await t.click((p) => p.locator(`${form} label`).filter({ has: p.locator('input[name=mode][value=balance]') }), 'والاستخدام: زي الرصيد، اللي يفضل بيتشال للطلب الجاي.', 1200);
        await t.click((p) => p.locator(`${form} button.btn-primary`).last(), 'ودوس «اعمل التعاقد والفاوتشرات».', 2200);
        await t.point('[data-contract-stats]', 'التعاقد جاهز: 20 فاوتشر، وتشوف اللي اتصرف واللي باقي.', 3400);
        await t.page.evaluate(() => document.querySelectorAll('a[target=_blank]').forEach((a) => a.removeAttribute('target')));
        await t.click('[data-print-new]', 'دوس «اطبع الفاوتشرات الجديدة».', 2000);
        await t.say('كل فاوتشر بكود و QR وقيمته وتاريخه: اطبعها على A4 أو إيصالات 80 مم وسلّمها للشركة.', 3800);
        await t.click((p) => p.getByRole('link', { name: 'رجوع للتعاقد' }), 'نرجع للتعاقد.', 1200);
        await t.click('[data-statement-link]', 'ولما الشركة تدفع: افتح «كشف حساب الشركة».', 1800);
        await t.fill('[data-payment-form] input[name=amount]', 3000, 'اكتب المبلغ اللي دفعته...');
        await t.select('[data-payment-form] select[name=method]', 'transfer', '...وطريقة الدفع: تحويل بنكي.');
        await t.fill('[data-payment-form] input[name=reference]', 'TRX-1029', 'ورقم التحويل أو الشيك لو فيه.');
        await t.click('[data-payment-form] button', 'ودوس «سجّل الدفعة».', 2000);
        await t.point('[data-account]', 'الدفعة نزلت في الكشف، والمطلوب والمدفوع والمتبقي اتحسبوا لوحدهم.', 3600);
    },
};
