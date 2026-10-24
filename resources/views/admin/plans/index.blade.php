@extends('layouts.admin')
@section('title', __('Plans & add-ons') . ' · EgHub Admin')

@section('content')
@include('admin.plans.tabs')

@php
    $featureTotal = count(\App\Models\Plan::FEATURES);
@endphp
<div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-5" data-plan-cards>
    @foreach ($plans as $p)
        @php
            $rights = $matrix[$p->id];
            $on = count(array_filter($rights['features']));
        @endphp
        <article @class(['card flex flex-col gap-3 p-4', 'ring-2 ring-brand-600' => $p->is_featured]) data-plan="{{ $p->code }}">
            <div class="flex items-start justify-between gap-2">
                <div class="min-w-0">
                    <h2 class="truncate text-lg font-black">{{ $p->displayName() }}</h2>
                    <div class="flex flex-wrap gap-1">
                        @if ($p->is_featured)<span class="badge bg-brand-100 text-brand-700">{{ __('Most popular') }}</span>@endif
                        @unless ($p->is_public)<span class="badge bg-navy-100 text-navy-600">{{ __('Hidden from pricing') }}</span>@endunless
                        @if ($p->contact_sales)<span class="badge bg-amber-100 text-amber-800" data-plan-contact>{{ __('Contact sales') }}</span>@endif
                    </div>
                </div>
                <span class="badge shrink-0 bg-navy-50 text-navy-700" title="{{ __('Restaurants on this plan') }}">{{ __(':count restaurants', ['count' => (int) ($restaurants[$p->id] ?? 0)]) }}</span>
            </div>
            <div>
                @if ($p->contact_sales)
                    <span class="text-2xl font-black">{{ __('By agreement') }}</span>
                    <span class="block text-xs text-navy-500">{{ __('Shown as “Contact us”; assign it from the restaurant’s page.') }}</span>
                @else
                    <span class="text-3xl font-black">{{ money($p->monthly_price) }}</span>
                    <span class="text-sm text-navy-500">{{ __('EGP / month') }}</span>
                    @if ($p->original_price)<span class="ms-1 text-sm text-navy-400 line-through">{{ money($p->original_price) }}</span>@endif
                @endif
            </div>
            <dl class="grid grid-cols-2 gap-x-3 gap-y-1 text-sm">
                @foreach (\App\Models\Plan::LIMITS as $key => $_)
                    @php($v = $rights['limits'][$key])
                    <dt class="truncate text-navy-500">{{ \App\Models\Plan::label($key) }}</dt>
                    <dd class="text-end font-bold" dir="ltr">{{ $v === null ? '∞' : number_format($v) }}</dd>
                @endforeach
            </dl>
            <div>
                <div class="mb-1 flex justify-between text-xs text-navy-500"><span>{{ __('Features') }}</span><span dir="ltr">{{ $on }} / {{ $featureTotal }}</span></div>
                <div class="flex h-1.5 overflow-hidden rounded-full bg-navy-100"><div class="h-full rounded-full bg-brand-600" style="width: {{ round($on / max(1, $featureTotal) * 100) }}%"></div></div>
            </div>
            <a href="{{ route('admin.plans.edit', $p) }}" class="btn-ghost mt-auto w-full">{{ __('Edit plan') }}</a>
        </article>
    @endforeach
</div>

<details class="group card mt-4 p-0" data-compare>
    <summary class="flex min-h-14 cursor-pointer list-none items-center justify-between gap-2 px-5 font-bold [&::-webkit-details-marker]:hidden">
        <span>{{ __('Compare all features') }}</span>
        <svg class="size-5 text-navy-400 transition group-open:rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="m6 9 6 6 6-6"/></svg>
    </summary>
    <div class="max-h-[70vh] overflow-auto border-t border-navy-100">
        <table class="w-full min-w-[36rem] border-separate border-spacing-0 text-sm" data-compare-table>
            <thead>
                <tr>
                    <th class="sticky start-0 top-0 z-20 border-b border-navy-100 bg-white px-3 py-2 text-start text-xs font-semibold text-navy-500">{{ __('Feature') }}</th>
                    @foreach ($plans as $p)
                        <th class="sticky top-0 z-10 w-[12%] border-b border-navy-100 bg-white px-3 py-2 text-center">
                            <a href="{{ route('admin.plans.edit', $p) }}" class="font-extrabold text-navy-900 hover:text-brand-700">{{ $p->displayName() }}</a>
                            <div class="text-xs font-normal text-navy-500">{{ $p->contact_sales ? __('By agreement') : money($p->monthly_price) }}</div>
                        </th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                <tr><td colspan="{{ $plans->count() + 1 }}" class="sticky start-0 bg-navy-50 px-3 py-2 text-xs font-bold text-navy-600">{{ __('Limits') }}</td></tr>
                @foreach (\App\Models\Plan::LIMITS as $key => $_)
                    <tr>
                        <td class="sticky start-0 z-[1] min-w-36 max-w-40 border-b border-navy-50 bg-white px-3 py-2 max-sm:text-xs sm:max-w-none font-semibold">{{ \App\Models\Plan::label($key) }}</td>
                        @foreach ($plans as $p)
                            @php($v = $matrix[$p->id]['limits'][$key])
                            <td class="border-b border-navy-50 px-3 py-2 text-center font-semibold">{{ $v === null ? '∞' : $v }}</td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
            @foreach (\App\Models\Plan::GROUPS as $group => $gl)
                <tbody data-compare-group>
                    <tr>
                        <td colspan="{{ $plans->count() + 1 }}" class="bg-navy-50 p-0">
                            <button type="button" class="sticky start-0 flex min-h-10 items-center gap-2 px-3 text-xs font-bold text-navy-600" aria-expanded="true" data-compare-toggle>
                                <svg class="size-4 transition" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="m6 9 6 6 6-6"/></svg>
                                {{ app()->getLocale() === 'en' ? $gl[1] : $gl[0] }}
                            </button>
                        </td>
                    </tr>
                    @foreach (\App\Models\Plan::FEATURES as $key => $f)
                        @continue($f[2] !== $group)
                        <tr data-compare-row>
                            <td class="sticky start-0 z-[1] min-w-36 max-w-40 border-b border-navy-50 bg-white px-3 py-2 max-sm:text-xs sm:max-w-none">{{ \App\Models\Plan::label($key) }}</td>
                            @foreach ($plans as $p)
                                <td class="border-b border-navy-50 px-3 py-2 text-center">{!! $matrix[$p->id]['features'][$key] ? '<span class="font-bold text-emerald-600">✓</span>' : '<span class="text-navy-200">—</span>' !!}</td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            @endforeach
        </table>
    </div>
</details>
@endsection

@push('scripts')
<script>
    document.querySelectorAll('[data-compare-toggle]').forEach((button) => button.addEventListener('click', () => {
        const open = button.getAttribute('aria-expanded') !== 'true';
        button.setAttribute('aria-expanded', open ? 'true' : 'false');
        button.querySelector('svg').classList.toggle('-rotate-90', !open);
        button.closest('[data-compare-group]').querySelectorAll('[data-compare-row]').forEach((row) => row.classList.toggle('hidden', !open));
    }));
</script>
@endpush
