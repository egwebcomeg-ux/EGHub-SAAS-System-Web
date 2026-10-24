{{-- A real, scannable QR code (Bacon's encoder, as in the free QR tool) drawn as one SVG path, for the share cards: $data, $size, $color. --}}
@php
    $size ??= 200;
    $color ??= '#0A2540';
    $matrix = \BaconQrCode\Encoder\Encoder::encode($data, \BaconQrCode\Common\ErrorCorrectionLevel::M(), \BaconQrCode\Encoder\Encoder::DEFAULT_BYTE_MODE_ENCODING)->getMatrix();
    $modules = $matrix->getWidth();
    $qrPath = '';
    for ($y = 0; $y < $modules; $y++) {
        for ($x = 0; $x < $modules; $x++) {
            if ($matrix->get($x, $y) === 1) {
                $qrPath .= "M{$x} {$y}h1v1h-1z";
            }
        }
    }
@endphp
<svg width="{{ $size }}" height="{{ $size }}" viewBox="-1 -1 {{ $modules + 2 }} {{ $modules + 2 }}" shape-rendering="crispEdges" aria-hidden="true"><rect x="-1" y="-1" width="{{ $modules + 2 }}" height="{{ $modules + 2 }}" fill="#fff"/><path d="{{ $qrPath }}" fill="{{ $color }}"/></svg>
