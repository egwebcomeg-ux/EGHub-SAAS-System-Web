@extends('layouts.landing')
@section('title', __('Terms of service') . ' · EgHub')
@section('share_card', 'terms')

@section('page')
<x-landing.legal :title="__('Terms of service')" :updated="__('Last updated: :date', ['date' => '2026/10/13'])" current="terms">

    @if (app()->getLocale() === 'en')
        <p>EgHub is a service of EgWeb (“we”). By creating an account you (“the restaurant”) accept these terms.</p>
        <h2>1. The service</h2>
        <p>EgHub gives restaurants and cafes a digital menu, table ordering, a loyalty card and the other features of the plan and add-ons they subscribe to. Features and limits of each plan are shown on the pricing page and in your dashboard.</p>
        <h2>2. Your account</h2>
        <ul><li>You are responsible for your staff accounts and their passwords, and for the permissions you give them.</li><li>The information you enter (names, prices, photos, offers) must be accurate and you must own the rights to it.</li><li>You may not use EgHub for anything unlawful or to mislead guests.</li></ul>
        <h2>3. Subscription and payment</h2>
        <ul><li>New restaurants get a free trial. After it ends, a paid plan is needed to keep the menu online.</li><li>Subscriptions are paid in advance for the chosen period (1, 6 or 12 months) by the payment methods shown in the dashboard.</li><li>If a renewal is not paid, the account gets a short grace period and is then paused. Your data is kept and the menu returns once you pay.</li><li>Payments already made are not refunded for unused days, unless the law requires otherwise.</li><li>We may change prices; a new price applies from your next renewal and we announce it in advance.</li></ul>
        <h2>4. Orders and payments between you and your guests</h2>
        <p>EgHub passes guest orders to your restaurant. The sale, the food, the prices charged and any payment between you and your guests are your responsibility.</p>
        <h2>5. Third-party services</h2>
        <p>Some features use services you connect yourself, such as an AI provider with your own API key. Their terms and costs apply between you and that provider.</p>
        <h2>6. Availability</h2>
        <p>We work to keep the service running at all times but cannot guarantee it will never be interrupted. We are not liable for indirect losses such as lost sales.</p>
        <h2>7. Ending the service</h2>
        <p>You can stop your subscription at any time. We may suspend accounts that break these terms. You can ask us to delete your restaurant data after you stop.</p>
        <h2>8. Law</h2>
        <p>These terms are governed by the laws of the Arab Republic of Egypt.</p>
    @else
        <p>EgHub خدمة مقدّمة من EgWeb («إحنا»). بإنشاء حساب إنت («المطعم») بتوافق على الشروط دي.</p>
        <h2>١. الخدمة</h2>
        <p>EgHub بيقدّم للمطاعم والكافيهات منيو ديجيتال وطلب من الترابيزة وكارت ولاء وباقي مميزات الباقة والإضافات اللي المطعم مشترك فيها. مميزات وحدود كل باقة موجودة في صفحة الأسعار وفي لوحة التحكم.</p>
        <h2>٢. حسابك</h2>
        <ul><li>إنت مسؤول عن حسابات الموظفين وكلمات السر بتاعتهم والصلاحيات اللي بتديهالهم.</li><li>البيانات اللي بتدخلها (الأسماء والأسعار والصور والعروض) لازم تكون صحيحة ويكون من حقك تستخدمها.</li><li>ممنوع استخدام EgHub في أي حاجة مخالفة للقانون أو لتضليل العملاء.</li></ul>
        <h2>٣. الاشتراك والدفع</h2>
        <ul><li>المطاعم الجديدة بتاخد فترة تجربة مجانية، وبعدها لازم باقة مدفوعة عشان المنيو يفضل شغال.</li><li>الاشتراك بيتدفع مقدّماً عن المدة اللي تختارها (شهر أو ٦ أو ١٢ شهر) بوسائل الدفع الموجودة في لوحة التحكم.</li><li>لو التجديد ماتدفعش، الحساب بياخد مهلة قصيرة وبعدها بيتوقف مؤقتاً. بياناتك بتفضل محفوظة والمنيو بيرجع أول ما تدفع.</li><li>المبالغ المدفوعة مش بترجع عن الأيام اللي ماتستخدمتش، إلا لو القانون بيقول غير كده.</li><li>ممكن نغيّر الأسعار، والسعر الجديد بيبدأ من التجديد اللي بعده وبنبلّغك قبلها.</li></ul>
        <h2>٤. الطلبات والفلوس بينك وبين عملائك</h2>
        <p>EgHub بيوصّل طلبات العملاء لمطعمك. البيع والأكل والأسعار وأي دفع بينك وبين عملائك مسؤوليتك إنت.</p>
        <h2>٥. خدمات تانية</h2>
        <p>بعض المميزات بتستخدم خدمات بتربطها بنفسك، زي مزود الذكاء الاصطناعي بمفتاحك الخاص. شروط وتكلفة الخدمات دي بينك وبين المزود.</p>
        <h2>٦. التشغيل</h2>
        <p>بنشتغل إن الخدمة تفضل شغالة طول الوقت، بس مانقدرش نضمن إنها عمرها ما هتقف. ومش مسؤولين عن خسائر غير مباشرة زي المبيعات اللي فاتت.</p>
        <h2>٧. إنهاء الخدمة</h2>
        <p>تقدر توقف اشتراكك في أي وقت. وممكن نوقف الحسابات اللي بتخالف الشروط دي. وتقدر تطلب مننا نمسح بيانات مطعمك بعد ما توقف.</p>
        <h2>٨. القانون</h2>
        <p>الشروط دي خاضعة لقوانين جمهورية مصر العربية.</p>
    @endif
</x-landing.legal>
@endsection
