@extends('layouts.dashboard')
@section('title', __('Pair a TV'))

@section('content')
@php
    $devices = \App\Support\ScreenDevices::all();
    $picked = old('device_type', $type);
@endphp
<x-dash.page-header :title="__('Pair a TV')" :help="__('Choose your kind of screen, follow its steps, then type the 6-digit code the TV shows.')">
    <x-slot:actions>
        <a href="{{ route('screens.supported') }}" target="_blank" rel="noopener" class="btn-ghost">{{ __('All supported screens') }}</a>
    </x-slot:actions>
</x-dash.page-header>

<form method="post" action="{{ route('dash.tv.pair.store') }}" class="grid max-w-5xl gap-6" data-pair-form>
    @csrf
    <section class="card space-y-4" id="pair-type">
        <h2 class="section-title"><span class="text-brand-600">1.</span> {{ __('Screen type') }}</h2>
        @include('dashboard.partials.tv-device-picker', ['type' => $type, 'suggested' => $suggested])
    </section>

    <section class="card space-y-4" id="pair-shape">
        <h2 class="section-title"><span class="text-brand-600">2.</span> {{ __('Screen size and orientation') }}</h2>
        @include('dashboard.partials.tv-shape-fields', ['screen' => $screens->firstWhere('id', $selected)])
    </section>

    <section class="card space-y-4" id="pair-steps">
        <h2 class="section-title"><span class="text-brand-600">3.</span> {{ __('Set up the screen') }}</h2>
        <p class="empty-state" data-guide-empty @if ($picked && isset($devices[$picked])) hidden @endif>{{ __('Choose your screen type above and its steps show here.') }}</p>
        <p class="empty-state" data-guide-for="{{ \App\Support\ScreenDevices::UNKNOWN }}" hidden>{{ __('Use the tips above to tell what your screen is, then pick it: its steps show here.') }}</p>
        @foreach ($devices as $key => $device)
            <div data-guide-for="{{ $key }}" @if ($picked !== $key) hidden @endif>
                @include('partials.screen-device-guide', ['device' => $device, 'heading' => 'h3'])
            </div>
        @endforeach
    </section>

    <div class="grid gap-6 lg:grid-cols-5">
        <section class="card grid gap-4 lg:col-span-3" id="pair-code-step">
            <h2 class="section-title"><span class="text-brand-600">4.</span> {{ __('Type the code') }}</h2>
            <div>
                <label class="field-label" for="pair-code">{{ __('Code on the TV') }}</label>
                <input id="pair-code" class="field text-center text-3xl font-extrabold tracking-[.3em]" name="code" value="{{ old('code', $code) }}" required
                    inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]{6,7}" maxlength="7" placeholder="000000" dir="ltr">
                @error('code')<p class="mt-1 text-sm font-semibold text-red-600">{{ $message }}</p>@enderror
            </div>
            @if ($screens->isEmpty())
                <div class="empty-state">{{ __('Add a screen first, then pair a TV with it.') }} <a class="font-bold text-brand-700" href="{{ route('dash.devices', ['tab' => 'tv']) }}#add-tv">{{ __('Add screen') }}</a></div>
            @else
                <fieldset>
                    <legend class="field-label">{{ __('Which screen is it?') }}</legend>
                    <div class="grid gap-2 sm:grid-cols-2">
                        @foreach ($screens as $s)
                            <label class="flex min-h-12 cursor-pointer items-center gap-3 rounded-xl border border-navy-100 p-3 has-[:checked]:border-brand-500 has-[:checked]:bg-brand-50">
                                <input type="radio" name="screen_id" value="{{ $s->id }}" @checked((int) old('screen_id', $selected) === $s->id) class="size-4 accent-brand-600" required
                                    data-screen-shape="{{ json_encode(['shape' => \App\Support\ScreenShape::shapeOf($s), 'turn' => \App\Support\ScreenShape::turnOf($s), 'screen_size' => $s->screen_size, 'viewing_distance' => $s->viewing_distance ?? 'mid', 'manual' => (bool) $s->text_scale, 'percent' => \App\Support\ScreenShape::percent($s)]) }}">
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate font-bold">{{ $s->name }}</span>
                                    <span class="block text-xs text-navy-500">{{ $s->isPaired() ? __('Has a TV: the new one replaces it') : __('No TV yet') }}</span>
                                </span>
                            </label>
                        @endforeach
                    </div>
                </fieldset>
                <button class="btn-primary justify-center">{{ __('Pair') }}</button>
            @endif
        </section>

        <aside class="space-y-4 lg:col-span-2">
            <section class="card space-y-2 text-sm text-navy-700">
                <h2 class="section-title">{{ __('On the TV') }}</h2>
                <ol class="list-decimal space-y-1 ps-5">
                    <li>{!! __('Open the browser (Samsung, LG, Android TV, Fire TV or a computer) or the EgHub app and go to :url', ['url' => '<b dir="ltr">'.e(preg_replace('#^https?://#', '', platform_url(null, 'tv'))).'</b>']) !!}</li>
                    <li>{{ __('A big code and a QR show. The code changes every 10 minutes.') }}</li>
                    <li>{{ __('Scanning the QR with your phone opens this page with the code filled in.') }}</li>
                </ol>
            </section>
            @include('dashboard.partials.tv-limit', ['limit' => $limit, 'paired' => $paired])
        </aside>
    </div>
</form>

<script>
    // picking another screen in step 4 brings its own size and orientation into step 2
    document.querySelectorAll('[data-screen-shape]').forEach((radio) => radio.addEventListener('change', () => {
        const box = document.querySelector('[data-shape-fields]');
        if (!box || !radio.checked) {
            return;
        }
        const shape = JSON.parse(radio.dataset.screenShape);
        ['shape', 'turn', 'screen_size', 'viewing_distance'].forEach((name) => {
            box.querySelectorAll(`[name="${name}"]`).forEach((input) => { input.checked = input.value === String(shape[name] ?? ''); });
        });
        box.querySelector('[data-scale-manual]').checked = shape.manual;
        box.querySelector('[data-scale-range]').value = shape.percent;
        box.dispatchEvent(new Event('change'));
    }));
</script>
@endsection
