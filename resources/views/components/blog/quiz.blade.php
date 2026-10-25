{{--
    A short self-check, from `[[quiz title]] … [[/quiz]]` (App\Support\PostBlocks). Without JavaScript each answer sits
    in a <details>; landing.js gives feedback the moment a choice is made, and a score at the end.
    $id · $title · $questions: [{question, options: [{text, correct}], explanation}]
--}}
@use('App\Support\PostBlocks')
<section class="lp-block" data-bquiz aria-labelledby="{{ $id }}-t">
    <div class="lp-block-head">
        <span class="lp-block-ic" aria-hidden="true"><x-landing.icon name="star" class="size-5" /></span>
        <div>
            <h3 id="{{ $id }}-t" class="lp-block-title">{{ $title !== '' ? $title : 'اختبر نفسك' }}</h3>
            <p class="lp-block-intro">{{ count($questions) }} {{ count($questions) >= 3 && count($questions) <= 10 ? 'أسئلة' : 'سؤال' }}. اختار إجابة وشوف النتيجة على طول.</p>
        </div>
    </div>
    @foreach ($questions as $q => $question)
        <fieldset class="lp-quiz-q" data-bquiz-q>
            <legend>{{ $loop->iteration }}. {{ $question['question'] }}</legend>
            <div class="lp-quiz-options">
                @foreach ($question['options'] as $option)
                    <label for="{{ $id }}-{{ $q }}-{{ $loop->index }}">
                        <input id="{{ $id }}-{{ $q }}-{{ $loop->index }}" type="radio" name="{{ $id }}-{{ $q }}" value="{{ $option['correct'] ? '1' : '0' }}">
                        <span>{{ $option['text'] }}</span>
                    </label>
                @endforeach
            </div>
            <p class="lp-quiz-feedback" data-bquiz-feedback aria-live="polite" hidden></p>
            <details class="lp-quiz-answer" data-bquiz-answer>
                <summary>الإجابة</summary>
                <p><strong>{{ collect($question['options'])->firstWhere('correct', true)['text'] }}</strong>@if ($question['explanation'] !== '') — {!! PostBlocks::inline($question['explanation']) !!}@endif</p>
            </details>
        </fieldset>
    @endforeach
    <p class="lp-quiz-score" data-bquiz-score aria-live="polite" hidden></p>
</section>
