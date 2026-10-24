@extends('layouts.landing')
@section('title', __('Refund policy') . ' · EgHub')
@section('description', __('How EgHub subscriptions are paid, cancelled and refunded.'))

@section('page')
<x-landing.legal :title="__('Refund policy')" :updated="__('Last updated: :date', ['date' => '2026/10/23'])" current="refund">

    @if (app()->getLocale() === 'en')
        <p>This policy explains how paying for EgHub, cancelling and refunds work. It is part of the <a href="{{ route('terms') }}" class="font-semibold text-brand-700 underline">terms of service</a>.</p>
        <h2>1. Subscriptions are prepaid</h2>
        <p>Every paid plan and add-on is paid in advance for the period you choose (1, 6 or 12 months). The free plan and the free trial cost nothing and need no card.</p>
        <h2>2. Cancel at any time</h2>
        <ul><li>Nothing is charged automatically: a new period starts only when you pay its invoice from your dashboard (Subscription). To cancel, simply do not renew; you can stop at any time without calling anyone.</li><li>Your plan stays active until the end of the period you already paid for. After that the restaurant moves to the free plan, its menu stays online and its data is kept.</li></ul>
        <h2>3. Refunds</h2>
        <ul><li>Payments are not refunded for the unused days of a period you cancelled, except where Egyptian law requires a refund.</li><li>If you were charged by mistake (a double payment, a wrong amount, or a charge for a period you did not order), we refund the difference within 14 days to the same payment method you used.</li></ul>
        <h2>4. Installments</h2>
        <p>When you pay through an installment or buy-now-pay-later provider (for example valU or bank installments), the provider's own terms apply to the installments, their fees and any refund between you and the provider. Our part is the subscription price shown on your invoice.</p>
        <h2>5. Contact us</h2>
        @if ($contactEmail !== '' || $contactWhatsApp !== '')
            <p>For a refund request or a billing question, send us the invoice number:</p>
            <ul>
                @if ($contactEmail !== '')<li>Email: <a href="mailto:{{ $contactEmail }}" class="font-semibold text-brand-700 underline" dir="ltr">{{ $contactEmail }}</a></li>@endif
                @if ($contactWhatsApp !== '')<li>WhatsApp: <a href="{{ $contactWhatsAppUrl }}" target="_blank" rel="noopener" class="font-semibold text-brand-700 underline" dir="ltr">{{ $contactWhatsApp }}</a></li>@endif
            </ul>
        @else
            <p>For a refund request or a billing question, contact support from your dashboard and include the invoice number.</p>
        @endif
    @else
        <p>السياسة دي بتوضّح إزاي بتدفع اشتراك EgHub وإزاي تلغيه وإمتى الفلوس بترجع. وهي جزء من <a href="{{ route('terms') }}" class="font-semibold text-brand-700 underline">شروط الاستخدام</a>.</p>
        <h2>١. الاشتراك مدفوع مقدّماً</h2>
        <p>كل باقة مدفوعة وكل إضافة بتتدفع مقدّماً عن المدة اللي بتختارها (شهر أو ٦ أو ١٢ شهر). الباقة المجانية وفترة التجربة مجانية ومش محتاجة بطاقة.</p>
        <h2>٢. تقدر تلغي في أي وقت</h2>
        <ul><li>مفيش أي خصم تلقائي: المدة الجديدة بتبدأ بس لما تدفع فاتورتها من لوحة التحكم (الاشتراك). عشان تلغي، ببساطة ماتجددش؛ وتقدر توقف في أي وقت من غير ما تكلّم حد.</li><li>باقتك بتفضل شغالة لحد آخر المدة اللي دفعتها. بعدها المطعم بينزل على الباقة المجانية، والمنيو بيفضل شغال وبياناته محفوظة.</li></ul>
        <h2>٣. استرداد الفلوس</h2>
        <ul><li>المبالغ المدفوعة مش بترجع عن الأيام اللي ماتستخدمتش من مدة إنت لغيتها، إلا في الحالات اللي القانون المصري بيلزم فيها بالاسترداد.</li><li>لو اتخصم منك مبلغ بالغلط (دفع مرتين، أو مبلغ غلط، أو مدة ماطلبتهاش)، بنرجّعلك الفرق خلال ١٤ يوم على نفس وسيلة الدفع اللي دفعت بيها.</li></ul>
        <h2>٤. التقسيط</h2>
        <p>لو دفعت عن طريق شركة تقسيط أو «اشتري دلوقتي وادفع بعدين» (زي valU أو تقسيط البنوك)، شروط الشركة دي هي اللي بتطبق على الأقساط ومصاريفها وأي استرداد بينك وبينها. اللي علينا هو سعر الاشتراك المكتوب في فاتورتك.</p>
        <h2>٥. تتواصل معانا إزاي</h2>
        @if ($contactEmail !== '' || $contactWhatsApp !== '')
            <p>لطلب استرداد أو أي سؤال عن الفواتير، ابعتلنا رقم الفاتورة على:</p>
            <ul>
                @if ($contactEmail !== '')<li>الإيميل: <a href="mailto:{{ $contactEmail }}" class="font-semibold text-brand-700 underline" dir="ltr">{{ $contactEmail }}</a></li>@endif
                @if ($contactWhatsApp !== '')<li>واتساب: <a href="{{ $contactWhatsAppUrl }}" target="_blank" rel="noopener" class="font-semibold text-brand-700 underline" dir="ltr">{{ $contactWhatsApp }}</a></li>@endif
            </ul>
        @else
            <p>لطلب استرداد أو أي سؤال عن الفواتير، كلّم الدعم من لوحة التحكم وابعت رقم الفاتورة.</p>
        @endif
    @endif
</x-landing.legal>
@endsection
