@php
    $rtl = app()->getLocale() === 'ar';
    $color = preg_match('/^#[0-9a-fA-F]{6}$/', $place['color'] ?? '') ? $place['color'] : '#0A2540';
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="color-scheme" content="light">
<title>{{ $place['name'] }}</title>
</head>
<body style="margin:0;padding:0;background:#f4f5f7;font-family:Tahoma,Arial,sans-serif;color:#1f2933;direction:{{ $rtl ? 'rtl' : 'ltr' }};text-align:{{ $rtl ? 'right' : 'left' }};">
<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="background:#f4f5f7;padding:24px 12px;">
<tr><td align="center">
<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="max-width:480px;background:#ffffff;border-radius:16px;overflow:hidden;">
    <tr><td align="center" style="padding:28px 24px 12px;">
        @if ($place['logo'])
            <img src="{{ $place['logo'] }}" alt="{{ $place['name'] }}" width="72" height="72" style="display:block;width:72px;height:72px;object-fit:contain;border-radius:14px;">
        @endif
        <p style="margin:12px 0 0;font-size:18px;font-weight:bold;color:{{ $color }};">{{ $place['name'] }}</p>
    </td></tr>
    <tr><td style="padding:8px 28px 4px;font-size:16px;line-height:1.7;">
        @if ($kind === 'reset')
            <p style="margin:0 0 12px;">{{ __('You asked to choose a new password for your account at :place.', ['place' => $place['name']]) }}</p>
        @elseif ($kind === 'confirm')
            <p style="margin:0 0 12px;">{{ __('Thanks for your order from :place! Confirm your email so next time you sign in with it and find your address saved.', ['place' => $place['name']]) }}</p>
        @else
            <p style="margin:0 0 12px;">{{ __('Tap the button to sign in to your account at :place.', ['place' => $place['name']]) }}</p>
        @endif
    </td></tr>
    <tr><td align="center" style="padding:8px 28px 16px;">
        <a href="{{ $url }}" style="display:inline-block;background:{{ $color }};color:#ffffff;text-decoration:none;font-weight:bold;font-size:16px;padding:14px 28px;border-radius:12px;">
            {{ $kind === 'reset' ? __('Choose a new password') : ($kind === 'confirm' ? __('Confirm my email') : __('Sign in to my account')) }}
        </a>
    </td></tr>
    @if ($code)
        <tr><td align="center" style="padding:0 28px 16px;">
            <p style="margin:0 0 6px;font-size:14px;color:#52606d;">{{ __('Or type this code on the page you came from:') }}</p>
            <p dir="ltr" style="margin:0;font-size:32px;font-weight:bold;letter-spacing:8px;color:#1f2933;">{{ $code }}</p>
        </td></tr>
    @endif
    <tr><td style="padding:4px 28px 24px;font-size:13px;line-height:1.7;color:#52606d;">
        <p style="margin:0;">{{ $kind === 'confirm' ? __('If you did not order from :place, ignore this email.', ['place' => $place['name']]) : __('The link works once, for :minutes minutes. If you did not ask for this, ignore this email: nobody can get in without it.', ['minutes' => $minutes]) }}</p>
    </td></tr>
</table>
<p style="margin:16px 0 0;font-size:12px;color:#9aa5b1;">{{ $place['name'] }} · <a href="{{ $place['menu'] }}" style="color:#9aa5b1;">{{ __('The menu') }}</a></p>
</td></tr>
</table>
</body>
</html>
