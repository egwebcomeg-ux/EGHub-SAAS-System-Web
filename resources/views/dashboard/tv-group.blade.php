@extends('layouts.dashboard')
@section('title', $group->name . ' · ' . __('Screen group'))

@section('content')
@php
    $memberIds = $members->pluck('id')->all();
    $own = $members->where('own_playlist', true);
@endphp
<x-dash.page-header :title="$group->name" :help="__('One playlist for every screen of this group. Each screen keeps its own branch prices, look and hours.')">
    <x-slot:actions>
        <a href="{{ route('dash.devices', ['tab' => 'tv']) }}" class="btn-ghost">{{ __('All screens') }}</a>
        <a href="{{ route('dash.tv.alerts') }}" class="btn-ghost">{{ __('Urgent message') }}</a>
    </x-slot:actions>
</x-dash.page-header>

<div class="grid gap-6 xl:grid-cols-3">
    <div class="space-y-6 xl:col-span-2">
        @include('dashboard.partials.tv-playlist', ['owner' => $group])
    </div>

    <div class="space-y-6">
        <section class="card space-y-3" id="members">
            <h2 class="section-title">{{ __('Screens in this group') }}</h2>
            @if ($members->isEmpty())
                <p class="empty-state">{{ __('No screens yet. Tick the screens that should play this playlist.') }}</p>
            @else
                <ul class="space-y-2" data-members>
                    @foreach ($members as $m)
                        <li class="flex flex-wrap items-center gap-2 rounded-lg border border-navy-100 p-2 text-sm" data-tv-live-row="{{ $m->id }}">
                            @include('dashboard.partials.tv-dot', ['screen' => $m])
                            <a href="{{ route('dash.tv.edit', $m) }}" class="min-w-0 flex-1 truncate font-semibold text-brand-700">{{ $m->name }}</a>
                            <span class="text-xs text-navy-500">{{ $m->branch ? tr($m->branch->name, $m->branch->name_en) : __('Main branch') }}</span>
                            @if ($m->own_playlist)<span class="badge bg-amber-50 text-amber-800">{{ __('Own playlist') }}</span>@endif
                        </li>
                    @endforeach
                </ul>
                @if ($own->isNotEmpty())
                    <p class="text-xs text-navy-500">{{ __('Screens marked «Own playlist» stay in the group (for urgent messages and quick picks) but play their own slides. To make one follow the group, open it and choose «Play the group\'s playlist».') }}</p>
                @endif
            @endif

            <form method="post" action="{{ route('dash.tv.groups.update', $group) }}" class="space-y-3 border-t border-navy-100 pt-3">
                @csrf
                <div><label class="field-label">{{ __('Group name') }}</label><input class="field" name="name" value="{{ $group->name }}" required maxlength="60"></div>
                <p class="field-label">{{ __('Screens that follow this playlist') }}</p>
                @include('dashboard.partials.tv-screen-picker', ['screens' => $targets, 'checked' => $memberIds, 'narrow' => true])
                <button class="btn-primary">{{ __('Save') }}</button>
            </form>
            <form method="post" action="{{ route('dash.tv.groups.delete', $group) }}" onsubmit="return confirm(@js(__('Delete this group? Its screens go back to their own playlists.')))">@csrf<button class="min-h-10 px-1 text-sm text-red-600">{{ __('Delete group') }}</button></form>
        </section>
    </div>
</div>
@endsection
