@extends('layouts.base')
@section('title', tr($program->title, $program->title_en))
@section('body_class', 'bg-navy-50 text-navy-900')

@push('head')
<meta name="theme-color" content="{{ $program->card_design['bg'] ?? '#B5451B' }}">
<meta name="apple-mobile-web-app-capable" content="yes">
@endpush

@section('body')
@php($points = $program->type === 'points')
<main class="mx-auto max-w-md px-4 py-6">
    <div class="mb-4 flex justify-center">
        <div class="inline-flex rounded-full bg-white p-1 shadow-sm" role="tablist">
            <button type="button" class="rounded-full px-5 py-1.5 text-sm font-bold" data-side="front" aria-selected="true">{{ __('Front side') }}</button>
            <button type="button" class="rounded-full px-5 py-1.5 text-sm font-bold" data-side="back">{{ __('Back side') }}</button>
        </div>
    </div>

    <section data-face="front">
        @include('partials.loyalty-card', ['stamps' => $card->stamps, 'name' => $customer->name ?: null])

        @if ($card->availableRewards->isNotEmpty())
            <div class="mt-5 space-y-2">
                @foreach ($card->availableRewards as $r)
                    <div class="flex items-center gap-3 rounded-2xl bg-emerald-50 p-4 text-emerald-900 ring-1 ring-emerald-200">
                        <span class="text-2xl">🎁</span>
                        <div><div class="font-extrabold">{{ $r->label }}</div><div class="text-sm">{{ __('Show this card at the counter to use it.') }}</div></div>
                    </div>
                @endforeach
            </div>
        @endif
        <p class="mt-5 text-center text-sm text-navy-500">{{ __('Show the QR to the cashier with every order to collect your stamps.') }}</p>
        <p class="mt-1 text-center text-xs text-navy-500">{{ __('Tip: add this page to your home screen to keep the card one tap away.') }}</p>
    </section>

    <section data-face="back" class="hidden space-y-4">
        <div class="card">
            <h2 class="section-title mb-3">{{ __('Rewards') }}</h2>
            <ul class="space-y-2">
                @foreach ($milestones as $m)
                    <li class="flex items-center justify-between gap-3">
                        <span class="flex items-center gap-2">🎁 {{ $m['label'] }}</span>
                        <span class="badge bg-navy-100 text-navy-700">{{ $points ? __(':n points', ['n' => $m['at']]) : __('Stamp :n', ['n' => $m['at']]) }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
        <div class="card text-sm text-navy-700">
            <h2 class="section-title mb-2">{{ __('How it works') }}</h2>
            <p>
                @if ($program->type === 'visits')
                    {{ __('One stamp per visit') }}@if ($program->min_order > 0) {{ __('(orders from :amount)', ['amount' => money($program->min_order)]) }}@endif.
                @elseif ($program->type === 'items')
                    {{ __('One stamp for every qualifying item you order.') }}
                @else
                    {{ __(':n points for every 1 EGP you spend.', ['n' => money($program->points_per_egp)]) }}
                @endif
            </p>
            @if ($program->stamps_expire_days)<p class="mt-1">{{ __('Stamps expire :days days after your last visit.', ['days' => $program->stamps_expire_days]) }}</p>@endif
        </div>
        @if ($card->transactions->isNotEmpty())
            <div class="card">
                <h2 class="section-title mb-2">{{ __('History') }}</h2>
                <ul class="space-y-1 text-sm">
                    @foreach ($card->transactions as $t)
                        <li class="flex justify-between">
                            <span>{{ $t->type === 'redeem' ? '🎁 ' . $t->note : ($points ? __('+:n points', ['n' => $t->amount]) : __('+:n stamps', ['n' => $t->amount])) }}</span>
                            <span class="text-navy-500">{{ local_time($t->created_at, 'Y/m/d') }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
        @if ($branches->isNotEmpty())
            <div class="card text-sm">
                @foreach ($branches as $b)
                    <div class="py-1"><b>{{ tr($b->name, $b->name_en) }}</b>@if ($b->address) · {{ $b->address }}@endif</div>
                @endforeach
            </div>
        @endif
    </section>

    <a href="{{ route('menu.show') }}" class="btn-ghost mt-6 w-full">{{ __('Open the menu') }}</a>
</main>
<script>
    document.querySelectorAll('[data-side]').forEach((b) => b.addEventListener('click', () => {
        document.querySelectorAll('[data-side]').forEach((x) => {
            const on = x === b;
            x.setAttribute('aria-selected', on);
            x.classList.toggle('bg-navy-800', on);
            x.classList.toggle('text-white', on);
        });
        document.querySelectorAll('[data-face]').forEach((f) => f.classList.toggle('hidden', f.dataset.face !== b.dataset.side));
    }));
    document.querySelector('[data-side="front"]').click();
</script>
@endsection
