@extends('layouts.dashboard')
@section('title', __('Review import'))

@section('content')
<div class="mb-4 flex flex-wrap items-center justify-between gap-3">
    <h1 class="page-title">{{ __('Review import') }}</h1>
    <a href="{{ route('dash.import') }}" class="btn-ghost">{{ __('Back') }}</a>
</div>

@if ($import->status === 'pending')
    <div class="card mx-auto max-w-lg py-10 text-center" data-poll="{{ route('dash.import.status', $import) }}">
        <div class="mx-auto mb-4 size-12 animate-spin rounded-full border-4 border-brand-200 border-t-brand-600"></div>
        <h2 class="section-title">{{ __('Reading your menu…') }}</h2>
        <p class="muted">{{ __('This usually takes 20 to 60 seconds. You can stay on this page.') }}</p>
    </div>
    <script>
        (function poll() {
            setTimeout(async () => {
                try {
                    const d = await (await fetch(document.querySelector('[data-poll]').dataset.poll, { headers: { Accept: 'application/json' } })).json();
                    if (d.status !== 'pending') return location.reload();
                } catch (e) {}
                poll();
            }, 3000);
        })();
    </script>
@elseif ($import->status === 'failed')
    <div class="card mx-auto max-w-lg text-center">
        <p class="font-semibold text-red-700">{{ $import->error ?: __('Failed') }}</p>
        <a href="{{ route('dash.import') }}" class="btn-primary mt-4">{{ __('Try again') }}</a>
    </div>
@elseif ($import->status === 'applied')
    <div class="card mx-auto max-w-lg text-center">
        <p class="font-semibold text-emerald-700">{{ __('Added to the menu') }}</p>
        <a href="{{ route('dash.menu') }}" class="btn-primary mt-4">{{ __('Open the menu editor') }}</a>
    </div>
@else
    @php($rows = collect($import->result['categories'] ?? [])->flatMap(fn ($c) => collect($c['items'])->map(fn ($i) => $i + ['category' => $c['name'], 'category_en' => $c['name_en']])))
    <form method="post" action="{{ route('dash.import.apply', $import) }}" class="space-y-4">
        @csrf
        <div class="card flex flex-wrap items-center justify-between gap-3">
            <p class="text-sm text-navy-700">{{ __(':n items found. Fix anything that is wrong, untick rows you don’t want, then confirm.', ['n' => $rows->count()]) }}</p>
            <div class="flex flex-wrap items-center gap-3 text-sm">
                <label class="flex items-center gap-2"><input type="radio" name="mode" value="add" checked class="size-4 accent-brand-600"> {{ __('Add as new items') }}</label>
                <label class="flex items-center gap-2"><input type="radio" name="mode" value="update" class="size-4 accent-brand-600"> {{ __('Update items with the same name (prices)') }}</label>
            </div>
        </div>
        <x-flash />
        <div class="card overflow-x-auto p-0">
            <table class="table-base min-w-[64rem]">
                <thead><tr><th class="w-10"></th><th>{{ __('Category') }}</th><th>{{ __('Name (Arabic)') }}</th><th>{{ __('Name (English)') }}</th><th class="w-24">{{ __('Price') }}</th><th>{{ __('Sizes') }}</th><th>{{ __('Extras') }}</th></tr></thead>
                <tbody>
                @foreach ($rows as $i => $r)
                    <tr>
                        <td><input type="checkbox" name="rows[{{ $i }}][keep]" value="1" checked class="size-4 accent-brand-600"></td>
                        <td>
                            <input class="field min-h-9" name="rows[{{ $i }}][category]" value="{{ $r['category'] }}">
                            <input type="hidden" name="rows[{{ $i }}][category_en]" value="{{ $r['category_en'] }}">
                            <input type="hidden" name="rows[{{ $i }}][description]" value="{{ $r['description'] }}">
                            <input type="hidden" name="rows[{{ $i }}][allergens]" value="{{ implode(' | ', $r['allergens'] ?? []) }}">
                        </td>
                        <td><input class="field min-h-9" name="rows[{{ $i }}][name]" value="{{ $r['name'] }}"></td>
                        <td><input class="field min-h-9" name="rows[{{ $i }}][name_en]" value="{{ $r['name_en'] }}" dir="ltr"></td>
                        <td><input class="field min-h-9" name="rows[{{ $i }}][price]" value="{{ money($r['price']) }}" inputmode="decimal"></td>
                        <td><input class="field min-h-9" name="rows[{{ $i }}][sizes]" value="{{ \App\Services\Import\MenuDraft::choicesText($r['sizes']) }}" placeholder="صغير:0 | كبير:15"></td>
                        <td><input class="field min-h-9" name="rows[{{ $i }}][extras]" value="{{ \App\Services\Import\MenuDraft::choicesText($r['extras']) }}"></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <button class="btn-primary">{{ __('Add to my menu') }}</button>
    </form>
@endif
@endsection
