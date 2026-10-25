{{--
    A blog calculator (App\Support\BlogCalculators), from a `[[calculator name]]` line (App\Support\PostBlocks).
    Without JavaScript it reads as a worked example: the default numbers, the results computed on the server and the
    formula. landing.js shows the inputs and recomputes as the reader types.
    $id · $name · $calculator: BlogCalculators::all()[$name] · $example: BlogCalculators::compute($name)
--}}
@use('App\Support\BlogCalculators')
<section class="lp-block" data-bcalc="{{ $name }}" aria-labelledby="{{ $id }}-t">
    <div class="lp-block-head">
        <span class="lp-block-ic" aria-hidden="true"><x-landing.icon name="percent" class="size-5" /></span>
        <div>
            <h3 id="{{ $id }}-t" class="lp-block-title">{{ $calculator['title'] }}</h3>
            <p class="lp-block-intro">{{ $calculator['intro'] }}</p>
        </div>
    </div>

    <div class="lp-calc-inputs" data-bcalc-inputs hidden>
        @foreach ($calculator['inputs'] as $key => $input)
            <div>
                <label for="{{ $id }}-{{ $key }}" class="lp-block-label">{{ $input['label'] }}</label>
                <div class="lp-calc-field">
                    <input id="{{ $id }}-{{ $key }}" name="{{ $key }}" type="number" inputmode="decimal" step="{{ $input['step'] }}" @if ($key !== 'change') min="0" @endif value="{{ BlogCalculators::format($input['default'], 2) }}" dir="ltr">
                    <span aria-hidden="true">{{ $input['suffix'] }}</span>
                </div>
            </div>
        @endforeach
    </div>

    <p class="lp-block-note" data-bcalc-static>
        <strong>مثال بالأرقام:</strong>
        @foreach ($calculator['inputs'] as $input)
            {{ $input['label'] }} {{ BlogCalculators::format($input['default'], 2) }} {{ $input['suffix'] }}@if (! $loop->last)، @endif
        @endforeach
    </p>

    <dl class="lp-calc-out" aria-live="polite" data-bcalc-out>
        @foreach ($calculator['outputs'] as $key => $output)
            <div class="{{ $loop->first ? 'is-main' : '' }}">
                <dt>{{ $output['label'] }}</dt>
                <dd><span data-out="{{ $key }}" data-decimals="{{ $output['decimals'] }}">{{ BlogCalculators::format($example[$key] ?? null, $output['decimals']) }}</span> <small>{{ $output['suffix'] }}</small></dd>
            </div>
        @endforeach
    </dl>

    <p class="lp-block-note"><strong>المعادلة:</strong> {{ $calculator['formula'] }}</p>
    <p class="lp-block-note" data-bcalc-hint hidden>الأرقام اللي فوق مثال. غيّرها بأرقام محلك والنتيجة بتتحسب على طول.</p>
</section>
