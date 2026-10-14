@props(['steps', 'w' => 640, 'h' => 400, 'ms' => [], 'label' => ''])
{{--
    Silent workflow animation (player in resources/js/landing.js). The slot is the stage, drawn at $w × $h and
    scaled to fit; its elements use data-show / data-on with step ranges. $steps = [[label, one line], ...] are the
    captions; $ms optionally sets each step's duration.
--}}
<figure {{ $attributes->merge(['class' => 'sc']) }} data-scene aria-label="{{ $label }}">
    <div class="sc-wrap" data-stage-wrap style="aspect-ratio: {{ $w }} / {{ $h }}">
        <div class="sc-stage" data-stage data-w="{{ $w }}" data-h="{{ $h }}" style="width: {{ $w }}px; height: {{ $h }}px" aria-hidden="true">
            {{ $slot }}
        </div>
        <span class="sc-pause pointer-events-none absolute top-3 end-3 z-30 rounded-full bg-navy-900/80 px-2.5 py-1 text-xs font-bold text-white">{{ __('Paused') }}</span>
    </div>
    <figcaption class="mt-4">
        <div class="sc-steps" style="--n: {{ count($steps) }}">
            @foreach ($steps as $i => [$stepLabel, $stepText])
                <button type="button" class="sc-step" data-step="{{ $i + 1 }}" data-ms="{{ $ms[$i] ?? 2800 }}" aria-label="{{ __('Step :n of :total', ['n' => $i + 1, 'total' => count($steps)]) }}: {{ $stepLabel }}">
                    <span class="sc-bar"><i></i></span>
                    <span class="tabular-nums">{{ $i + 1 }}</span><span class="sc-label"> · {{ $stepLabel }}</span>
                </button>
            @endforeach
        </div>
        <div class="sc-text mt-1">
            @foreach ($steps as $i => [$stepLabel, $stepText])
                <p data-step-text="{{ $i + 1 }}" @if ($i > 0) hidden @endif class="text-[0.95rem] leading-relaxed text-navy-600"><b class="font-extrabold text-navy-900">{{ $stepLabel }}.</b> {{ $stepText }}</p>
            @endforeach
        </div>
    </figcaption>
</figure>
