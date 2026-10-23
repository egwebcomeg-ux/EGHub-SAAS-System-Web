{{--
    The knowledge-base drawer of the staff screens (call center, POS, order board): instant search over the shown
    answers, internal and public, loaded once from dash.kb.feed (resources/js/kb-drawer.js). Any [data-kb-open] button
    of the page opens it; F1 too. Include only when App\Services\KnowledgeBase::canOpen() is true.
--}}
@php
    $kbI18n = [
        'all' => __('All'), 'loading' => __('Loading…'), 'failed' => __('The answers could not be loaded. Try again.'),
        'retry' => __('Try again'), 'none' => __('No answers yet.'), 'noMatch' => __('No answer matches. Try another word.'),
        'copy' => __('Copy the answer'), 'copied' => __('Copied ✓'), 'public' => __('On the menu'), 'internal' => __('Staff only'),
        'count' => __(':n answers', ['n' => '__N__']),
    ];
@endphp
<dialog class="m-0 ms-auto h-dvh max-h-none w-full max-w-md bg-white p-0 text-navy-900 shadow-2xl backdrop:bg-navy-900/40" data-kb-drawer
        data-kb-url="{{ route('dash.kb.feed') }}" data-kb-i18n="{{ json_encode($kbI18n, JSON_UNESCAPED_UNICODE) }}" aria-labelledby="kb-drawer-title">
    <div class="flex h-full flex-col">
        <div class="flex items-center gap-2 border-b border-navy-100 px-4 py-3">
            <h2 id="kb-drawer-title" class="min-w-0 flex-1 text-lg font-extrabold">📖 {{ __('Knowledge base') }}</h2>
            @can('kb.manage')
                <a href="{{ route('dash.kb') }}" class="btn-ghost min-h-9 px-3 text-sm" target="_blank" rel="noopener">{{ __('Edit the answers') }}</a>
            @endcan
            <form method="dialog">
                <button class="grid size-11 place-items-center rounded-xl text-navy-600 hover:bg-navy-50" aria-label="{{ __('Close') }}">
                    <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
            </form>
        </div>
        <div class="space-y-2 border-b border-navy-100 px-4 py-3">
            <input type="search" class="field" data-kb-q dir="auto" autocomplete="off" maxlength="80"
                   placeholder="{{ __('Search: delivery, hours, parking, allergy…') }}" aria-label="{{ __('Search the answers') }}">
            <div class="-mx-4 flex gap-2 overflow-x-auto px-4 pb-1 [scrollbar-width:none]" data-kb-cats></div>
        </div>
        <div class="min-h-0 flex-1 space-y-2 overflow-y-auto px-4 py-3" data-kb-list aria-live="polite"></div>
        <p class="border-t border-navy-100 px-4 py-2 text-xs text-navy-500"><kbd class="rounded border border-navy-200 bg-navy-50 px-1.5 font-mono" dir="ltr">F1</kbd> {{ __('opens this list, Esc closes it.') }} <span data-kb-count></span></p>
    </div>
</dialog>
