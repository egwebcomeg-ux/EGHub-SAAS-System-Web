@extends('layouts.dashboard')
@section('title', __('Pair a TV'))

@section('content')
<x-dash.page-header :title="__('Pair a TV')" :help="__('Type the 6-digit code the TV shows on :url and choose which screen it is.', ['url' => preg_replace('#^https?://#', '', platform_url(null, 'tv'))])" />

<div class="grid max-w-4xl gap-6 lg:grid-cols-5">
    <form method="post" action="{{ route('dash.tv.pair.store') }}" class="card grid gap-4 lg:col-span-3" data-pair-form>
        @csrf
        <div>
            <label class="field-label" for="pair-code">{{ __('Code on the TV') }}</label>
            <input id="pair-code" class="field text-center text-3xl font-extrabold tracking-[.3em]" name="code" value="{{ old('code', $code) }}" required
                inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]{6,7}" maxlength="7" placeholder="000000" dir="ltr" @if (! $code) autofocus @endif>
        </div>
        @if ($screens->isEmpty())
            <div class="empty-state">{{ __('Add a screen first, then pair a TV with it.') }} <a class="font-bold text-brand-700" href="{{ route('dash.devices', ['tab' => 'tv']) }}#add-tv">{{ __('Add screen') }}</a></div>
        @else
            <fieldset>
                <legend class="field-label">{{ __('Which screen is it?') }}</legend>
                <div class="grid gap-2 sm:grid-cols-2">
                    @foreach ($screens as $s)
                        <label class="flex min-h-12 cursor-pointer items-center gap-3 rounded-xl border border-navy-100 p-3 has-[:checked]:border-brand-500 has-[:checked]:bg-brand-50">
                            <input type="radio" name="screen_id" value="{{ $s->id }}" @checked((int) old('screen_id', $selected) === $s->id) class="size-4 accent-brand-600" required>
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
    </form>

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
@endsection
