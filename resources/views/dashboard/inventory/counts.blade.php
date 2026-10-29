@extends('layouts.dashboard')
@section('title', __('Stock counts'))

@section('content')
<x-dash.page-header :title="__('Stock counts')" :help="__('Start a count sheet, type what is on the shelf and save it to finish later. A manager posts it, and the stock is corrected to the shelf.')" />
@include('dashboard.inventory.tabs')

<div class="grid gap-4 xl:grid-cols-3">
    <section class="card overflow-x-auto xl:col-span-2">
        <table class="table-base">
            <thead><tr><th>#</th><th>{{ __('Started') }}</th><th>{{ __('Status') }}</th><th>{{ __('Counted') }}</th><th>{{ __('Difference') }}</th><th>{{ __('By') }}</th><th></th></tr></thead>
            <tbody>
            @forelse ($counts as $c)
                <tr data-count-row="{{ $c->id }}">
                    <td class="font-semibold">#{{ $c->id }}</td>
                    <td class="whitespace-nowrap text-navy-500">{{ local_time($c->created_at, 'd/m h:i A') }}@if ($c->note)<div class="text-xs">{{ $c->note }}</div>@endif</td>
                    <td><span @class(['badge', 'bg-amber-100 text-amber-800' => $c->isDraft(), 'bg-emerald-100 text-emerald-800' => ! $c->isDraft()])>{{ $c->statusLabel() }}</span></td>
                    <td class="text-navy-500" dir="ltr">{{ $c->counted_count }} / {{ $c->lines_count }}</td>
                    <td>
                        @if (! $c->isDraft())
                            <span dir="ltr" @class(['font-bold', 'text-red-600' => $c->value < 0, 'text-emerald-700' => $c->value > 0])>{{ money($c->value) }}</span>
                        @else
                            —
                        @endif
                    </td>
                    <td class="text-navy-500">{{ $c->poster?->name ?? $c->creator?->name ?? '—' }}</td>
                    <td><a href="{{ route('dash.inventory.counts.show', $c) }}" class="btn-ghost min-h-9 px-3">{{ $c->isDraft() ? __('Continue') : __('View') }}</a></td>
                </tr>
            @empty
                <tr><td colspan="7" class="py-6 text-center text-navy-500">{{ __('No counts yet. Start one from the card.') }}</td></tr>
            @endforelse
            </tbody>
        </table>
        <div class="mt-3">{{ $counts->links() }}</div>
    </section>

    <form method="post" action="{{ route('dash.inventory.counts.store') }}" class="card space-y-3" data-new-count>
        @csrf
        <h2 class="section-title">{{ __('New count') }} <span class="text-sm font-normal text-navy-500">· {{ $branch->name }}</span></h2>
        <input type="hidden" name="branch" value="{{ $branch->id }}">
        @php $scopes = ['all' => __('Everything'), 'ingredient' => __('Ingredients only'), 'packaging' => __('Packaging only'), 'some' => __('Only the ones I choose')]; @endphp
        <div class="space-y-1">
            @foreach ($scopes as $key => $label)
                <label class="flex items-center gap-2 text-sm"><input type="radio" name="scope" value="{{ $key }}" @checked(old('scope', 'all') === $key) class="size-4 accent-brand-600"
                    onchange="this.form.querySelector('[data-pick]').classList.toggle('hidden', this.value !== 'some')"> {{ $label }}</label>
            @endforeach
        </div>
        <div data-pick @class(['max-h-72 space-y-1 overflow-y-auto rounded-xl border border-navy-100 p-2', 'hidden' => old('scope', 'all') !== 'some'])>
            @forelse ($ingredients as $i)
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="ingredient_ids[]" value="{{ $i->id }}" @checked(in_array($i->id, (array) old('ingredient_ids', [])))
                    class="size-4 accent-brand-600"> {{ $i->name }} <span class="text-xs text-navy-500">{{ $i->kindLabel() }}</span></label>
            @empty
                <p class="text-sm text-navy-500">{{ __('Add ingredients first from the Stock page.') }}</p>
            @endforelse
        </div>
        <input class="field" name="note" maxlength="200" value="{{ old('note') }}" placeholder="{{ __('Note (e.g. month-end count)') }}">
        <button class="btn-primary w-full">{{ __('Start count') }}</button>
    </form>
</div>
@endsection
