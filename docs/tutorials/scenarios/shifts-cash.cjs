// الورديات والدرج: فتح وردية بالفلوس اللي في الدرج، صرف من الدرج بسبب، وتقفيل الوردية بالعد والفرق.
const tinker = require('../tinker.cjs');

module.exports = {
    id: 'shifts-cash', title: 'الورديات وفلوس الدرج', as: 'owner', start: 'app:/shifts',
    // the video opens a shift: one left open by the cashier videos is closed first, with what it expected
    prepare: () => tinker('foreach (App\\Models\\Shift::whereNull("closed_at")->get() as $s) { $s->forceFill(["closed_at" => now(), "closing_cash" => $s->expected_cash ?? $s->opening_cash, "expected_cash" => $s->expected_cash ?? $s->opening_cash])->save(); } echo "shifts ok";'),
    voice: {
        'الورديات وفلوس الدرج': 'أهلاً بيك في إي جي هَب. في الفيديو ده هنشوف إزاي تفتح وردية الكاشير وتقفلها وتعرف الدرج ناقص ولا زيادة.',
        'أول اليوم: اكتب الفلوس اللي في الدرج...': 'أول اليوم، اكتب الفلوس اللي في الدرج.',
        '...ودوس «فتح وردية».': 'واضغط فتح وردية.',
        'الوردية مفتوحة: المبيعات كاش وفيزا والمتوقع في الدرج بيتحسب لوحده.': 'كده الوردية مفتوحة، والمبيعات كاش وفيزا، والمتوقع في الدرج بيتحسب لوحده.',
        'صرفت من الدرج؟ سجّلها: المبلغ...': 'لو صرفت من الدرج، سجّلها. اكتب المبلغ.',
        '...والسبب.': 'والسبب.',
        'واحفظ: بتتخصم من المتوقع.': 'واحفظ، وهتتخصم من المتوقع في الدرج.',
        'آخر الوردية: عِد الفلوس واكتبها...': 'وآخر الوردية، عِد الفلوس واكتبها.',
        '...ودوس «قفل الوردية».': 'واضغط قفل الوردية.',
        'والفرق بيبان: ناقص ولا زيادة، ومين اللي كان على الوردية.': 'والفرق بيبان على طول، ناقص ولا زيادة، ومين اللي كان على الوردية.',
        'خلصنا ✅': 'بالسهولة دي تتابع فلوس الدرج كل وردية. ابدأ مجاناً من إي جي هَب دوت آب.',
    },
    async run(t) {
        await t.fill('#opening_cash', 500, 'أول اليوم: اكتب الفلوس اللي في الدرج...');
        // opening a shift lands on the cashier screen, ready to sell
        await t.click('form[action$="/shifts/open"] button', '...ودوس «فتح وردية».', 2400);
        await t.go('app:/shifts', 'الوردية مفتوحة: المبيعات كاش وفيزا والمتوقع في الدرج بيتحسب لوحده.');
        await t.wait(1200);
        const move = 'form[action$="/shifts/move"]';
        await t.fill(`${move} input[name=amount]`, 50, 'صرفت من الدرج؟ سجّلها: المبلغ...');
        await t.fill(`${move} input[name=reason]`, 'تلج', '...والسبب.');
        await t.click(`${move} button`, 'واحفظ: بتتخصم من المتوقع.', 1800);
        const close = 'form[action$="/shifts/close"]';
        await t.fill(`${close} input[name=closing_cash]`, 440, 'آخر الوردية: عِد الفلوس واكتبها...');
        await t.click(`${close} button`, '...ودوس «قفل الوردية».', 2400);
        await t.scroll((p) => p.locator('h2').filter({ hasText: 'الورديات المقفولة' }), 100, 1200);
        await t.say('والفرق بيبان: ناقص ولا زيادة، ومين اللي كان على الوردية.', 3400);
    },
};
