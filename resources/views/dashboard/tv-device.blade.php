@extends('layouts.dashboard')
@section('title', $screen->name . ' · ' . __('Screen type'))

@section('content')
@php
    $devices = \App\Support\ScreenDevices::all();
    $current = $type ? $devices[$type] : null;
@endphp
<x-dash.page-header :title="__('How to keep :screen running', ['screen' => $screen->name])" :help="__('The setup steps for this screen\'s kind of TV: how to open it, keep it on all day and what happens after a power cut.')">
    <x-slot:actions>
        <a href="{{ route('dash.tv.edit', $screen) }}" class="btn-ghost">{{ __('Back to the screen') }}</a>
    </x-slot:actions>
</x-dash.page-header>

<div class="grid max-w-5xl gap-6">
    @if ($current && ! $changing)
        <section class="card space-y-4" id="device-guide">
            @include('partials.screen-device-guide', ['device' => $current, 'heading' => 'h2', 'expanded' => true])
            <p class="border-t border-navy-100 pt-3 text-sm">
                <a href="{{ route('dash.tv.device', ['screen' => $screen, 'change' => 1]) }}" class="font-bold text-brand-700" data-change-type>{{ __('Changed the screen type?') }}</a>
            </p>
        </section>
    @else
        <form method="post" action="{{ route('dash.tv.device.update', $screen) }}" class="grid gap-6">
            @csrf
            <section class="card space-y-4">
                <h2 class="section-title">{{ __('Screen type') }}</h2>
                @error('device_type')<p class="text-sm font-semibold text-red-600">{{ __('Choose the screen type.') }}</p>@enderror
                @include('dashboard.partials.tv-device-picker', ['type' => $type, 'suggested' => false])
            </section>
            <section class="card space-y-4">
                <p class="empty-state" data-guide-empty @if ($current) hidden @endif>{{ __('Choose your screen type above and its steps show here.') }}</p>
                @foreach ($devices as $key => $device)
                    <div data-guide-for="{{ $key }}" @if ($type !== $key) hidden @endif>
                        @include('partials.screen-device-guide', ['device' => $device, 'heading' => 'h3'])
                    </div>
                @endforeach
                <button class="btn-primary">{{ __('Save the screen type') }}</button>
            </section>
        </form>
    @endif
</div>
@endsection
