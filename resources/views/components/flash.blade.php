@props(['toast' => false])
{{--
    Flash messages. With :toast (the restaurant dashboard) the "ok" message is a toast at the top that hides by
    itself (resources/js/dash-ui.js), with an optional action link from session('ok_action') = [label, url];
    the toast box is always there so scripts can show their own toasts in it. Errors always stay in the page.
--}}
@if ($toast)
    <div class="dash-toasts" data-dash-toasts aria-live="polite" data-close-label="{{ __('Close') }}">
        @if (session('ok'))
            <div class="dash-toast" role="status" data-flash-toast>
                <svg class="size-5 shrink-0 text-emerald-300" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>
                <span>{{ session('ok') }}</span>
                @if (is_array($action = session('ok_action')) && isset($action[0], $action[1]))
                    <a href="{{ $action[1] }}" class="shrink-0" data-flash-action>{{ $action[0] }}</a>
                @endif
                <button type="button" data-toast-close aria-label="{{ __('Close') }}">
                    <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
            </div>
        @endif
    </div>
@elseif (session('ok'))
    <div class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-800" role="status">{{ session('ok') }}</div>
@endif
@if ($errors->any())
    <div class="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800" role="alert">
        @foreach ($errors->all() as $error)
            <div>{{ $error }}</div>
        @endforeach
    </div>
@endif
