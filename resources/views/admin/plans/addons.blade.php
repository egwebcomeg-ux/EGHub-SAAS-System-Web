@extends('layouts.admin')
@section('title', __('Add-ons') . ' · EgHub Admin')

@section('content')
@include('admin.plans.tabs')
@php
    $typeOf = fn ($a) => $a->isFeaturePack() ? 'feature' : ($a->isCreditPack() ? 'credits' : 'limit');
    $types = ['feature' => [__('Feature pack'), 'bg-brand-100 text-brand-700'], 'limit' => [__('Extra limit'), 'bg-navy-100 text-navy-700'], 'credits' => [__('WhatsApp credits'), 'bg-emerald-100 text-emerald-800']];
    $countByType = $addons->countBy($typeOf);
@endphp
<p class="muted mb-3">{{ __('Sold on top of any plan. A pack unlocks features; an extra limit raises a plan limit; credits are paid once.') }}</p>

<div class="mb-3 flex flex-wrap items-center gap-2" data-addon-filters>
    <input type="search" class="field min-w-0 flex-1 sm:max-w-xs" placeholder="{{ __('Search add-ons') }}" aria-label="{{ __('Search add-ons') }}" data-addon-search>
    <div class="flex flex-wrap gap-1 text-sm font-semibold" role="group" aria-label="{{ __('Type') }}">
        <button type="button" class="rounded-lg bg-navy-800 px-3 py-2 text-white" data-addon-type="">{{ __('All') }} <span class="opacity-70">{{ $addons->count() }}</span></button>
        @foreach ($types as $key => [$label])
            @if ($countByType->get($key))
                <button type="button" class="rounded-lg bg-white px-3 py-2 text-navy-700 ring-1 ring-navy-100" data-addon-type="{{ $key }}">{{ $label }} <span class="opacity-70">{{ $countByType->get($key) }}</span></button>
            @endif
        @endforeach
    </div>
</div>

<div class="card p-0">
    <ul class="divide-y divide-navy-50" data-addon-list>
        @foreach ($addons as $a)
            @php
                $type = $typeOf($a);
                $minPlan = $a->min_plan ? $plans->get($a->min_plan)?->displayName() : null;
            @endphp
            <li class="flex flex-wrap items-center gap-x-4 gap-y-2 px-4 py-3 sm:px-5" data-addon-row data-type="{{ $type }}" data-search="{{ mb_strtolower($a->name . ' ' . $a->name_en . ' ' . $a->key) }}">
                <div class="min-w-0 flex-1 basis-56">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="badge {{ $types[$type][1] }}">{{ $types[$type][0] }}</span>
                        <a href="{{ route('admin.addons.edit', $a) }}" class="font-bold text-navy-900 hover:text-brand-700">{{ $a->displayName() }}</a>
                    </div>
                    <div class="mt-0.5 flex flex-wrap gap-x-3 text-xs text-navy-500">
                        <span>{{ __('Available from plan') }}: {{ $minPlan ?? __('Any plan') }}</span>
                        @if ($a->per_branch)<span>{{ __('Per branch') }}</span>@endif
                        @if ($a->isFeaturePack())<span>{{ __(':count features', ['count' => count($a->features ?? [])]) }}</span>@endif
                        @if ($a->isCreditPack())<span>{{ number_format((int) $a->credits) }} {{ __('messages') }}</span>@endif
                        @if ($buyers->get($a->key))<span>{{ __(':count restaurants', ['count' => $buyers->get($a->key)]) }}</span>@endif
                    </div>
                </div>
                <div class="w-28 text-end text-sm">
                    <span class="font-black">{{ money($a->monthly_price) }}</span>
                    <span class="block text-xs text-navy-500">{{ $a->isCreditPack() ? __('EGP once') : __('EGP / month') }}</span>
                </div>
                <form method="post" action="{{ route('admin.addons.toggle', $a) }}">
                    @csrf
                    <button type="submit" role="switch" aria-checked="{{ $a->is_active ? 'true' : 'false' }}" class="flex min-h-11 items-center gap-2 text-xs font-semibold text-navy-600" title="{{ __('On sale') }}">
                        <span @class(['relative h-6 w-11 rounded-full transition', 'bg-emerald-500' => $a->is_active, 'bg-navy-200' => ! $a->is_active])>
                            <span @class(['absolute top-0.5 size-5 rounded-full bg-white shadow transition-all', 'start-[1.375rem]' => $a->is_active, 'start-0.5' => ! $a->is_active])></span>
                        </span>
                        <span class="w-24 whitespace-nowrap text-start">{{ $a->is_active ? __('On sale') : __('Off') }}</span>
                    </button>
                </form>
                <a href="{{ route('admin.addons.edit', $a) }}" class="btn-ghost min-h-9 px-3 text-xs">{{ __('Edit') }}</a>
            </li>
        @endforeach
    </ul>
    <p class="hidden px-5 py-8 text-center text-sm text-navy-500" data-addon-empty>{{ __('No add-on matches.') }}</p>
</div>
@endsection

@push('scripts')
<script>
    (() => {
        const rows = [...document.querySelectorAll('[data-addon-row]')];
        const search = document.querySelector('[data-addon-search]');
        const buttons = [...document.querySelectorAll('[data-addon-type]')];
        let type = '';
        const apply = () => {
            const q = search.value.trim().toLowerCase();
            let shown = 0;
            rows.forEach((row) => {
                const match = (type === '' || row.dataset.type === type) && (q === '' || row.dataset.search.includes(q));
                row.classList.toggle('hidden', !match);
                shown += match ? 1 : 0;
            });
            document.querySelector('[data-addon-empty]').classList.toggle('hidden', shown > 0);
        };
        search.addEventListener('input', apply);
        buttons.forEach((b) => b.addEventListener('click', () => {
            type = b.dataset.addonType;
            buttons.forEach((x) => {
                const on = x === b;
                x.classList.toggle('bg-navy-800', on); x.classList.toggle('text-white', on);
                x.classList.toggle('bg-white', !on); x.classList.toggle('text-navy-700', !on); x.classList.toggle('ring-1', !on);
            });
            apply();
        }));
    })();
</script>
@endpush
