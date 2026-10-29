@extends('layouts.dashboard')
@section('title', __('Ratings'))

@section('content')
<x-dash.page-header :title="__('Ratings')" />
@include('dashboard.partials.area-tabs', ['area' => 'reports', 'current' => 'feedback'])

<div class="mb-4 grid gap-4 md:grid-cols-3">
    <div class="card text-center">
        <div class="muted">{{ __('Average rating') }}</div>
        <div class="text-4xl font-extrabold text-amber-500">{{ $count ? number_format($average, 1) : '—' }} <span class="text-2xl">★</span></div>
        <div class="muted">{{ __(':n ratings', ['n' => $count]) }}</div>
    </div>
    <div class="card md:col-span-2">
        @for ($r = 5; $r >= 1; $r--)
            @php($n = (int) ($spread[$r] ?? 0))
            <a href="?rating={{ $rating === $r ? '' : $r }}" @class(['flex items-center gap-3 rounded-lg px-1 py-1 text-sm', 'bg-navy-50' => $rating === $r])>
                <span class="w-10">{{ $r }} ★</span>
                <span class="h-2 flex-1 overflow-hidden rounded-full bg-navy-100"><span class="block h-full rounded-full bg-amber-400" style="width: {{ $count ? round($n * 100 / $count) : 0 }}%"></span></span>
                <span class="w-10 text-end text-navy-500">{{ $n }}</span>
            </a>
        @endfor
    </div>
</div>

<div class="space-y-3">
    @forelse ($feedback as $f)
        <div class="card flex flex-wrap items-start justify-between gap-3">
            <div>
                <div class="text-lg text-amber-500">{{ str_repeat('★', $f->rating) }}<span class="text-navy-200">{{ str_repeat('★', 5 - $f->rating) }}</span></div>
                @if ($f->comment)<p class="mt-1 text-navy-800">{{ $f->comment }}</p>@endif
            </div>
            <div class="text-end text-sm text-navy-500">
                <div>{{ local_time($f->created_at) }}</div>
                @if ($f->table_name)<div>{{ __('Table :name', ['name' => $f->table_name]) }}</div>@endif
                @if ($f->order)<div>#{{ $f->order->number }}</div>@endif
            </div>
        </div>
    @empty
        <p class="empty-state">{{ __('No ratings yet. Guests can rate from the menu and after their order.') }}</p>
    @endforelse
    {{ $feedback->links() }}
</div>
@endsection
