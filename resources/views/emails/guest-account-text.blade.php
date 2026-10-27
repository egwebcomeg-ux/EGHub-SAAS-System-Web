{{ $place['name'] }}

{{ $kind === 'reset' ? __('You asked to choose a new password for your account at :place.', ['place' => $place['name']]) : ($kind === 'confirm' ? __('Thanks for your order from :place! Confirm your email so next time you sign in with it and find your address saved.', ['place' => $place['name']]) : __('Tap the button to sign in to your account at :place.', ['place' => $place['name']])) }}

{{ $url }}
@if ($code)

{{ __('Or type this code on the page you came from:') }} {{ $code }}
@endif

{{ __('The link works once, for :minutes minutes. If you did not ask for this, ignore this email: nobody can get in without it.', ['minutes' => $minutes]) }}
