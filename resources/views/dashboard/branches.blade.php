@extends('layouts.dashboard')
@section('title', __('Branches'))

@section('content')
<h1 class="mb-4 text-2xl font-extrabold">{{ __('Branches') }}</h1>

<div class="grid gap-4 xl:grid-cols-3">
    <div class="space-y-4 xl:col-span-2">
        @foreach ($branches as $b)
            <form method="post" action="{{ route('dash.branches.update', $b) }}" class="card grid gap-3 sm:grid-cols-2">
                @csrf
                <div class="flex items-center justify-between sm:col-span-2">
                    <h2 class="text-lg font-bold">{{ $b->name }} <span class="text-sm font-normal text-navy-500">· {{ __(':n tables', ['n' => $b->tables_count]) }}</span></h2>
                    <a href="{{ tenant()->url('?branch=' . $b->slug) }}" target="_blank" rel="noopener" class="text-sm text-brand-700" dir="ltr">?branch={{ $b->slug }}</a>
                </div>
                @include('dashboard.partials.branch-fields', ['b' => $b])
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="is_active" value="1" @checked($b->is_active) class="size-4 accent-brand-600"> {{ __('Active') }}</label>
                <div class="sm:col-span-2"><button class="btn-primary">{{ __('Save') }}</button></div>
            </form>
        @endforeach
    </div>
    <div class="card h-fit">
        <h2 class="mb-3 text-lg font-bold">{{ __('Add branch') }}</h2>
        @if ($canAdd)
            <form method="post" action="{{ route('dash.branches.store') }}" class="grid gap-3">
                @csrf
                @include('dashboard.partials.branch-fields', ['b' => null])
                <button class="btn-primary">{{ __('Add branch') }}</button>
            </form>
        @else
            <p class="text-sm text-navy-500">{{ \App\Services\Entitlements::current()->upgradeMessage('branches') }}</p>
        @endif
    </div>
</div>
@endsection
