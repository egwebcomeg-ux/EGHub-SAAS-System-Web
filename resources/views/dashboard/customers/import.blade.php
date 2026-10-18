@extends('layouts.dashboard')
@section('title', __('Import customers'))

@section('content')
<x-dash.page-header :title="__('Import customers')" :help="__('Nothing is saved until you review the rows and confirm.')">
    <x-slot:actions>
        <a href="{{ route('dash.customers') }}" class="btn-ghost">{{ __('Back to customers') }}</a>
    </x-slot:actions>
</x-dash.page-header>

<div class="grid gap-4 lg:grid-cols-5">
    <section class="card space-y-4 lg:col-span-3" data-import-upload>
        <div class="flex items-center gap-3">
            <span class="flex size-11 shrink-0 items-center justify-center rounded-xl bg-brand-50 text-xl">📊</span>
            <div>
                <h2 class="section-title">{{ __('Upload a CSV or Excel file') }}</h2>
                <p class="muted">{{ __('Up to :rows rows and :mb MB. An exported customers file imports back as it is.', ['rows' => number_format($maxRows), 'mb' => $maxMb]) }}</p>
            </div>
        </div>
        <form method="post" action="{{ route('dash.customers.import.store') }}" enctype="multipart/form-data" class="space-y-3">
            @csrf
            <input class="field py-2" type="file" name="file" accept=".xlsx,.csv" required>
            @error('file')<div class="field-error" role="alert">{{ $message }}</div>@enderror
            <button class="btn-primary w-full">{{ __('Upload and review') }}</button>
        </form>
        <a href="{{ route('dash.customers.import.template') }}" class="btn-ghost w-full" data-import-template>⬇ {{ __('Download an example file') }}</a>
    </section>

    <section class="card space-y-3 lg:col-span-2">
        <h2 class="section-title">{{ __('Columns we read') }}</h2>
        <ul class="space-y-2 text-sm text-navy-700">
            <li><b>{{ __('Mobile') }}</b> <span class="badge bg-brand-50 text-brand-700">{{ __('Required') }}</span> · {{ __('Egyptian numbers; Arabic digits and a lost leading zero are fixed.') }}</li>
            <li><b>{{ __('Name') }}</b> · <b>{{ __('Second phone') }}</b> · <b>{{ __('Birthday') }}</b> · <b>{{ __('Notes') }}</b></li>
            <li><b>{{ __('Address') }}</b> · <b>{{ __('Area') }}</b> ({{ __('matched with your delivery zones by name') }})</li>
            <li><b>{{ __('Accepts offers') }}</b> (1 / 0) · <b>{{ __('Email') }}</b> {{ __('goes into the notes') }}</li>
        </ul>
        <p class="muted">{{ __('Headers can be in Arabic or English. Visits, spend, loyalty and wallet balances are never changed by an import.') }}</p>
    </section>
</div>
@endsection
