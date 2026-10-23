@extends('layouts.dashboard')
@section('title', __('Branches'))

@section('content')
<x-dash.page-header :title="__('Branches')" :help="__('Each branch has its own address, phone and tables.')" />

<div class="grid gap-4 xl:grid-cols-3">
    <div class="space-y-4 xl:col-span-2">
        @foreach ($branches as $b)
            <form method="post" action="{{ route('dash.branches.update', $b) }}" class="card grid gap-3 sm:grid-cols-2">
                @csrf
                <div class="flex flex-wrap items-center justify-between gap-2 sm:col-span-2">
                    <h2 class="text-lg font-bold">{{ $b->name }} <span class="text-sm font-normal text-navy-500">· {{ __(':n tables', ['n' => $b->tables_count]) }}</span></h2>
                    <div class="flex flex-wrap items-center gap-3 whitespace-nowrap">
                        @if (\App\Services\Entitlements::current()->allows('branch_pricing'))<a href="{{ route('dash.branches.menu', $b) }}" class="btn-ghost min-h-9 px-3">{{ __('Prices & availability') }}</a>@endif
                    </div>
                </div>
                {{-- the branch's own menu link (opens the menu on this branch): shown whole, with copy and open --}}
                @php($branchLink = tenant()->url('?branch=' . $b->slug))
                <div class="flex flex-wrap items-center gap-2 rounded-xl bg-navy-50 px-3 py-2 text-sm sm:col-span-2" data-branch-link>
                    <span class="font-semibold text-navy-700">{{ __('Branch menu link') }}</span>
                    <bdi dir="ltr" class="min-w-0 flex-1 break-all font-mono text-xs text-navy-600">{{ $branchLink }}</bdi>
                    <button type="button" class="btn-ghost min-h-9 px-3 text-xs" data-copy="{{ $branchLink }}">{{ __('Copy') }}</button>
                    <a href="{{ $branchLink }}" target="_blank" rel="noopener" class="btn-ghost min-h-9 px-3 text-xs">{{ __('Open') }}</a>
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

<script>
    document.querySelectorAll('[data-copy]').forEach((b) => b.addEventListener('click', async () => {
        try { await navigator.clipboard.writeText(b.dataset.copy); b.textContent = @js(__('Copied')); } catch (e) { prompt('', b.dataset.copy); }
    }));
</script>
@endsection
