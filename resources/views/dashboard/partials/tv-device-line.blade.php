{{-- A screen's kind of TV on its card, with its setup help and «اتغيّر نوع الشاشة؟». --}}
@php
    $deviceTitle = \App\Support\ScreenDevices::title($screen->device_type);
@endphp
<p class="text-sm text-navy-600" data-screen-shape><span class="text-navy-500">{{ __('Screen size and orientation') }}:</span> {{ \App\Support\ScreenShape::summary($screen) }} <a href="{{ route('dash.tv.edit', $screen) }}#shape" class="ms-1 text-brand-700 underline">{{ __('Change it') }}</a></p>
<div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm" data-device-type="{{ $screen->device_type }}">
    @if ($deviceTitle)
        <span class="font-semibold text-navy-700"><span class="text-navy-500">{{ __('Screen type') }}:</span> <span dir="auto">{{ $deviceTitle }}</span></span>
        <a href="{{ route('dash.tv.device', $screen) }}" class="font-bold text-brand-700" data-device-help>{{ __('How do I keep this screen running?') }}</a>
        <a href="{{ route('dash.tv.device', ['screen' => $screen, 'change' => 1]) }}" class="text-navy-500 underline" data-change-type>{{ __('Changed the screen type?') }}</a>
    @else
        <a href="{{ route('dash.tv.device', $screen) }}" class="font-bold text-brand-700" data-choose-type>{{ __('Choose the screen type to see its setup steps') }}</a>
    @endif
</div>
