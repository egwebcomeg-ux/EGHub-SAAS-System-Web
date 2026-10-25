@extends('layouts.dashboard')
@section('title', __('Phone notifications'))

@section('content')
<x-dash.page-header :title="__('Phone notifications')" />
<p class="muted mb-4 max-w-3xl">{{ __('With the EgHub app on your phone (the restaurant app or the waiter app), these alerts reach you even when the app is closed. Each person picks their own.') }}</p>

@unless ($configured)
    <p class="mb-4 max-w-3xl rounded-xl bg-amber-50 p-3 text-sm font-semibold text-amber-900" data-push-off>{{ __('Phone notifications are not switched on for EgHub yet. Your choices are saved and work as soon as they are.') }}</p>
@endunless

<section class="card mb-4 max-w-3xl">
    <h2 class="section-title">{{ __('What should ring your phone?') }}</h2>
    @if ($preferences === [])
        <p class="mt-2 text-sm text-navy-500">{{ __('Your role has no notifications on the phone.') }}</p>
    @else
        <form method="post" action="{{ route('dash.push.update') }}" class="mt-3 space-y-3" data-push-form>
            @csrf
            @foreach ($preferences as $event => $on)
                <label class="flex min-h-11 items-center gap-3 text-sm font-semibold">
                    <input type="checkbox" class="size-5" name="events[]" value="{{ $event }}" @checked($on)>
                    {{ \App\Services\Push\StaffPush::label($event) }}
                </label>
            @endforeach
            <button class="btn-primary">{{ __('Save') }}</button>
        </form>
    @endif
</section>

<section class="card max-w-3xl">
    <h2 class="section-title">{{ __('Your phones') }}</h2>
    @forelse ($devices as $device)
        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-navy-100 py-3 last:border-0" data-push-device>
            <div class="min-w-0">
                <div class="font-semibold">{{ $device->device ?: __('Android phone') }}</div>
                <div class="text-xs text-navy-500">{{ __('EgHub :app app', ['app' => $device->app]) }} @if ($device->app_version) · {{ $device->app_version }}@endif · {{ __('Last seen :time', ['time' => local_time($device->last_seen_at)]) }}</div>
            </div>
            <form method="post" action="{{ route('dash.push.devices.delete', $device->id) }}">
                @csrf
                <button class="btn-ghost min-h-11 px-3 text-red-700">{{ __('Remove') }}</button>
            </form>
        </div>
    @empty
        <p class="mt-2 text-sm text-navy-500">{{ __('No phone yet. Install the EgHub app and sign in with your account: it shows up here by itself.') }}</p>
    @endforelse
</section>
@endsection
