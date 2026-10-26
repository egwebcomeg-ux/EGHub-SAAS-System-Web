@extends('layouts.landing')
@section('title', __('Privacy policy') . ' · EgHub')
@section('share_card', 'privacy')

@section('page')
<x-landing.legal :title="__('Privacy policy')" :updated="__('Last updated: :date', ['date' => '2026/10/13'])" current="privacy">

    @if (app()->getLocale() === 'en')
        <p>This policy explains what data EgHub (by EgWeb) keeps and why.</p>
        <h2>Restaurants and their staff</h2>
        <p>We keep the restaurant name, the names, emails and mobile numbers of its staff, the menu and its settings, orders and subscription invoices. We use them to run the service, send account emails (such as password resets and invoices) and support you.</p>
        <h2>Guests of a restaurant</h2>
        <ul><li>A guest who only browses the menu is not asked for any personal data.</li><li>When a guest orders, joins a loyalty card or leaves a rating, the name and mobile number they enter are kept for that restaurant only.</li><li>A guest who signs in to “My account” with Google: we keep only the Google account ID, the confirmed email and the name, for that restaurant only (never a Google password or token, nothing from the Google account itself). A guest who signs in with WhatsApp sends a short code to the EgHub number: we keep the mobile number it came from, and the code expires within minutes.</li><li>This data belongs to the restaurant, is visible only to its staff, and is never shared between restaurants or sold. The same person at two restaurants is two separate guests.</li></ul>
        <h2>Cookies</h2>
        <p>We use the cookies needed to keep you signed in, remember your language and keep a guest’s cart. On the EgHub website itself (not on restaurant menus) we use the Meta pixel to measure our own ads: it counts visits, taps on WhatsApp links and new sign-ups. A restaurant may add its own ad pixels (Meta, Google, TikTok) to its menu; they are set up and controlled by that restaurant.</p>
        <h2>Services we rely on</h2>
        <p>Hosting, email delivery and, when a restaurant connects them, payment or AI providers. They receive only what is needed for their part of the service.</p>
        <h2>Keeping and deleting data</h2>
        <p>Data is kept while the account is active. A restaurant can export its customers list and can ask us to delete its data after ending its subscription. Invoices may be kept as long as the law requires.</p>
        <h2>Your rights</h2>
        <p>In line with Egypt’s Personal Data Protection Law (No. 151 of 2020), you can ask to see, correct or delete your personal data. Guests should contact the restaurant first, since it controls their data; you can also contact us.</p>
    @else
        <p>السياسة دي بتوضح EgHub (من EgWeb) بيحتفظ بأنهي بيانات وليه.</p>
        <h2>المطاعم والموظفين</h2>
        <p>بنحتفظ باسم المطعم، وأسماء وإيميلات وأرقام موبايلات الموظفين، والمنيو وإعداداته، والطلبات وفواتير الاشتراك. وبنستخدمها عشان نشغّل الخدمة ونبعت إيميلات الحساب (زي تغيير كلمة السر والفواتير) ونساعدك.</p>
        <h2>عملاء المطعم</h2>
        <ul><li>العميل اللي بيتفرّج على المنيو بس مش بيتطلب منه أي بيانات شخصية.</li><li>لما العميل يطلب أو يشترك في كارت الولاء أو يقيّم، الاسم والموبايل اللي بيكتبهم بيتحفظوا للمطعم ده بس.</li><li>العميل اللي بيدخل «حسابي» بجوجل: بنحتفظ برقم حساب جوجل والإيميل المتأكد والاسم بس، للمطعم ده بس (عمرنا ما بناخد كلمة سر جوجل أو أي حاجة تانية من الحساب). واللي بيدخل بواتساب بيبعت كود قصير لرقم EgHub: بنحتفظ بالموبايل اللي جت منه الرسالة، والكود بيخلص بعد دقايق.</li><li>البيانات دي ملك المطعم، ومحدش بيشوفها غير موظفينه، وعمرها ما بتتشارك بين المطاعم أو بتتباع. ونفس الشخص في مطعمين بيبقى عميلين منفصلين.</li></ul>
        <h2>الكوكيز</h2>
        <p>بنستخدم الكوكيز اللازمة: إنك تفضل مسجل دخول، وتفتكر لغتك، وتحفظ سلة العميل. وعلى موقع EgHub نفسه (مش على منيوهات المطاعم) بنستخدم بيكسل Meta عشان نقيس إعلاناتنا: بيعدّ الزيارات، والضغط على لينكات واتساب، والتسجيلات الجديدة. وأي مطعم ممكن يضيف بيكسلات الإعلانات بتاعته (Meta أو Google أو TikTok) على المنيو بتاعه، وده بيظبطه المطعم نفسه وتحت مسؤوليته.</p>
        <h2>خدمات بنعتمد عليها</h2>
        <p>الاستضافة وإرسال الإيميلات، ولما المطعم يربطها بنفسه: بوابات الدفع أو مزودي الذكاء الاصطناعي. كل خدمة بتاخد اللي محتاجاه لدورها بس.</p>
        <h2>الاحتفاظ بالبيانات ومسحها</h2>
        <p>البيانات بتفضل محفوظة طول ما الحساب شغال. المطعم يقدر ينزّل قايمة عملائه، ويقدر يطلب مسح بياناته بعد ما يوقف اشتراكه. الفواتير ممكن تفضل محفوظة للمدة اللي القانون بيطلبها.</p>
        <h2>حقوقك</h2>
        <p>طبقاً لقانون حماية البيانات الشخصية المصري (رقم ١٥١ لسنة ٢٠٢٠)، تقدر تطلب تشوف بياناتك الشخصية أو تصححها أو تمسحها. عملاء المطاعم يتواصلوا مع المطعم الأول لأنه المسؤول عن بياناتهم، وتقدروا تتواصلوا معانا كمان.</p>
    @endif
</x-landing.legal>
@endsection
