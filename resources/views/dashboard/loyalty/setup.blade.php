@extends('layouts.dashboard')
@section('title', __('Loyalty card'))

@section('content')
@php
    $design = $program->card_design ?? [];
    $q = $program->qualifying_items ?? [];
    $milestones = array_values($program->milestones ?? []);
@endphp
<div class="mb-4 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h1 class="page-title">{{ __('Loyalty card') }}</h1>
        <p class="muted">{{ __('A digital stamp card on the guest’s phone. Stamps come only from paid orders or from your staff.') }}</p>
    </div>
    <div class="flex gap-2">
        <a href="{{ route('dash.vouchers') }}" class="btn-ghost">{{ __('Vouchers') }}</a>
        @if ($program->exists)<a href="{{ tenant()->url('card') }}" target="_blank" rel="noopener" class="btn-ghost">{{ __('Open the guest page') }}</a>@endif
        @can('loyalty.stamp')<a href="{{ route('dash.loyalty.desk') }}" class="btn-primary">{{ __('Counter: stamp a card') }}</a>@endcan
    </div>
</div>

@if ($stats)
    <div class="mb-4 grid gap-4 sm:grid-cols-3">
        <div class="card"><div class="muted">{{ __('Cards') }}</div><div class="text-2xl font-extrabold">{{ $stats['cards'] }}</div></div>
        <div class="card"><div class="muted">{{ __('Rewards reached') }}</div><div class="text-2xl font-extrabold">{{ $stats['rewards'] }}</div></div>
        <div class="card"><div class="muted">{{ __('Rewards redeemed') }}</div><div class="text-2xl font-extrabold">{{ $stats['redeemed'] }}</div></div>
    </div>
@endif

<form method="post" action="{{ route('dash.loyalty.update') }}" class="grid gap-4 lg:grid-cols-5">
    @csrf
    <div class="space-y-4 lg:col-span-3">
        @if ($locked)
            <p class="rounded-xl bg-amber-50 p-3 text-sm font-semibold text-amber-800">{{ __('Guests already hold cards: the card size, type and rewards are locked. You can still change the name, colors and expiry.') }}</p>
        @endif

        <section class="card space-y-4">
            <h2 class="section-title">{{ __('How guests earn') }}</h2>
            <div class="grid gap-2 sm:grid-cols-3">
                @foreach (['visits' => [__('Per visit'), __('1 stamp per visit (max 1 a day)')], 'items' => [__('Per item'), __('e.g. every coffee = 1 stamp')], 'points' => [__('Points'), __('Points for every 1 EGP')]] as $type => [$label, $hint])
                    @continue($type === 'points' && !$points)
                    <label class="cursor-pointer rounded-xl border border-navy-100 p-3 has-[:checked]:border-brand-500 has-[:checked]:bg-brand-50">
                        <input type="radio" name="type" value="{{ $type }}" class="sr-only" @checked(old('type', $program->type) === $type) @disabled($locked && $program->type !== $type) data-type-radio>
                        <span class="block font-bold">{{ $label }}</span>
                        <span class="block text-xs text-navy-500">{{ $hint }}</span>
                    </label>
                @endforeach
            </div>
            <div class="grid gap-3 sm:grid-cols-3">
                <div data-show="visits items">
                    <label class="field-label">{{ __('Stamps on the card') }}</label>
                    <input class="field" name="stamps_required" type="number" min="2" max="30" value="{{ old('stamps_required', $program->stamps_required) }}" @readonly($locked)>
                </div>
                <div data-show="visits">
                    <label class="field-label">{{ __('Minimum order for a stamp (EGP)') }}</label>
                    <input class="field" name="min_order" type="number" min="0" step="1" value="{{ old('min_order', (float) $program->min_order) }}">
                </div>
                <div data-show="points">
                    <label class="field-label">{{ __('Points per 1 EGP') }}</label>
                    <input class="field" name="points_per_egp" type="number" min="0.01" step="0.01" value="{{ old('points_per_egp', (float) ($program->points_per_egp ?: 1)) }}">
                </div>
                <div>
                    <label class="field-label">{{ __('Stamps expire after (days, optional)') }}</label>
                    <input class="field" name="stamps_expire_days" type="number" min="7" max="730" value="{{ old('stamps_expire_days', $program->stamps_expire_days) }}">
                </div>
            </div>
            <div data-show="items" class="space-y-2">
                <label class="field-label">{{ __('Which items earn a stamp?') }}</label>
                <div class="flex flex-wrap gap-x-4 gap-y-1">
                    @foreach ($categories as $c)
                        <label class="flex items-center gap-1.5 text-sm font-semibold"><input type="checkbox" name="categories[]" value="{{ $c->id }}" @checked(in_array($c->id, $q['categories'] ?? [])) class="size-4 accent-brand-600"> {{ $c->name }} <span class="font-normal text-navy-500">({{ __('whole category') }})</span></label>
                    @endforeach
                </div>
                <details class="text-sm"><summary class="cursor-pointer text-navy-500">{{ __('Or pick single items') }}</summary>
                    <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1">
                        @foreach ($items as $i)
                            <label class="flex items-center gap-1.5"><input type="checkbox" name="items[]" value="{{ $i->id }}" @checked(in_array($i->id, $q['items'] ?? [])) class="size-4 accent-brand-600"> {{ $i->name }}</label>
                        @endforeach
                    </div>
                </details>
            </div>
        </section>

        <section class="card space-y-3">
            <h2 class="section-title">{{ __('Rewards') }}</h2>
            <div>
                <label class="field-label" data-show="visits items">{{ __('Reward for a full card') }}</label>
                <input class="field" name="reward_label" required maxlength="80" value="{{ old('reward_label', $program->reward_label) }}" placeholder="{{ __('e.g. A free coffee') }}" @readonly($locked)>
            </div>
            <p class="muted">{{ __('Optional rewards along the way, e.g. 30% off at stamp 5. For points: the points each reward costs.') }}</p>
            @for ($i = 0; $i < 4; $i++)
                <div class="grid grid-cols-[6rem_1fr] gap-2">
                    <input class="field" name="milestones[{{ $i }}][at]" type="number" min="1" value="{{ $milestones[$i]['at'] ?? '' }}" placeholder="{{ __('At') }}" @readonly($locked)>
                    <input class="field" name="milestones[{{ $i }}][label]" maxlength="80" value="{{ $milestones[$i]['label'] ?? '' }}" placeholder="{{ __('e.g. 30% off your order') }}" @readonly($locked)>
                </div>
            @endfor
        </section>

        <section class="card grid gap-3 sm:grid-cols-2">
            <h2 class="section-title sm:col-span-2">{{ __('Card look') }}</h2>
            <div><label class="field-label">{{ __('Card name (Arabic)') }}</label><input class="field" name="title" required maxlength="60" value="{{ old('title', $program->title) }}"></div>
            <div><label class="field-label">{{ __('Card name (English)') }}</label><input class="field" name="title_en" maxlength="60" value="{{ old('title_en', $program->title_en) }}" dir="ltr"></div>
            <div><label class="field-label">{{ __('Card color') }}</label><input class="field h-11 p-1" type="color" name="design[bg]" value="{{ $design['bg'] ?? '#B5451B' }}"></div>
            <div><label class="field-label">{{ __('Stamps band color') }}</label><input class="field h-11 p-1" type="color" name="design[stamp_bg]" value="{{ $design['stamp_bg'] ?? '#7A1F2B' }}"></div>
            <div class="sm:col-span-2">
                <label class="field-label">{{ __('Stamp icon') }}</label>
                <div class="flex flex-wrap gap-2">
                    @foreach ($icons as $icon)
                        <label class="cursor-pointer rounded-xl border border-navy-100 p-2 has-[:checked]:border-brand-500 has-[:checked]:bg-brand-50">
                            <input type="radio" name="design[icon]" value="{{ $icon }}" class="sr-only" @checked(($design['icon'] ?? 'cup') === $icon)>
                            @if ($icon === 'logo')<span class="block px-1 text-xs font-bold">{{ __('Logo') }}</span>@else<x-stamp-icon :icon="$icon" class="size-7 text-navy-800" />@endif
                        </label>
                    @endforeach
                </div>
            </div>
            <label class="flex items-center gap-2 text-sm font-semibold sm:col-span-2"><input type="checkbox" name="is_active" value="1" @checked($program->is_active ?? true) class="size-4 accent-brand-600"> {{ __('Program is active') }}</label>
        </section>
        <button class="btn-primary">{{ __('Save loyalty program') }}</button>
    </div>

    <div class="lg:col-span-2">
        <div class="sticky top-4 space-y-3">
            <div class="muted text-center">{{ __('Preview') }}</div>
            @include('partials.loyalty-card', ['stamps' => min(3, max(0, (int) $program->stamps_required - 1)), 'settings' => \App\Services\Settings::all(),
                'milestones' => app(\App\Services\Loyalty\LoyaltyService::class)->milestones($program->exists ? $program : $program->fill(['milestones' => $milestones]))])
        </div>
    </div>
</form>

@if ($locked)
    <form method="post" action="{{ route('dash.loyalty.restart') }}" class="mt-6" onsubmit="return confirm(@js(__('Start a new program? Guests get a new empty card; old rewards stay usable.')))">
        @csrf<button class="btn-ghost">{{ __('Start a new program with other rules') }}</button>
    </form>
@endif

<script>
    (() => {
        const sync = () => {
            const type = document.querySelector('[data-type-radio]:checked')?.value || 'visits';
            document.querySelectorAll('[data-show]').forEach((el) => el.classList.toggle('hidden', !el.dataset.show.split(' ').includes(type)));
        };
        document.querySelectorAll('[data-type-radio]').forEach((r) => r.addEventListener('change', sync));
        sync();
    })();
</script>
@endsection
