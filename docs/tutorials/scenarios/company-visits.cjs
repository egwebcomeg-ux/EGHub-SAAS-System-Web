// زيارات الشركات: لينك الشركة، طلب زيارة ببادجت، موافقة المدير وتقسيم البادجت.
const day = new Date(Date.now() + 2 * 86400000).toISOString().slice(0, 10);
module.exports = {
    id: 'company-visits', title: 'زيارات الشركات', subtitle: 'المندوب جاي ببادجت: وجبات + فاوتشر للشركة', as: 'owner', start: 'app:/corporate',
    async run(t) {
        await t.click((p) => p.locator('table a, [data-contract] a, a').filter({ hasText: 'شركة النيل' }).first(), 'افتح عقد الشركة من تعاقدات الشركات.');
        await t.click((p) => p.getByRole('link', { name: 'كشف حساب الشركة' }).first(), 'الأول نتأكد إن الشركة دافعة: افتح كشف الحساب.');
        await t.fill('[data-payment-form] input[name=amount]', 5000, 'سجّل الدفعة اللي الشركة دفعتها (تحويل، شيك، كاش...).');
        await t.click((p) => p.locator('[data-payment-form] button').last(), 'واحفظ الدفعة: بتنزل في كشف الحساب.');
        await t.go('app:/corporate', 'نرجع للعقد.');
        await t.click((p) => p.locator('a').filter({ hasText: 'شركة النيل' }).first(), '');
        await t.click('[data-portal-make]', 'اعمل لينك خاص للشركة وابعتهولها: منه بتطلب زياراتها وتشوف حسابها.');
        const url = await t.page.inputValue('[data-portal-url]');
        await t.point('[data-portal-url]', 'ده لينك الشركة.');
        await t.go(url, 'كده إحنا بنشوف صفحة الشركة زي ما هي شايفاها.');
        await t.point('[data-company-account]', 'الشركة شايفة حسابها: المطلوب والمدفوع والمتبقي.');
        await t.fill('input[name=date]', day, 'تختار يوم الزيارة (قبلها بالمدة اللي حددتها في العقد).');
        await t.fill('input[name=time]', '13:00', '');
        await t.fill('input[name=party_size]', 6, 'عدد الأفراد.');
        await t.fill('input[name=budget]', 3000, 'والبادجت: مثلاً 3000 جنيه.');
        await t.fill('input[name=rep_name]', 'أحمد', 'اسم المندوب اللي جاي.');
        await t.fill('input[name=rep_phone]', '01011112222', 'وموبايله.');
        await t.click((p) => p.getByRole('button', { name: 'ابعت الزيارة' }), 'وتبعت الطلب.');
        await t.point('[data-company-visits]', 'الزيارة مستنية موافقة المطعم.');
        await t.go('app:/corporate/bookings', 'عندك في لوحة التحكم: زيارات الشركات.');
        await t.point((p) => p.locator('[data-booking-status="requested"]').first(), 'الزيارة الجديدة مستنية موافقة المدير.');
        await t.fill((p) => p.locator('[data-booking-approve] input[name=meal_price]').first(), 400, 'اكتب سعر الوجبة للفرد: 6 أفراد × 400 = 2400 وجبات.');
        await t.click((p) => p.locator('[data-booking-approve] button').first(), 'وأكد الزيارة: الباقي (600) بيبقى فاوتشر للشركة.');
        await t.point((p) => p.locator('[data-meals-voucher]').first(), 'كود الوجبات بيشتغل يوم الزيارة بس ومع موبايل المندوب.');
        await t.point((p) => p.locator('[data-company-voucher]').first(), 'وده فاوتشر الشركة بالباقي لحد آخر العقد.');
        await t.point('[data-send-rep]', 'ابعت الكود للمندوب على واتساب بضغطة.', 3000);
        await t.say('بعد الزيارة، اللي ما اتصرفش من الوجبات بيتحول لوحده على فاوتشر الشركة.', 3500);
    },
};
