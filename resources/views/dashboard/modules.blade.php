@extends('layouts.dashboard')
@section('title', __('Your modules'))

@section('content')
@php
    $rights = \App\Services\Entitlements::current();
    $trialing = $coverage['trialing'];
@endphp
<div class="mx-auto max-w-6xl" data-modules-page>
    <header class="mb-5">
        @if ($welcome)
            <p class="mb-1 text-sm font-bold text-brand-700">{{ __('One quick step before you start') }}</p>
            <h1 class="page-title">{{ __('What will you use at :name?', ['name' => tenant()->name]) }}</h1>
        @else
            <h1 class="page-title">{{ __('Your modules') }}</h1>
        @endif
        <p class="muted mt-1 max-w-2xl">{{ __('Tick what you work with and we put it first in your dashboard. Nothing is locked or removed, and you can change it any time from Settings.') }}</p>
        @unless ($hasChoice)
            <p class="mt-2 inline-flex items-center gap-2 rounded-full bg-navy-50 px-3 py-1 text-xs font-semibold text-navy-600">
                <x-landing.icon name="sparkles" class="size-4" />{{ __('We ticked what usually suits a :type. Change it as you like.', ['type' => \App\Support\BusinessType::label($businessType)]) }}
            </p>
        @endunless
    </header>

    <form method="post" action="{{ route('dash.modules.update') }}" data-modules-form data-summary-url="{{ route('dash.modules.summary') }}">
        @csrf
        @if ($welcome)<input type="hidden" name="welcome" value="1">@endif

        <div class="grid gap-5 lg:grid-cols-3">
            <div class="space-y-6 lg:col-span-2">
                @foreach ($grouped as $group => $modules)
                    @continue($modules === [])
                    <section aria-labelledby="group-{{ $group }}">
                        <h2 id="group-{{ $group }}" class="mb-2 flex items-center gap-2 text-sm font-extrabold text-navy-700">
                            <x-landing.icon :name="$groups[$group][1]" class="size-4 text-brand-600" />{{ $groups[$group][0] }}
                        </h2>
                        <div class="grid gap-3 sm:grid-cols-2">
                            @foreach ($modules as $module)
                                @php
                                    $locked = in_array($module['slug'], \App\Services\ModuleChoice::LOCKED, true);
                                    $has = $module['feature'] === null || $rights->allows($module['feature']);
                                @endphp
                                <label data-module-tile="{{ $module['slug'] }}" @class([
                                    'group relative flex gap-3 rounded-2xl border border-navy-100 bg-white p-4 transition',
                                    'has-checked:border-brand-500 has-checked:bg-brand-50/60 has-checked:ring-1 has-checked:ring-brand-500',
                                    'has-focus-visible:outline-2 has-focus-visible:outline-offset-2 has-focus-visible:outline-brand-500',
                                    'cursor-pointer hover:border-navy-300' => ! $locked,
                                ])>
                                    <input type="checkbox" class="peer sr-only" value="{{ $module['slug'] }}" data-module-box
                                           @if ($locked) checked disabled @else name="modules[]" @checked(in_array($module['slug'], $selected, true)) @endif>
                                    @if ($locked)<input type="hidden" name="modules[]" value="{{ $module['slug'] }}">@endif
                                    <span class="grid size-11 shrink-0 place-items-center rounded-xl bg-navy-50 text-navy-600 transition group-has-checked:bg-brand-600 group-has-checked:text-white">
                                        <x-landing.icon :name="$module['icon']" class="size-5" />
                                    </span>
                                    <span class="min-w-0 flex-1">
                                        <span class="block font-bold leading-snug text-navy-900">{{ $module['name'] }}</span>
                                        <span class="mt-0.5 block text-sm leading-relaxed text-navy-500">{{ $module['summary'] }}</span>
                                        <span class="mt-2 flex flex-wrap gap-1.5">
                                            @if ($locked)
                                                <span class="badge bg-navy-800 text-white">{{ __('Always on') }}</span>
                                            @elseif ($has)
                                                <span class="badge bg-navy-50 text-navy-600">{{ $trialing ? __('In your trial') : __('In your plan') }}</span>
                                            @elseif (($text = $where($module)) !== '')
                                                <span class="badge bg-brand-50 text-brand-700">{{ $text }}</span>
                                            @endif
                                        </span>
                                    </span>
                                    <span aria-hidden="true" class="grid size-6 shrink-0 place-items-center rounded-full border-2 border-navy-200 text-transparent transition group-has-checked:border-brand-600 group-has-checked:bg-brand-600 group-has-checked:text-white">
                                        <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                                    </span>
                                </label>
                            @endforeach
                        </div>
                    </section>
                @endforeach
            </div>

            <aside class="lg:sticky lg:top-4 lg:self-start">
                <div class="card" data-modules-summary aria-live="polite">
                    @include('dashboard.partials.modules-summary', ['coverage' => $coverage])
                </div>
            </aside>
        </div>

        <div class="sticky bottom-0 z-20 -mx-4 mt-6 border-t border-navy-100 bg-white/95 px-4 py-3 backdrop-blur sm:-mx-6 sm:px-6">
            <div class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-3">
                <span class="text-sm text-navy-600">{!! __('You picked :n modules', ['n' => '<b class="text-navy-900" data-modules-count>'.count($selected).'</b>']) !!}</span>
                <div class="flex flex-1 items-center justify-end gap-2 sm:flex-none">
                    @if ($welcome)
                        <button type="submit" formaction="{{ route('dash.modules.skip') }}" formnovalidate class="btn-ghost" data-modules-skip>{{ __('Later') }}</button>
                    @endif
                    <button class="btn-primary flex-1 sm:flex-none sm:px-8" data-modules-save>{{ $welcome ? __('Save and start') : __('Save') }}</button>
                </div>
            </div>
        </div>
    </form>
</div>

<script>
(() => {
    const form = document.querySelector('[data-modules-form]');
    if (!form) return;
    const boxes = [...form.querySelectorAll('[data-module-box]')];
    const count = form.querySelector('[data-modules-count]');
    const summary = form.querySelector('[data-modules-summary]');
    let timer = null, ticket = 0;
    const refresh = () => {
        const picked = boxes.filter((b) => b.checked);
        count.textContent = picked.length;
        clearTimeout(timer);
        timer = setTimeout(async () => {
            const mine = ++ticket;
            const params = new URLSearchParams();
            picked.forEach((b) => params.append('modules[]', b.value));
            try {
                const res = await fetch(form.dataset.summaryUrl + '?' + params, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
                if (res.ok && mine === ticket) summary.innerHTML = await res.text();
            } catch (e) { /* the summary refreshes on the next tick or on save */ }
        }, 250);
    };
    boxes.forEach((b) => b.addEventListener('change', refresh));
})();
</script>
@endsection
