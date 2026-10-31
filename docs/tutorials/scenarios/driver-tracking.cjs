// الطيارين والتتبع: إسناد أوردر توصيل لطيار من لوحة الطلبات، شاشة الطيار، الزبون بيتابعه على الخريطة، والكاش مع كل طيار.
const tinker = require('../tinker.cjs');

const BASE = process.env.BASE || 'http://localhost:8000';
const DRIVER = 'driver@demo.test';
let order = {};

/**
 * Signs the recording's browser in as someone else on a page of its own, off camera (its video is thrown away), while
 * the recording shows a title card naming whose screen comes next (the next page replaces it).
 */
async function signInAs(t, email, title, subtitle) {
    await t.page.evaluate(([title, subtitle]) => {
        const c = document.createElement('div');
        c.id = 'tut-card';
        c.innerHTML = `<div>${title}<small>${subtitle}</small></div>`;
        document.body.appendChild(c);
    }, [title, subtitle]);
    const context = t.page.context();
    await context.clearCookies();
    const page = await context.newPage();
    await page.goto(BASE.replace('://', '://app.') + '/login');
    await page.fill('input[name=login]', email);
    await page.fill('input[name=password]', 'password');
    await page.press('input[name=password]', 'Enter');
    await page.waitForURL((u) => !u.pathname.startsWith('/login'));
    const video = page.video();
    await page.close();
    await video?.delete();
}

module.exports = {
    id: 'driver-tracking', title: 'الطيارين وتتبع التوصيل', as: 'owner', start: 'app:/orders',
    voice: {
        'الطيارين وتتبع التوصيل': 'أهلاً بيك في إي جي هَب. في الفيديو ده هنشوف إزاي تدي الأوردر للطيار، والعميل يتابعه على الخريطة لحد باب البيت.',
        'أوردر توصيل جاهز على لوحة الطلبات.': 'عندنا أوردر توصيل جاهز على لوحة الطلبات.',
        'اختار الطيار من الكارت نفسه.': 'اختار الطيار من كارت الأوردر نفسه.',
        'الأوردر راح للطيار، وموبايله بيرن.': 'الأوردر راح للطيار، وموبايله بيرن على طول.',
        'شاشة الطيار: أوردراته بس، بالعنوان والتليفون والمبلغ اللي هيحصّله.': 'ودي شاشة الطيار. بيشوف أوردراته بس، بالعنوان ورقم العميل والمبلغ اللي هيحصّله.',
        'استلم الأوردر؟ يدوس «استلمت».': 'ولما يستلم الأوردر من المطعم، يضغط استلمت.',
        'مكانه بيتبعت لوحده طول المشوار.': 'ومن اللحظة دي مكانه بيتبعت لوحده طول المشوار.',
        'والزبون بيشوف الطيار جاي على الخريطة من صفحة طلبه.': 'والعميل بيشوف الطيار جاي على الخريطة، من صفحة متابعة طلبه.',
        'وصل؟ يدوس «اتسلم»...': 'ولما يوصل، يضغط اتسلم.',
        '...ويختار الزبون دفع إزاي.': 'ويختار العميل دفع إزاي.',
        'وإنت من صفحة الطيارين: كل طيار مكانه على الخريطة...': 'وإنت من صفحة الطيارين، بتشوف كل طيار ومكانه على الخريطة.',
        '...وأوردراته والكاش اللي معاه، وتسوّيه وهو راجع.': 'وأوردراته، والكاش اللي معاه، وتسوّي الحساب معاه وهو راجع.',
        'خلصنا ✅': 'بالسهولة دي التوصيل بقى تحت عينك من المطبخ لباب العميل. ابدأ مجاناً من إي جي هَب دوت آب.',
    },
    prepare() {
        // a driver «كريم», and a delivery order ready for him (placed from the call center, to Nasr City)
        const out = tinker('$u = App\\Models\\User::query()->firstOrCreate(["email" => "' + DRIVER + '"], ["name" => "كريم الطيار", "phone" => "01100000009", "password" => "password"]); $u->syncRoles(["driver"]);'
            + ' $b = App\\Models\\Branch::query()->first(); $z = App\\Models\\DeliveryZone::query()->where("name", "مدينة نصر")->first(); $i = App\\Models\\MenuItem::query()->whereDoesntHave("optionGroups")->orderBy("id")->take(2)->get();'
            + ' $s = app(App\\Services\\Orders\\OrderService::class); $o = $s->place(["items" => $i->map(fn ($x) => ["item_id" => $x->id, "qty" => 1])->all(), "customer_name" => "منى علي", "customer_phone" => "01012345678",'
            + ' "source" => "callcenter", "type" => "delivery", "delivery_zone" => $z, "delivery_address" => ["zone" => "مدينة نصر", "text" => "9 شارع عباس العقاد، الدور التالت", "lat" => 30.0565, "lng" => 31.3420]], $b);'
            + ' $s->transition($o, "preparing"); $s->transition($o->fresh(), "ready"); $o->refresh(); echo "ORD|".$o->id."|".$o->tracking_token."|".$u->id."\\n";');
        const [, id, token, driverId] = out.match(/ORD\|(\d+)\|(\w+)\|(\d+)/);
        order = { id, token, driverId };
    },
    async run(t) {
        const card = `[data-driver-select="${order.id}"]`;
        await t.page.locator(card).waitFor();
        await t.scroll(card, 260);
        await t.point((p) => p.locator(card).locator('xpath=ancestor::article[1]'), 'أوردر توصيل جاهز على لوحة الطلبات.', 2600);
        await t.select(card, order.driverId, 'اختار الطيار من الكارت نفسه.', 1600);
        await t.say('الأوردر راح للطيار، وموبايله بيرن.', 2400);

        // the driver's phone: his own screen, with his position
        await t.page.context().grantPermissions(['geolocation'], { origin: BASE.replace('://', '://app.') });
        await t.page.context().setGeolocation({ latitude: 30.0612, longitude: 31.3301, accuracy: 10 });
        await signInAs(t, DRIVER, '📱 على موبايل الطيار', 'تطبيق EgHub للطيار');
        await t.go('app:/driver', 'شاشة الطيار: أوردراته بس، بالعنوان والتليفون والمبلغ اللي هيحصّله.');
        await t.wait(1200);
        await t.click(`[data-act="pickedUp"][data-id="${order.id}"]`, 'استلم الأوردر؟ يدوس «استلمت».', 2000);
        await t.point('[data-geo]', 'مكانه بيتبعت لوحده طول المشوار.', 2800);

        await t.phone(`/demo-cafe/o/${order.token}`, 'والزبون بيشوف الطيار جاي على الخريطة من صفحة طلبه.');
        await t.scroll((p) => t.inPhone(p).locator('[data-driver-box]'), 60, 1200);
        await t.point((p) => t.inPhone(p).locator('[data-driver-box]'), 'والزبون بيشوف الطيار جاي على الخريطة من صفحة طلبه.', 3600);

        await t.go('app:/driver');
        await t.click(`[data-act="delivered"][data-id="${order.id}"]`, 'وصل؟ يدوس «اتسلم»...', 1200);
        await t.click('[data-deliver-methods] [data-method="cash"]', '...ويختار الزبون دفع إزاي.', 2000);

        await signInAs(t, 'owner@demo.test', '🖥️ في المطعم', 'لوحة التحكم');
        await t.go('app:/drivers', 'وإنت من صفحة الطيارين: كل طيار مكانه على الخريطة...');
        await t.wait(2500);
        await t.point('[data-drivers]', '...وأوردراته والكاش اللي معاه، وتسوّيه وهو راجع.', 3400);
    },
};
