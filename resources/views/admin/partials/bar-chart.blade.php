{{--
    One-series daily bar chart in plain SVG (no chart library). $series: list of [date: Carbon, value: float] from
    Admin\DashboardController::perDay(); $money: values are EGP; $empty: text when every day is zero. Each bar has a
    hover tooltip (<title>) and the numbers are also in a screen-reader table.
--}}
@php
    $money = $money ?? false;
    $values = array_column($series, 'value');
    $total = array_sum($values);
    $max = max(1, ...$values ?: [0]);
    $width = 600;
    $height = 150;
    $top = 18;
    $slot = $width / max(1, count($series));
    $barWidth = max(2, $slot - 3);
    $fmt = fn (float $v) => $money ? money($v) : (string) (int) $v;
@endphp
@if ($total <= 0)
    <div class="grid h-40 place-items-center rounded-xl border border-dashed border-navy-200 text-center text-sm text-navy-500" data-chart-empty>
        <div>
            <svg class="mx-auto mb-1 size-7 text-navy-300" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/></svg>
            {{ $empty }}
        </div>
    </div>
@else
    <figure class="m-0" data-chart>
        <svg viewBox="0 0 {{ $width }} {{ $height + 22 }}" class="block h-auto w-full" dir="ltr" style="direction: ltr" role="img" aria-label="{{ $label }}">
            {{-- recessive grid: the baseline and the max line --}}
            <line x1="0" x2="{{ $width }}" y1="{{ $top }}" y2="{{ $top }}" stroke="currentColor" class="text-navy-100" stroke-dasharray="3 4" />
            <line x1="0" x2="{{ $width }}" y1="{{ $height }}" y2="{{ $height }}" stroke="currentColor" class="text-navy-200" />
            <text x="2" y="{{ $top - 4 }}" class="fill-navy-500" font-size="11">{{ $fmt($max) }}</text>
            @foreach ($series as $i => $point)
                @php
                    $h = $point['value'] > 0 ? max(3, ($point['value'] / $max) * ($height - $top)) : 0;
                    $x = $i * $slot + 1.5;
                @endphp
                <g>
                    <title>{{ local_time($point['date'], 'Y/m/d') }}: {{ $fmt($point['value']) }}{{ $money ? ' '.__('EGP') : '' }}</title>
                    {{-- hit target taller than the mark --}}
                    <rect x="{{ $x }}" y="0" width="{{ $barWidth }}" height="{{ $height }}" fill="transparent" />
                    @if ($h > 0)
                        <rect x="{{ $x }}" y="{{ $height - $h }}" width="{{ $barWidth }}" height="{{ $h }}" rx="{{ min(4, $barWidth / 2) }}" class="fill-brand-600 hover:fill-brand-700" />
                    @endif
                </g>
            @endforeach
            @foreach ([0, intdiv(count($series), 2), count($series) - 1] as $i)
                <text x="{{ $i * $slot + $slot / 2 }}" y="{{ $height + 16 }}" text-anchor="{{ $i === 0 ? 'start' : ($i === count($series) - 1 ? 'end' : 'middle') }}" class="fill-navy-500" font-size="11">{{ $series[$i]['date']->format('d/m') }}</text>
            @endforeach
        </svg>
        <table class="sr-only">
            <caption>{{ $label }}</caption>
            @foreach ($series as $point)
                <tr><td>{{ local_time($point['date'], 'Y/m/d') }}</td><td>{{ $fmt($point['value']) }}</td></tr>
            @endforeach
        </table>
    </figure>
@endif
