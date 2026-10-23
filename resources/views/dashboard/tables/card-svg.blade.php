{{-- One table card as a standalone A6 SVG (units: 0.1 mm). Same design as partials.stand-card, flat for printing. --}}
@php
    $w = 1050;
    $h = 1480;
    $accent = $design['options']['accent'];
    $navy = $design['style'] === 'navy';
    $font = "Cairo, Tajawal, 'Segoe UI', Tahoma, Arial, sans-serif";
    $y = 120;
    if ($logo) {
        $logoY = $y;
        $y += 125;
    }
    $brandSize = \App\Support\StandStyles::fitFont($brand, 60, 820);
    $y += $brandSize;
    $brandY = $y;
    $headlineSize = \App\Support\StandStyles::fitFont($headline, 68, 840);
    $y += 30 + $headlineSize;
    $headlineY = $y;
    $qrSize = $logo ? 500 : 580;
    $pad = 34;
    $panel = $qrSize + 2 * $pad;
    $panelX = ($w - $panel) / 2;
    $panelY = $y + 50;
    $y = $panelY + $panel;
    $tableSize = \App\Support\StandStyles::fitFont($tableLabel, 90, 820);
    $tableY = $y + 40 + $tableSize;
    $lineY = $tableY + 80;
@endphp
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" version="1.1" width="105mm" height="148mm" viewBox="0 0 {{ $w }} {{ $h }}" direction="{{ ($locale ?? app()->getLocale()) === 'ar' ? 'rtl' : 'ltr' }}">
    <title>{{ $brand }} · {{ $tableLabel }}</title>
    <defs>
        <linearGradient id="bg" x1="0" y1="0" x2="0.35" y2="1">
            <stop offset="0" stop-color="{{ $palette['bg1'] }}"/>
            <stop offset="1" stop-color="{{ $palette['bg2'] }}"/>
        </linearGradient>
        @if ($design['style'] === 'wood')
            <pattern id="grain" width="60" height="1480" patternUnits="userSpaceOnUse" patternTransform="rotate(7)">
                <rect x="0" y="0" width="7" height="1480" fill="#78481E" fill-opacity="0.07"/>
                <rect x="26" y="0" width="4" height="1480" fill="#78481E" fill-opacity="0.05"/>
            </pattern>
        @endif
    </defs>
    <rect x="0" y="0" width="{{ $w }}" height="{{ $h }}" fill="url(#bg)"/>
    @if ($design['style'] === 'wood')<rect x="0" y="0" width="{{ $w }}" height="{{ $h }}" fill="url(#grain)"/>@endif
    <rect x="52" y="52" width="{{ $w - 104 }}" height="{{ $h - 104 }}" rx="42" fill="none" stroke="{{ $accent }}" stroke-width="7"/>

    @if ($logo)
        @if ($navy)<rect x="{{ $w / 2 - 170 }}" y="{{ $logoY - 14 }}" width="340" height="128" rx="20" fill="#FFFFFF"/>@endif
        <image x="{{ $w / 2 - 155 }}" y="{{ $logoY }}" width="310" height="100" preserveAspectRatio="xMidYMid meet" href="{{ $logo }}" xlink:href="{{ $logo }}"/>
    @endif
    <text x="{{ $w / 2 }}" y="{{ $brandY }}" text-anchor="middle" font-family="{{ $font }}" font-size="{{ $brandSize }}" font-weight="800" fill="{{ $palette['ink'] }}">{{ $brand }}</text>
    <text x="{{ $w / 2 }}" y="{{ $headlineY }}" text-anchor="middle" font-family="{{ $font }}" font-size="{{ $headlineSize }}" font-weight="900" fill="{{ $navy ? '#FFFFFF' : $accent }}">{{ $headline }}</text>

    <rect x="{{ $panelX }}" y="{{ $panelY }}" width="{{ $panel }}" height="{{ $panel }}" rx="36" fill="#FFFFFF" stroke="{{ $accent }}" stroke-width="7"/>
    {!! \App\Support\StandStyles::nestSvg($qr, $panelX + $pad, $panelY + $pad, $qrSize) !!}

    <text x="{{ $w / 2 }}" y="{{ $tableY }}" text-anchor="middle" font-family="{{ $font }}" font-size="{{ $tableSize }}" font-weight="900" fill="{{ $navy ? $accent : $palette['ink'] }}">{{ $tableLabel }}</text>
    @foreach ($lines as $i => $line)
        <text x="{{ $w / 2 }}" y="{{ $lineY + $i * 48 }}" text-anchor="middle" font-family="{{ $font }}" font-size="{{ \App\Support\StandStyles::fitFont($line, 36, 820) }}" font-weight="600" fill="{{ $palette['muted'] }}">{{ $line }}</text>
    @endforeach
    @if ($url)
        <text x="{{ $w / 2 }}" y="{{ $lineY + count($lines) * 48 }}" text-anchor="middle" direction="ltr" font-family="{{ $font }}" font-size="{{ \App\Support\StandStyles::fitFont($url, 32, 820) }}" font-weight="600" fill="{{ $palette['muted'] }}">{{ $url }}</text>
    @endif
</svg>
