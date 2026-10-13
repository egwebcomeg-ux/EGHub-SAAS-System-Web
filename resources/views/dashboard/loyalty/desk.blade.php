@extends('layouts.dashboard')
@section('title', __('Loyalty card'))

@section('content')
<div class="mb-4 flex flex-wrap items-center justify-between gap-3">
    <h1 class="page-title">{{ __('Loyalty card') }} · {{ __('Counter') }}</h1>
    @can('loyalty.manage')<a href="{{ route('dash.loyalty.setup') }}" class="btn-ghost">{{ __('Program settings') }}</a>@endcan
</div>

@if (!$program)
    <div class="card max-w-lg">
        <p class="text-navy-700">{{ __('No active loyalty program yet.') }}</p>
        @can('loyalty.manage')<a href="{{ route('dash.loyalty.setup') }}" class="btn-primary mt-3">{{ __('Set up the loyalty card') }}</a>@endcan
    </div>
@else
    <div class="grid gap-4 lg:grid-cols-5">
        <div class="space-y-4 lg:col-span-2">
            <form class="card space-y-3" data-lookup>
                <label class="field-label" for="q">{{ __('Guest mobile or scanned card') }}</label>
                <div class="flex gap-2">
                    <input class="field text-lg" id="q" name="q" value="{{ $q }}" autofocus dir="ltr" placeholder="01xxxxxxxxx" autocomplete="off">
                    <button class="btn-primary">{{ __('Find') }}</button>
                </div>
                <button type="button" class="btn-ghost hidden w-full" data-scan>📷 {{ __('Scan the guest’s QR') }}</button>
                <video class="hidden w-full rounded-xl" data-video playsinline muted></video>
            </form>

            @if ($q !== '' && !$card)
                <form method="post" action="{{ route('dash.loyalty.enroll') }}" class="card space-y-3">
                    @csrf
                    <p class="font-semibold">{{ __('No card for this number. Create one?') }}</p>
                    <input class="field" name="phone" value="{{ $q }}" required dir="ltr">
                    <input class="field" name="name" placeholder="{{ __('Guest name (optional)') }}" maxlength="100">
                    <button class="btn-primary w-full">{{ __('Create card') }}</button>
                </form>
            @endif
        </div>

        @if ($card)
            <div class="space-y-4 lg:col-span-3">
                <section class="card">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <div class="text-xl font-extrabold">{{ $card->customer->name ?: __('Guest') }}</div>
                            <div class="muted" dir="ltr">{{ $card->customer->phone }}</div>
                            <div class="mt-1 text-sm">{{ __(':n visits', ['n' => $card->customer->visits_count]) }} · {{ money($card->customer->total_spent) }} {{ __('EGP') }}</div>
                        </div>
                        <div class="text-end">
                            <div class="muted">{{ $program->type === 'points' ? __('Points') : __('Stamps') }}</div>
                            <div class="text-4xl font-extrabold text-brand-700">{{ $card->stamps }}@unless ($program->type === 'points')<span class="text-lg text-navy-500">/{{ $program->stamps_required }}</span>@endunless</div>
                        </div>
                    </div>

                    <form method="post" action="{{ route('dash.loyalty.stamp', $card) }}" class="mt-4 flex flex-wrap items-center gap-2">
                        @csrf
                        <input class="field w-20 text-center" name="count" type="number" min="1" max="20" value="1">
                        <input class="field flex-1" name="note" maxlength="100" placeholder="{{ __('Note (optional)') }}">
                        <button class="btn-primary">{{ $program->type === 'points' ? __('Add points') : __('Add stamp') }}</button>
                    </form>
                </section>

                <section class="card">
                    <h2 class="section-title mb-3">{{ __('Rewards to use') }}</h2>
                    @forelse ($card->availableRewards as $r)
                        <form method="post" action="{{ route('dash.loyalty.redeem', $r) }}" class="mb-2 flex items-center justify-between gap-3 rounded-xl bg-emerald-50 p-3">
                            @csrf
                            <span class="font-bold text-emerald-900">🎁 {{ $r->label }}</span>
                            <button class="btn-primary min-h-9 px-3" onclick="return confirm(@js(__('Give the guest: :label?', ['label' => $r->label])))">{{ __('Redeem') }}</button>
                        </form>
                    @empty
                        <p class="muted">{{ __('No rewards yet.') }}</p>
                    @endforelse
                    @if ($program->type === 'points')
                        @foreach ($milestones as $i => $m)
                            <form method="post" action="{{ route('dash.loyalty.claim', $card) }}" class="mt-2 flex items-center justify-between gap-3 rounded-xl bg-navy-50 p-3">
                                @csrf<input type="hidden" name="milestone" value="{{ $i }}">
                                <span>{{ $m['label'] }} · {{ __(':n points', ['n' => $m['at']]) }}</span>
                                <button class="btn-ghost min-h-9 px-3" @disabled($card->stamps < $m['at'])>{{ __('Exchange') }}</button>
                            </form>
                        @endforeach
                    @endif
                </section>

                @if ($card->transactions->isNotEmpty())
                    <section class="card">
                        <h2 class="section-title mb-2">{{ __('History') }}</h2>
                        <ul class="space-y-1 text-sm">
                            @foreach ($card->transactions as $t)
                                @php($typeLabel = ['stamp' => __('Stamp'), 'points' => __('Points'), 'reward' => __('Reward'), 'redeem' => __('Redeemed'), 'reverse' => __('Reversed'), 'expire' => __('Expired')][$t->type] ?? $t->type)
                                <li class="flex justify-between gap-3"><span>{{ $typeLabel }} {{ $t->amount ? ($t->amount > 0 ? '+' : '') . $t->amount : '' }} {{ $t->note }}</span><span class="muted">{{ local_time($t->created_at, 'm/d h:i A') }}</span></li>
                            @endforeach
                        </ul>
                    </section>
                @endif
            </div>
        @endif
    </div>
@endif

<script>
    // Camera scan where the browser supports it (Chrome on Android, most POS tablets); typing the phone always works.
    (() => {
        if (!('BarcodeDetector' in window)) return;
        const btn = document.querySelector('[data-scan]');
        const video = document.querySelector('[data-video]');
        btn?.classList.remove('hidden');
        btn?.addEventListener('click', async () => {
            const stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } });
            video.srcObject = stream;
            video.classList.remove('hidden');
            await video.play();
            const detector = new BarcodeDetector({ formats: ['qr_code'] });
            const tick = async () => {
                const codes = await detector.detect(video).catch(() => []);
                if (codes.length) {
                    stream.getTracks().forEach((t) => t.stop());
                    document.getElementById('q').value = codes[0].rawValue;
                    document.querySelector('[data-lookup]').submit();
                } else requestAnimationFrame(tick);
            };
            tick();
        });
    })();
</script>
@endsection
