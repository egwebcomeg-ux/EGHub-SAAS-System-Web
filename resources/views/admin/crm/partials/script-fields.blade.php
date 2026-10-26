{{-- title, text and (for a question) the answer options of one script item --}}
@php($uid = $item?->id ?? 'new-'.$kind)
<div class="grid gap-3">
    <div>
        <label class="field-label" for="s-title-{{ $uid }}">{{ $kind === 'objection' ? __('What they say') : __('Title') }}</label>
        <input id="s-title-{{ $uid }}" name="title" maxlength="120" class="field" value="{{ $item?->title }}" required>
    </div>
    <div>
        <label class="field-label" for="s-body-{{ $uid }}">{{ match ($kind) { 'question' => __('The question'), 'objection' => __('The reply'), default => __('What to say') } }}</label>
        <textarea id="s-body-{{ $uid }}" name="body" rows="{{ $kind === 'question' ? 2 : 5 }}" maxlength="4000" class="field py-2 leading-7" required>{{ $item?->body }}</textarea>
    </div>
    @if ($kind === 'question')
        <div class="space-y-2" data-options>
            <div class="text-sm font-bold text-navy-800">{{ __('Answers (empty rows are ignored)') }}</div>
            @foreach ($rows as $i => $option)
                <div class="grid gap-2 rounded-xl border border-navy-100 bg-navy-50/40 p-3 md:grid-cols-[minmax(0,1fr)_minmax(0,2fr)_minmax(0,1fr)]" data-option-row>
                    <input type="hidden" name="options[{{ $i }}][key]" value="{{ $option['key'] }}">
                    <input name="options[{{ $i }}][label]" maxlength="80" class="field" value="{{ $option['label'] }}" placeholder="{{ __('Answer') }}" aria-label="{{ __('Answer') }}">
                    <textarea name="options[{{ $i }}][reply]" rows="2" maxlength="1000" class="field py-2" placeholder="{{ __('What to reply') }}" aria-label="{{ __('What to reply') }}">{{ $option['reply'] }}</textarea>
                    <select name="options[{{ $i }}][signal]" class="field" aria-label="{{ __('Signal for the offer') }}">
                        @foreach (\App\Models\CallScriptItem::SIGNALS as $signal => $_)
                            <option value="{{ $signal }}" @selected($option['signal'] === $signal)>{{ \App\Models\CallScriptItem::signalLabel($signal) }}</option>
                        @endforeach
                    </select>
                </div>
            @endforeach
        </div>
    @endif
</div>
