@extends('layouts.dashboard')
@section('title', __('Import menu'))

@section('content')
<div class="dash-head">
    <div>
        <h1 class="page-title">{{ __('Import menu') }}</h1>
        <p class="dash-help">{{ __('Nothing is added until you review the rows and confirm.') }}</p>
    </div>
    <a href="{{ route('dash.menu') }}" class="btn-ghost">{{ __('Back to menu') }}</a>
</div>

<div class="grid gap-4 lg:grid-cols-2">
    <section class="card space-y-4">
        <div class="flex items-center gap-3">
            <span class="flex size-11 items-center justify-center rounded-xl bg-emerald-100 text-xl">📊</span>
            <div>
                <h2 class="section-title">{{ __('From Excel') }}</h2>
                <p class="muted">{{ __('Fill our template (or your own sheet with the same headers) and upload it.') }}</p>
            </div>
        </div>
        @if ($excel)
            <ol class="list-decimal space-y-1 ps-5 text-sm text-navy-700">
                <li><a href="{{ route('dash.import.template') }}" class="font-semibold text-brand-700 underline">{{ __('Download the template') }}</a></li>
                <li>{{ __('One row per item. Leave the category empty to keep the one above.') }}</li>
                <li>{{ __('Sizes and extras as name:price separated by |, e.g. Small:0 | Large:15') }}</li>
            </ol>
            <form method="post" action="{{ route('dash.import.excel') }}" enctype="multipart/form-data" class="space-y-3">
                @csrf
                <input class="field py-2" type="file" name="file" accept=".xlsx,.csv" required>
                @error('file')<div class="field-error">{{ $message }}</div>@enderror
                <button class="btn-primary w-full">{{ __('Upload and review') }}</button>
            </form>
        @else
            <p class="rounded-xl bg-brand-50 p-3 text-sm text-brand-700">{{ \App\Services\Entitlements::current()->upgradeMessage('excel_import') }}</p>
        @endif
    </section>

    <section class="card space-y-4">
        <div class="flex items-center gap-3">
            <span class="flex size-11 items-center justify-center rounded-xl bg-violet-100 text-xl">📷</span>
            <div>
                <h2 class="section-title">{{ __('From a photo or PDF (AI)') }}</h2>
                <p class="muted">{{ __('Photograph your printed menu; AI turns it into categories, items, prices and sizes.') }}</p>
            </div>
        </div>
        @if (!$ai)
            <p class="rounded-xl bg-brand-50 p-3 text-sm text-brand-700">{{ \App\Services\Entitlements::current()->upgradeMessage('ai_import') }}</p>
        @elseif (!$aiReady)
            <div class="rounded-xl bg-amber-50 p-3 text-sm text-amber-900">
                {{ __('This uses your own AI account (Claude, Gemini or OpenAI), so you only pay the provider a few piasters per menu.') }}
                @can('settings.manage')<a href="{{ route('dash.settings') }}#ai" class="font-bold underline">{{ __('Add your API key in Settings') }}</a>@endcan
            </div>
        @else
            <form method="post" action="{{ route('dash.import.ai') }}" enctype="multipart/form-data" class="space-y-3">
                @csrf
                <input class="field py-2" type="file" name="files[]" accept="image/jpeg,image/png,image/webp,application/pdf" multiple required>
                <p class="muted">{{ __('Up to 5 clear photos (one per page), or one PDF. Max 10 MB each.') }}@if ($aiLeft !== null) · {{ __(':n left this month', ['n' => $aiLeft]) }}@endif</p>
                @error('files')<div class="field-error">{{ $message }}</div>@enderror
                @error('files.*')<div class="field-error">{{ $message }}</div>@enderror
                <button class="btn-primary w-full">{{ __('Read my menu') }}</button>
            </form>
        @endif
    </section>
</div>

@if ($recent->isNotEmpty())
    <section class="card mt-6 overflow-x-auto">
        <h2 class="section-title mb-3">{{ __('Recent imports') }}</h2>
        <table class="table-base">
            <tbody>
            @foreach ($recent as $imp)
                <tr>
                    <td>{{ $imp->source === 'excel' ? 'Excel' : ($imp->source === 'pdf' ? 'PDF' : __('Photos')) }}</td>
                    <td>{{ local_time($imp->created_at) }}</td>
                    <td>{{ ['pending' => __('Reading…'), 'parsed' => __('Ready to review'), 'applied' => __('Added to the menu'), 'failed' => __('Failed')][$imp->status] ?? $imp->status }}</td>
                    <td>@if (in_array($imp->status, ['pending', 'parsed', 'failed'], true))<a href="{{ route('dash.import.review', $imp) }}" class="text-brand-700">{{ __('Open') }}</a>@endif</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </section>
@endif
@endsection
