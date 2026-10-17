{{--
    One table QR card (needs partials.stand-card-css on the page).
    $style, $options (StandStyles::current()['options']), $brand, $logo (url|null), $headline, $table (label|null),
    $qr (svg markup), $url (short menu address|null), $waiter (calls available), $sample (bool, a "sample" ribbon).
--}}
<div class="sc" data-stand-card data-style="{{ $style }}" style="--accent: {{ $options['accent'] }}">
    @if ($sample ?? false)<div class="sc__sample">{{ __('Sample') }}</div>@endif
    <div class="sc__frame"></div>
    <div class="sc__in">
        <div class="sc__head">
            @if ($logo)<img src="{{ $logo }}" alt="" class="sc__logo">@endif
            <div class="sc__brand" data-card-brand>{{ $brand }}</div>
            <div class="sc__headline">{{ $headline }}</div>
        </div>
        <div class="sc__qr">{!! $qr !!}</div>
        @if ($table !== null)<div class="sc__table" data-card-table>{{ $table }}</div>@endif
        <div class="sc__lines">
            @if ($waiter)
                <span class="sc__line" data-line="waiter" @if (! $options['waiter']) hidden @endif>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 16V11a6 6 0 0 1 12 0v5l2 2H4z"/><path d="M10 21h4"/></svg>{{ __('Call the waiter from your phone') }}
                </span>
            @endif
            <span class="sc__line" data-line="payment" @if (! $options['payment']) hidden @endif>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2" y="6" width="20" height="13" rx="2"/><path d="M2 10h20M6 15h3"/></svg>{{ __('Pay cash or InstaPay') }}
            </span>
            <span class="sc__line" data-line="wifi" @if ($options['wifi_name'] === '') hidden @endif>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 9a15 15 0 0 1 20 0M5 12.5a10 10 0 0 1 14 0M8.5 16a5 5 0 0 1 7 0"/><circle cx="12" cy="19.5" r="1"/></svg>
                <span>Wi-Fi: <b dir="auto" data-wifi-name>{{ $options['wifi_name'] }}</b><span data-wifi-pass-wrap @if ($options['wifi_password'] === '') hidden @endif> · {{ __('Password') }}: <b dir="ltr" data-wifi-pass>{{ $options['wifi_password'] }}</b></span></span>
            </span>
            @if ($url)
                <span class="sc__line sc__line--url" data-line="url" @if (! $options['url']) hidden @endif dir="ltr">{{ $url }}</span>
            @endif
        </div>
    </div>
</div>
