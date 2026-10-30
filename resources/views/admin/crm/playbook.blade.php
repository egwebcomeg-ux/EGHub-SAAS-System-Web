@extends('layouts.base')
@section('title', 'خطة فريق المبيعات · EgHub')
@section('body_class', 'bg-white text-navy-900')

{{--
    «خطة فريق المبيعات»: the agreement and the daily guide of the sales team in Tanta, written for the team in Egyptian
    Arabic and printed on A4. Every number (pay, commission, bonuses, allowances, targets, the Complete plan's price)
    is read live from App\Support\SalesPlan and the plans table, so the paper always matches the system.
--}}
@php
    $p = $plan;
    $m = fn ($n) => money($n);
    $fullMonthly = (float) ($full?->monthly_price ?? 0);
    $fullYear = $full ? (float) $full->priceFor(12) : 0.0;
    $closerMonthly = \App\Support\SalesPlan::closerCommission($fullMonthly);
    $assistMonthly = \App\Support\SalesPlan::assistCommission($fullMonthly);
    $closerYear = \App\Support\SalesPlan::closerCommission($fullYear);
    $assistYear = \App\Support\SalesPlan::assistCommission($fullYear);
    $retention = \App\Support\SalesPlan::retentionCommission($fullMonthly);
    $rampCaller = $p['ramp']['caller'];
    $rampField = $p['ramp']['field'];
    // a worked month for each seat, on the first month's targets
    $fieldPaid = (int) ($rampField[0]['paid'] ?? 0);
    $fieldExample = $p['base']['field'] + $fieldPaid * $closerMonthly + $p['bonus']['field'] + 18 * $p['allowance']['tanta'] + 4 * $p['allowance']['gharbia'] + $p['phone']['field'];
    $callerVisits = (int) ($rampCaller[0]['appointments'] ?? 0);
    $callerExample = $p['base']['caller'] + $callerVisits * $p['per_visited_appointment'] + $fieldPaid * $assistMonthly + $p['bonus']['caller'] + $p['phone']['caller'];
    $script = fn (string $text) => e(preg_replace('/\{[a-z_]+\}/', '…', strtr($text, [
        '{restaurant}' => 'المكان', '{owner}' => 'حضرتك', '{caller}' => 'أنا', '{trial_length}' => (string) \App\Services\TenantProvisioner::TRIAL_DAYS,
        '{full_price}' => $m($fullMonthly), '{full_year_price}' => $m($fullYear),
    ])) ?? '');
@endphp

@push('head')
<style>
    @page { size: A4; margin: 14mm 12mm; }
    .pb h2 { font-size: 1.15rem; font-weight: 900; color: #131D3D; border-bottom: 3px solid #F37623; padding-bottom: .25rem; margin: 1.6rem 0 .7rem; }
    .pb h3 { font-weight: 800; color: #213368; margin: 1rem 0 .4rem; }
    .pb p, .pb li { line-height: 1.9; }
    .pb ul { list-style: disc; padding-inline-start: 1.4rem; }
    .pb ol { list-style: decimal; padding-inline-start: 1.4rem; }
    .pb table { width: 100%; border-collapse: collapse; margin: .5rem 0; font-size: .92rem; }
    .pb th, .pb td { border: 1px solid #cbd5e1; padding: .4rem .55rem; text-align: start; vertical-align: top; }
    .pb th { background: #131D3D; color: #fff; }
    .pb .box { border: 1px solid #cbd5e1; border-radius: .75rem; padding: .75rem 1rem; margin: .6rem 0; }
    .pb .page-break { break-before: page; }
    @media print { .no-print { display: none !important; } .pb { font-size: 11.5pt; } .pb h2 { break-after: avoid; } .pb table, .pb .box { break-inside: avoid; } }
</style>
@endpush

@section('body')
<div class="no-print sticky top-0 z-10 flex items-center justify-between gap-2 border-b border-navy-100 bg-white px-4 py-3">
    <a href="{{ route('admin.crm.prospects') }}" class="btn-ghost">← رجوع</a>
    <button type="button" class="btn-primary" onclick="window.print()">اطبع الخطة</button>
</div>

<main class="pb mx-auto max-w-4xl px-5 py-6" data-playbook>
    <header class="flex items-center justify-between gap-4 border-b-4 border-navy-900 pb-4">
        <div>
            <h1 class="text-2xl font-black text-navy-900">خطة فريق المبيعات</h1>
            <p class="font-bold text-navy-600">EgHub · فرع طنطا · سارية من {{ local_time(now(), 'm/Y') }}</p>
        </div>
        <x-logo :size="40" />
    </header>

    <h2>1. هدفنا</h2>
    <p>نوصّل EgHub لكل مطعم وكافيه ومحل مخبوزات وحلواني في طنطا والغربية. الزبون يبدأ بمنيو QR مجاني، وبعدين يجرّب الباقة الكاملة {{ \App\Services\TenantProvisioner::TRIAL_DAYS }} يوم، وشغلنا إننا نساعده يشوف قيمتها ويشترك. <strong>كل اشتراك مدفوع هو اللي بيتحسب، وعليه العمولة.</strong></p>

    <h2>2. مين بيعمل إيه</h2>
    <table>
        <tr><th style="width:22%">الدور</th><th>المسؤوليات</th></tr>
        <tr><td><strong>مبيعات تليفون</strong>@foreach ($members->filter(fn ($a) => $a->role === 'sales') as $a)<br>{{ $a->name }}@endforeach</td>
            <td>تكلم الأماكن اللي السيستم بيرشّحها وعملاء الإعلانات والمسجلين الجداد · تعرّف بـ EgHub في دقيقتين · تحجز ميعاد زيارة للمندوب · تبعت واتساب بالعرض · تتابع اللي سجّلوا لحد ما يدفعوا · تسجّل كل مكالمة في السيستم.</td></tr>
        <tr><td><strong>مندوب ميداني</strong>@foreach ($members->filter(fn ($a) => $a->role === 'field_sales') as $a)<br>{{ $a->name }}@endforeach</td>
            <td>يروح المواعيد المحجوزة وكمان الأماكن المقترحة في طريقه · يعرض على الموبايل منيو حقيقي · يجمع بيانات المكان وصورة المنيو · يساعد صاحب المكان يسجّل من موبايله في نفس الزيارة · يقفل الاشتراك · يضيف الأماكن الجديدة اللي يلاقيها.</td></tr>
    </table>

    <h2>3. اليوم بالساعة</h2>
    <div class="grid gap-4 sm:grid-cols-2">
        <div class="box">
            <h3>مبيعات تليفون</h3>
            <ul>
                <li><strong>10:00</strong> افتح «الأماكن» واقرا «مكالمات مقترحة» والمتابعات المتأخرة.</li>
                <li><strong>10:15 – 1:00</strong> مكالمات (أحسن وقت لأصحاب المطاعم قبل الزحمة).</li>
                <li><strong>1:00 – 2:00</strong> واتساب بالعروض والمنيو التجريبي للي طلبوا.</li>
                <li><strong>2:00 – 2:30</strong> راحة.</li>
                <li><strong>2:30 – 5:00</strong> مكالمات + متابعة المسجلين الجداد اللي لسه ما دفعوش.</li>
                <li><strong>5:00 – 5:30</strong> ثبّت مواعيد بكرة مع المندوب، وراجع إن كل مكالمة متسجلة.</li>
            </ul>
        </div>
        <div class="box">
            <h3>مندوب ميداني</h3>
            <ul>
                <li><strong>10:00</strong> افتح «زياراتي»: المواعيد والطريق المقترح.</li>
                <li><strong>10:30 – 1:30</strong> زيارات (وقت هادي قبل الغدا).</li>
                <li><strong>1:30 – 2:30</strong> اكتب تقارير الزيارات اللي فاتت وراحة.</li>
                <li><strong>2:30 – 5:30</strong> زيارات + الأماكن المقترحة في نفس المنطقة.</li>
                <li><strong>5:30 – 6:00</strong> قفّل كل التقارير، وضيف الأماكن الجديدة اللي شفتها.</li>
            </ul>
        </div>
    </div>

    <h2>4. إزاي تشتغل على السيستم</h2>
    <ol>
        <li><strong>الأماكن:</strong> لوحة الإدارة ← «الأماكن والمبيعات الميدانية». كل مكان ليه «ترشيح السيستم» من 100 (نوعه، فيه تليفون، قريب من طنطا، وجنبه أماكن تانية). ابدأ بالأعلى.</li>
        <li><strong>المكالمة:</strong> افتح المكان ← دوس «اتصل» ← بعد المكالمة سجّل النتيجة والملاحظات وميعاد المتابعة. مكالمة مش متسجلة = مكالمة ما حصلتش.</li>
        <li><strong>حجز الزيارة:</strong> في نفس الصفحة «احجز زيارة»: المندوب، اليوم والساعة، والعنوان. الزيارة بتظهر عند المندوب في «زياراتي».</li>
        <li><strong>الزيارة:</strong> المندوب يوصل ← «وصلت: سجّل الوصول» (الموبايل بيسجّل مكانك وبعدك عن المكان) ← بعد الزيارة «تقرير الزيارة»: النتيجة، البيانات، صورة المنيو، والخطوة الجاية.</li>
        <li><strong>التسجيل:</strong> لما صاحب المكان يسجّل على eghub.app <u>بنفس رقم الموبايل اللي في السيستم</u>، المكان بيتربط بيكم تلقائياً. لو سجّل برقم تاني، العمولة مش هتتحسب، فاتأكدوا من الرقم.</li>
        <li><strong>الاشتراك:</strong> أول فاتورة مدفوعة بتقفل البيعة وتسجّل مين اللي قفلها ومين ساعد. ده اللي بتتحسب عليه العمولة.</li>
        <li><strong>أداؤك:</strong> كارت «شهري» بيوريك التارجت والمحقق وتقدير فلوس الشهر لحظة بلحظة.</li>
    </ol>

    <h2 class="page-break">5. التارجت</h2>
    <p>أول 3 شهور بنطلع فيهم بالتدريج. من الشهر الرابع التارجت بيتحدد كل شهر على حسب نتايج اللي قبله.</p>
    <table>
        <tr><th>مبيعات تليفون</th><th>الشهر الأول</th><th>الشهر التاني</th><th>الشهر التالت</th></tr>
        @foreach (['calls_per_day' => 'مكالمات في اليوم', 'appointments' => 'مواعيد زيارة في الشهر', 'trials' => 'تسجيلات تجربة من مكالماتها', 'paid' => 'اشتراكات قفلتها بالتليفون'] as $key => $label)
            <tr><td>{{ $label }}</td>@foreach ($rampCaller as $r)<td>{{ $r[$key] ?? 0 }}</td>@endforeach</tr>
        @endforeach
    </table>
    <table>
        <tr><th>مندوب ميداني</th><th>الشهر الأول</th><th>الشهر التاني</th><th>الشهر التالت</th></tr>
        @foreach (['visits' => 'زيارات في الشهر (حوالي 3-4 في اليوم)', 'places' => 'أماكن جديدة بيضيفها للسيستم', 'paid' => 'اشتراكات مدفوعة'] as $key => $label)
            <tr><td>{{ $label }}</td>@foreach ($rampField as $r)<td>{{ $r[$key] ?? 0 }}</td>@endforeach</tr>
        @endforeach
    </table>
    @if ($members->isNotEmpty())
        <h3>تارجت الشهر ده ({{ $month }})</h3>
        <table>
            <tr><th>الاسم</th>@foreach (\App\Models\SalesTarget::METRICS as $l)<th>{{ $l[0] }}</th>@endforeach</tr>
            @foreach ($members as $a)
                <tr><td>{{ $a->name }}</td>@foreach (array_keys(\App\Models\SalesTarget::METRICS) as $key)<td>{{ $targets[$a->id][$key] ?: '—' }}</td>@endforeach</tr>
            @endforeach
        </table>
    @endif

    <h2>6. الفلوس</h2>
    <table>
        <tr><th>البند</th><th>مبيعات تليفون</th><th>مندوب ميداني</th></tr>
        <tr><td>المرتب الأساسي (شهري)</td><td>{{ $m($p['base']['caller']) }} جنيه</td><td>{{ $m($p['base']['field']) }} جنيه</td></tr>
        <tr><td>عمولة اللي قفل البيعة (من أول فاتورة)</td><td colspan="2">{{ $m($p['closer_pct']) }}% من أول فاتورة مدفوعة، أقل حاجة {{ $m($p['closer_min']) }} وأكتر حاجة {{ $m($p['closer_max']) }} جنيه</td></tr>
        <tr><td>عمولة المساعد (التاني في البيعة)</td><td colspan="2">{{ $m($p['assist_pct']) }}% من أول فاتورة، أقل حاجة {{ $m($p['assist_min']) }} وأكتر حاجة {{ $m($p['assist_max']) }} جنيه</td></tr>
        <tr><td>عمولة الاستمرار</td><td colspan="2">{{ $m($p['retention_pct']) }}% من الفاتورة التانية والتالتة للي قفل البيعة (عشان نجيب عملاء بيكمّلوا)</td></tr>
        <tr><td>لكل ميعاد زيارة اتعمل فعلاً</td><td>{{ $m($p['per_visited_appointment']) }} جنيه</td><td>—</td></tr>
        <tr><td>مكافأة التارجت (100%)</td><td>{{ $m($p['bonus']['caller']) }} جنيه على المواعيد اللي اتزارت</td><td>{{ $m($p['bonus']['field']) }} جنيه على الاشتراكات المدفوعة</td></tr>
        <tr><td>من {{ $m($p['bonus_double_at']) }}% من التارجت</td><td colspan="2">المكافأة بتتضاعف</td></tr>
        <tr><td>خط تليفون (شهري)</td><td>{{ $m($p['phone']['caller']) }} جنيه</td><td>{{ $m($p['phone']['field']) }} جنيه</td></tr>
    </table>
    <p><strong>مين «اللي قفل» ومين «المساعد»؟</strong> لو المندوب زار المكان، هو اللي قفل والبنت اللي حجزت الميعاد مساعدة. لو البيعة اتقفلت بالتليفون من غير زيارة، البنت هي اللي قفلت.</p>

    <h3>بدل الانتقالات للمندوب (لكل يوم فيه زيارات)</h3>
    <table>
        <tr><th>المكان</th><th>البدل في اليوم</th></tr>
        <tr><td>جوه طنطا</td><td>{{ $m($p['allowance']['tanta']) }} جنيه</td></tr>
        <tr><td>مراكز الغربية: {{ implode('، ', array_diff(\App\Support\SalesPlan::GHARBIA, ['المحلة'])) }}</td><td>{{ $m($p['allowance']['gharbia']) }} جنيه</td></tr>
        <tr><td>بره الغربية: {{ implode('، ', \App\Support\SalesPlan::OUTSIDE) }} (بموافقة مسبقة)</td><td>{{ $m($p['allowance']['outside']) }} جنيه</td></tr>
    </table>
    <p>اليوم بيتحسب مرة واحدة بأبعد مكان رحته فيه، ولازم الزيارات تكون متسجلة في السيستم بتسجيل الوصول.</p>

    <h3>أمثلة بأسعار النهارده (الباقة الكاملة {{ $m($fullMonthly) }} جنيه في الشهر)</h3>
    <table>
        <tr><th>الحالة</th><th>اللي قفل</th><th>المساعد</th></tr>
        <tr><td>اشترك شهري ({{ $m($fullMonthly) }} جنيه)</td><td>{{ $m($closerMonthly) }} جنيه</td><td>{{ $m($assistMonthly) }} جنيه</td></tr>
        <tr><td>اشترك سنة ({{ $m($fullYear) }} جنيه)</td><td>{{ $m($closerYear) }} جنيه</td><td>{{ $m($assistYear) }} جنيه</td></tr>
        <tr><td>جدّد الشهر التاني والتالت</td><td>{{ $m($retention) }} جنيه كل شهر</td><td>—</td></tr>
    </table>
    <div class="box">
        <p><strong>شهر للمندوب:</strong> {{ $fieldPaid }} اشتراكات شهرية وحقق التارجت، و22 يوم زيارات (18 في طنطا و4 في المراكز):<br>
            {{ $m($p['base']['field']) }} + ({{ $fieldPaid }} × {{ $m($closerMonthly) }}) + {{ $m($p['bonus']['field']) }} مكافأة + (18 × {{ $m($p['allowance']['tanta']) }}) + (4 × {{ $m($p['allowance']['gharbia']) }}) + {{ $m($p['phone']['field']) }} خط = <strong>{{ $m($fieldExample) }} جنيه</strong></p>
        <p><strong>شهر لمبيعات التليفون:</strong> {{ $callerVisits }} ميعاد اتزاروا وحققت التارجت، وساعدت في نفس الـ {{ $fieldPaid }} اشتراكات:<br>
            {{ $m($p['base']['caller']) }} + ({{ $callerVisits }} × {{ $m($p['per_visited_appointment']) }}) + ({{ $fieldPaid }} × {{ $m($assistMonthly) }}) + {{ $m($p['bonus']['caller']) }} مكافأة + {{ $m($p['phone']['caller']) }} خط = <strong>{{ $m($callerExample) }} جنيه</strong></p>
        <p class="text-sm text-navy-600">ولو الاشتراكات سنوية، العمولة بتكبر كتير. عشان كده اعرض السنوي الأول.</p>
    </div>
    <ul>
        <li>العمولة والبدلات بتتصرف يوم {{ (int) $p['payday'] }} من الشهر اللي بعده، بعد مراجعة السيستم.</li>
        <li>لو العميل استرد فلوسه خلال {{ (int) $p['clawback_days'] }} يوم من الدفع، العمولة بتاعته بترجع.</li>
        <li>فترة الاختبار {{ (int) $p['probation_months'] }} شهور.</li>
    </ul>

    <h2 class="page-break">7. القواعد</h2>
    <ol>
        <li>كل مكالمة وكل زيارة تتسجل في السيستم <strong>في نفس اليوم</strong>. اللي مش متسجل مش بيتحسب في التارجت ولا العمولة.</li>
        <li>مفيش وعود بره قايمة الأسعار المنشورة على eghub.app، ومفيش خصم إلا في حدود النسبة المسموحة في السيستم.</li>
        <li>رقم الموبايل اللي صاحب المكان بيسجّل بيه لازم يكون نفس الرقم اللي عندنا.</li>
        <li>تسجيل الوصول بيتعمل وانت عند المكان فعلاً. تسجيل وصول من مكان بعيد بيبان في التقرير.</li>
        <li>الاحترام الأول: لو حد قال «مش مهتم»، اشكره وسجّل السبب. متضايقش حد.</li>
        <li>بيانات العملاء أمانة: متتنقلش بره السيستم ومتتبعتش لحد.</li>
    </ol>

    <h2>8. قبل وأثناء الزيارة</h2>
    <ul>
        <li>☐ الموبايل مشحون والنت شغال، ومعاك المنيو التجريبي مفتوح.</li>
        <li>☐ اعرف اسم صاحب المكان واللي اتقال في المكالمة (من صفحة المكان).</li>
        <li>☐ سجّل الوصول أول ما توصل.</li>
        <li>☐ اسأل: عدد الترابيزات، الفروع، دليفري؟، شغال بسيستم إيه، مين صاحب القرار، إيه أكتر حاجة تاعباه.</li>
        <li>☐ صوّر المنيو وواجهة المحل.</li>
        <li>☐ اعرض المنيو QR على موبايله هو، وخليه يسجّل من موبايله بنفس رقمه.</li>
        <li>☐ اقفل بخطوة واضحة: اشتراك، أو ميعاد إدخال المنيو، أو مكالمة في يوم محدد.</li>
        <li>☐ اكتب التقرير قبل ما تمشي من المنطقة.</li>
    </ul>

    <h2>9. الكلام</h2>
    @foreach ($pitch as $item)
        <div class="box"><strong>{{ $item->title }}</strong><p>{!! nl2br($script($item->body)) !!}</p></div>
    @endforeach
    @if ($objections->isNotEmpty())
        <h3>الاعتراضات والرد عليها</h3>
        <table>
            <tr><th style="width:30%">لما يقول</th><th>نرد</th></tr>
            @foreach ($objections as $item)
                <tr><td>{{ $item->title }}</td><td>{!! nl2br($script($item->body)) !!}</td></tr>
            @endforeach
        </table>
    @endif

    <h2>10. المتابعة</h2>
    <ul>
        <li><strong>اجتماع كل أحد 10 الصبح (نص ساعة):</strong> أرقام الأسبوع من صفحة «فريق المبيعات»، أحسن زيارة، أصعب اعتراض، ومناطق الأسبوع الجاي.</li>
        <li><strong>آخر كل شهر:</strong> مراجعة التارجت والعمولة، وتحديد تارجت الشهر الجاي.</li>
        <li>اللي يحقق أقل من {{ (int) $p['review_below'] }}% من التارجت شهرين ورا بعض، بنقعد معاه نراجع الخطة.</li>
    </ul>

    <h2>11. الموافقة</h2>
    <p>قريت الخطة دي وفهمتها وموافق عليها، وعارف إن الأرقام ممكن تتعدل بإخطار مكتوب قبل بداية الشهر.</p>
    <table class="mt-4">
        <tr><th>الاسم</th><th>الدور</th><th>التوقيع</th><th>التاريخ</th></tr>
        @forelse ($members as $a)
            <tr><td style="height:3rem">{{ $a->name }}</td><td>{{ $a->roleName() }}</td><td></td><td></td></tr>
        @empty
            <tr><td style="height:3rem"></td><td>مبيعات تليفون</td><td></td><td></td></tr>
            <tr><td style="height:3rem"></td><td>مندوب ميداني</td><td></td><td></td></tr>
        @endforelse
        <tr><td style="height:3rem">عن الشركة</td><td>EgHub</td><td></td><td></td></tr>
    </table>
</main>
@endsection
