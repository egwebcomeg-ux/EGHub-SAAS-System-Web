{{-- The guest's card as a picture to keep in their photos ("Save the card picture"): name, progress and the same QR the cashier scans. --}}
@php
    $progress = match ($state['type']) {
        'points' => __(':n points', ['n' => $state['points']]),
        'stamps' => __('Stamps').': '.$state['stamps'].' / '.$state['required'],
        default => $state['tier'] ?? '',
    };
    $rtl = app()->getLocale() === 'ar';
@endphp
<svg xmlns="http://www.w3.org/2000/svg" width="640" height="1000" viewBox="0 0 640 1000" direction="{{ $rtl ? 'rtl' : 'ltr' }}" font-family="Tahoma, 'Segoe UI', Arial, sans-serif">
    <rect width="640" height="1000" rx="48" fill="{{ $state['color'] }}"/>
    <text x="320" y="110" text-anchor="middle" fill="#ffffff" font-size="40" font-weight="700">{{ $state['brand'] }}</text>
    <text x="320" y="180" text-anchor="middle" fill="#ffffff" font-size="54" font-weight="800">{{ $state['title'] }}</text>
    @if ($progress !== '')
        <text x="320" y="250" text-anchor="middle" fill="#ffffff" fill-opacity="0.9" font-size="36" font-weight="700">{{ $progress }}</text>
    @endif
    @if ($state['name'] !== '')
        <text x="320" y="300" text-anchor="middle" fill="#ffffff" fill-opacity="0.8" font-size="30">{{ $state['name'] }}</text>
    @endif
    <rect x="120" y="340" width="400" height="400" rx="32" fill="#ffffff"/>
    <image x="140" y="360" width="360" height="360" href="{{ $qr }}"/>
    @if ($state['balance'] !== null)
        <text x="320" y="810" text-anchor="middle" fill="#ffffff" font-size="34" font-weight="700">{{ __('My balance') }}: {{ money($state['balance']) }} {{ $state['currency'] }}</text>
    @endif
    @if ($state['tier'] && $state['type'])
        <text x="320" y="860" text-anchor="middle" fill="#ffffff" fill-opacity="0.9" font-size="30">{{ __('Tier') }}: {{ $state['tier'] }}</text>
    @endif
    <text x="320" y="940" text-anchor="middle" fill="#ffffff" fill-opacity="0.85" font-size="20">{{ __('Show the QR to the cashier with every order to collect your stamps.') }}</text>
</svg>
