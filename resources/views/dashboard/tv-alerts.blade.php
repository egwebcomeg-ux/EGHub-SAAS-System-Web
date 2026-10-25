@extends('layouts.dashboard')
@section('title', __('Urgent message') . ' · ' . __('TV screens'))

@section('content')
<x-dash.page-header :title="__('Urgent message')" :help="__('Type one line and it shows on the screens you tick, on top of what they play, within about :s seconds.', ['s' => $poll + 2])">
    <x-slot:actions>
        <a href="{{ route('dash.devices', ['tab' => 'tv']) }}" class="btn-ghost">{{ __('All screens') }}</a>
    </x-slot:actions>
</x-dash.page-header>

<div class="grid max-w-5xl gap-6 lg:grid-cols-5">
    <div class="space-y-4 lg:col-span-3">
        @if ($alerts->isNotEmpty())
            <section class="card space-y-3 border-red-200" id="on-now" data-alerts-on>
                <h2 class="section-title">{{ __('On the screens now') }}</h2>
                @foreach ($alerts as $a)
                    <div class="rounded-xl bg-red-50 p-3" data-alert="{{ $a->id }}">
                        <p class="text-base font-bold text-navy-900" dir="auto">{{ $a->text }}</p>
                        <p class="mt-1 text-xs text-navy-600">
                            {{ $a->style === 'full' ? __('Whole screen') : __('Band at the top') }} ·
                            {{ $a->ends_at ? __('Until :time', ['time' => local_time($a->ends_at, 'h:i A')]) : __('Until you clear it') }} ·
                            {{ trans_choice(':count screen|:count screens', count($a->screen_ids ?? [])) }}
                        </p>
                        <form method="post" action="{{ route('dash.tv.alerts.clear', $a) }}" class="mt-2">@csrf<button class="btn-primary min-h-11 w-full justify-center bg-red-600 sm:w-auto" data-clear-alert>{{ __('Clear it now') }}</button></form>
                    </div>
                @endforeach
            </section>
        @endif

        <form method="post" action="{{ route('dash.tv.alerts.store') }}" class="card space-y-4" data-alert-form>
            @csrf
            <div>
                <label class="field-label" for="alert-text">{{ __('The message') }}</label>
                <textarea class="field min-h-24 text-lg" id="alert-text" name="text" maxlength="200" required placeholder="{{ __('e.g. Order 42 is ready · The kitchen closes at 11 pm · We are out of mango today') }}">{{ old('text') }}</textarea>
                @error('text')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
            </div>
            <fieldset>
                <legend class="field-label">{{ __('How it shows') }}</legend>
                <div class="grid grid-cols-2 gap-2">
                    @foreach (['banner' => __('Band at the top'), 'full' => __('Whole screen')] as $style => $label)
                        <label class="flex min-h-12 cursor-pointer items-center gap-2 rounded-xl border border-navy-100 px-3 text-sm font-semibold has-[:checked]:border-brand-500 has-[:checked]:bg-brand-50">
                            <input type="radio" name="style" value="{{ $style }}" @checked(old('style', 'banner') === $style) class="size-5 accent-brand-600">
                            <span class="flex h-7 w-11 shrink-0 flex-col overflow-hidden rounded border border-navy-300 bg-navy-100" aria-hidden="true"><span class="{{ $style === 'full' ? 'h-full' : 'h-2' }} bg-brand-500"></span></span>
                            {{ $label }}
                        </label>
                    @endforeach
                </div>
            </fieldset>
            <div>
                <label class="field-label" for="alert-minutes">{{ __('For how long') }}</label>
                <select class="field" id="alert-minutes" name="minutes">
                    @foreach (\App\Models\TvAlert::DURATIONS as $m)
                        <option value="{{ $m }}" @selected((int) old('minutes', 15) === $m)>{{ $m === 0 ? __('Until I clear it') : ($m < 60 ? __(':n minutes', ['n' => $m]) : trans_choice(':count hour|:count hours', intdiv($m, 60))) }}</option>
                    @endforeach
                </select>
            </div>
            <fieldset>
                <legend class="field-label">{{ __('On which screens') }}</legend>
                @if ($screens->isEmpty())
                    <p class="empty-state">{{ __('No screens yet. Add your first TV screen.') }}</p>
                @else
                    @include('dashboard.partials.tv-screen-picker', ['screens' => $screens, 'checked' => old('screen_ids', $screens->pluck('id')->all())])
                @endif
                @error('screen_ids')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
            </fieldset>
            <button class="btn-primary min-h-12 w-full justify-center text-base" @disabled($screens->isEmpty())>{{ __('Send to the screens') }}</button>
        </form>
    </div>
    <aside class="space-y-3 text-sm text-navy-600 lg:col-span-2">
        <div class="card space-y-2">
            <h2 class="section-title">{{ __('Good to know') }}</h2>
            <ul class="list-disc space-y-1 ps-5">
                <li>{{ __('Each screen checks for a message every :s seconds, so it shows within about :t seconds on a screen with internet.', ['s' => $poll, 't' => $poll + 2]) }}</li>
                <li>{{ __('A screen without internet shows it when the line is back. A timed message still ends on time.') }}</li>
                <li>{{ __('The newest message wins when two are on the same screen.') }}</li>
                <li>{{ __('Send it from your phone: this page fits a phone screen.') }}</li>
            </ul>
        </div>
    </aside>
</div>
@endsection
