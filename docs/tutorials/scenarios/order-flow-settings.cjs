// إعدادات سير الطلبات وتأكيد رقم العميل.
module.exports = {
    id: 'order-flow-settings', title: 'إعداد سير الطلبات', as: 'owner', start: 'app:/settings?tab=orders',
    async run(t) {
        await t.point('[data-order-flow]', 'من الإعدادات ← الطلبات تحدد الطلب بيمشي إزاي حسب الشاشات اللي شغال بيها.', 3200);
        await t.select('select[name=order_flow]', 'auto', 'الأوتوماتيك هو المقترح: لو بتستخدم شاشة المطبخ الطلب بيروحلها، ولو لأ بيخلص أول ما يتدفع.');
        await t.fill('input[name=order_auto_serve_minutes]', 60, 'والطلب المدفوع اللي فاضل «جاهز» بيتقفل لوحده بعد الوقت ده.');
        await t.point('[data-verify-phone]', 'وهنا تقدر تطلب تأكيد رقم العميل بكود على واتساب قبل طلبات الدليفري أو كل الطلبات الأونلاين.', 3600);
        await t.select('select[name=online_verify_phone]', 'delivery', 'مثلاً: طلبات الدليفري بس.');
        await t.click((p) => p.getByRole('button', { name: 'حفظ الإعدادات' }), 'واحفظ.');
    },
};
