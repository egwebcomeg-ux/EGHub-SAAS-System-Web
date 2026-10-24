{{-- The website assistant ("اسألنا"). Included only when ANTHROPIC_API_KEY is set (App\Services\Assistant::enabled()). --}}
@php
    $asstWa = \App\Services\Assistant::salesWhatsApp();
    $asstL10n = [
        'welcome' => __('Hi 👋 I’m the EgHub assistant. Ask me about plans and prices, the free plan, add-ons, payment, or how your cafe gets started.'),
        'typing' => __('Typing…'),
        'failed' => __('Sorry, something went wrong and I couldn’t answer. You can message our team on WhatsApp.'),
        'tooMany' => __('You’ve asked a lot in a short time. Continue with our team on WhatsApp, or try again a bit later.'),
        'handoffPrompt' => __('Would you like to continue with someone from our team? They’ll reply on WhatsApp.'),
        'handoffButton' => __('Chat with our team on WhatsApp'),
        'handoffText' => __("Hi, I was asking the EgHub assistant on the website:\n:q"),
        'helloText' => __('Hi, I would like to know more about EgHub for my place'),
    ];
    $asstChips = [__('How much are the plans?'), __('What does the free plan include?'), __('Can I buy one module only?'), __('How do I pay?')];
@endphp
<div id="asst" hidden role="dialog" aria-modal="true" aria-labelledby="asst-title" data-endpoint="{{ route('assistant') }}" data-lang="{{ app()->getLocale() }}" data-wa="{{ $asstWa }}"
     data-l10n="{{ json_encode($asstL10n, JSON_UNESCAPED_UNICODE) }}"
     class="fixed inset-0 z-[60] flex flex-col overflow-hidden bg-white shadow-2xl sm:inset-auto sm:bottom-6 sm:end-6 sm:h-[min(620px,calc(100dvh-48px))] sm:w-[400px] sm:rounded-3xl sm:border sm:border-navy-100">
    <header class="flex items-center gap-3 bg-navy-900 px-4 py-3 text-white">
        <img src="{{ versioned_asset('icons/egmenu-192.png') }}" alt="" width="40" height="40" class="size-10 shrink-0 rounded-xl bg-white p-0.5">
        <div class="min-w-0 flex-1">
            <p id="asst-title" class="truncate text-base font-extrabold">{{ __('EgHub assistant') }}</p>
            <p class="text-xs text-white/70">{{ __('Replies instantly · automated assistant') }}</p>
        </div>
        <button type="button" data-asst-reset class="grid size-9 place-items-center rounded-lg bg-white/10 text-lg hover:bg-white/20" title="{{ __('New chat') }}" aria-label="{{ __('New chat') }}">↺</button>
        <button type="button" data-asst-close class="grid size-9 place-items-center rounded-lg bg-white/10 text-xl leading-none hover:bg-white/20" aria-label="{{ __('Close') }}">×</button>
    </header>
    <div data-asst-log class="flex flex-1 flex-col gap-2.5 overflow-y-auto overscroll-contain bg-navy-50 p-4 text-[15px] leading-relaxed" aria-live="polite"></div>
    <div data-asst-chips class="flex flex-wrap gap-1.5 bg-navy-50 px-4 pt-1">
        @foreach ($asstChips as $c)<button type="button" class="rounded-full border border-navy-200 bg-white px-3 py-1.5 text-[13px] font-bold text-navy-800 hover:border-brand-500 hover:text-brand-700">{{ $c }}</button>@endforeach
    </div>
    <form data-asst-form class="flex gap-2 border-t border-navy-100 bg-white px-3 py-2.5">
        <label class="sr-only" for="asst-input">{{ __('Type your question') }}</label>
        <textarea id="asst-input" rows="1" maxlength="600" placeholder="{{ __('Type your question…') }}" class="field max-h-28 min-h-11 flex-1 resize-none py-2.5"></textarea>
        <button type="submit" class="btn-primary min-h-11 px-4" aria-label="{{ __('Send') }}">{{ __('Send') }}</button>
    </form>
    @if ($asstWa !== '')
        <a data-asst-human href="https://wa.me/{{ $asstWa }}" target="_blank" rel="noopener" class="block bg-white px-3 pb-1 text-center text-[13px] font-bold text-emerald-700 hover:text-emerald-800">{{ __('Or message our team on WhatsApp') }}</a>
    @endif
    <p class="bg-white px-3 pb-2.5 text-center text-[11px] text-navy-500">{{ __('The assistant answers from EgHub’s own information; prices come from the live pricing page.') }}</p>
</div>

<script>
/* The website assistant: the conversation lives in this tab (sessionStorage); every turn goes to POST /assistant.
   Anything with data-assistant-open opens the panel instead of WhatsApp. */
(function () {
    'use strict';
    var panel = document.getElementById('asst');
    if (!panel || !window.fetch) return;
    var L = JSON.parse(panel.getAttribute('data-l10n') || '{}');
    var endpoint = panel.getAttribute('data-endpoint');
    var lang = panel.getAttribute('data-lang') || 'ar';
    var waNumber = panel.getAttribute('data-wa') || '';
    var log = panel.querySelector('[data-asst-log]');
    var form = panel.querySelector('[data-asst-form]');
    var input = form.querySelector('textarea');
    var send = form.querySelector('button[type=submit]');
    var chips = panel.querySelector('[data-asst-chips]');
    var human = panel.querySelector('[data-asst-human]');
    var csrf = document.querySelector('meta[name=csrf-token]');
    var KEY = 'egmenu-assistant';
    var busy = false, lastFocus = null;
    var BOT = 'max-w-[86%] self-start rounded-2xl rounded-ss-md border border-navy-100 bg-white px-3.5 py-2.5 text-navy-900 [&_a]:font-bold [&_a]:text-brand-700 [&_a]:underline';
    var ME = 'max-w-[86%] self-end rounded-2xl rounded-se-md bg-brand-600 px-3.5 py-2.5 text-white [&_a]:underline';

    var state = load() || fresh();
    function fresh() { return { id: Math.random().toString(36).slice(2) + Date.now().toString(36), messages: [] }; }
    function load() { try { return JSON.parse(sessionStorage.getItem(KEY) || 'null'); } catch (e) { return null; } }
    function save() { try { sessionStorage.setItem(KEY, JSON.stringify(state)); } catch (e) {} }
    function waLink(text) { return waNumber ? 'https://wa.me/' + waNumber + '?text=' + encodeURIComponent(text) : null; }
    function lastQuestion() {
        for (var i = state.messages.length - 1; i >= 0; i--) if (state.messages[i].role === 'user') return state.messages[i].content;
        return '';
    }
    function refreshHuman() {
        if (!human) return;
        var q = lastQuestion();
        var href = waLink(q ? L.handoffText.replace(':q', q) : L.helloText);
        if (href) human.href = href;
    }
    function render(text) {
        var esc = text.replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; });
        esc = esc.replace(/https?:\/\/[^\s<)]+[^\s<).,،]/g, function (u) { return '<a href="' + u + '" target="_blank" rel="noopener">' + u.replace(/^https?:\/\//, '') + '</a>'; });
        return esc.replace(/\n/g, '<br>');
    }
    function bubble(role, text, extra) {
        var div = document.createElement('div');
        div.className = (role === 'user' ? ME : BOT) + (extra ? ' ' + extra : '');
        div.innerHTML = render(text);
        log.appendChild(div);
        log.scrollTop = log.scrollHeight;
        return div;
    }
    function handoffCard() {
        var href = waLink(L.handoffText.replace(':q', lastQuestion()));
        if (!href) return;
        var div = document.createElement('div');
        div.className = 'self-stretch rounded-2xl border border-dashed border-navy-200 bg-white p-3 text-center text-sm';
        div.innerHTML = '<p class="mb-2 text-navy-800"></p><a class="inline-flex min-h-11 items-center justify-center rounded-xl bg-[#25D366] px-4 font-bold text-white" target="_blank" rel="noopener"></a>';
        div.querySelector('p').innerHTML = render(L.handoffPrompt);
        var a = div.querySelector('a');
        a.href = href;
        a.textContent = L.handoffButton;
        log.appendChild(div);
        log.scrollTop = log.scrollHeight;
    }
    function redraw() {
        log.innerHTML = '';
        bubble('assistant', L.welcome);
        state.messages.forEach(function (m) { bubble(m.role, m.content); if (m.handoff) handoffCard(); });
        chips.hidden = state.messages.length > 0;
        refreshHuman();
    }
    function ask(text) {
        text = (text || '').trim().slice(0, 600);
        if (!text || busy) return;
        busy = true; send.disabled = true; chips.hidden = true;
        state.messages.push({ role: 'user', content: text });
        save();
        bubble('user', text);
        refreshHuman();
        input.value = '';
        var typing = bubble('assistant', L.typing, 'italic text-navy-500');
        fetch(endpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf ? csrf.content : '' },
            body: JSON.stringify({ lang: lang, conversation: state.id, messages: state.messages.map(function (m) { return { role: m.role, content: m.content }; }) })
        }).then(function (r) {
            return r.json().catch(function () { return {}; }).then(function (d) { return { status: r.status, d: d }; });
        }).then(function (res) {
            typing.remove();
            if (res.status === 200 && res.d.reply) {
                state.messages.push({ role: 'assistant', content: res.d.reply, handoff: !!res.d.handoff });
                save();
                bubble('assistant', res.d.reply);
                if (res.d.handoff) handoffCard();
            } else {
                state.messages.pop(); save();
                bubble('assistant', res.status === 429 ? L.tooMany : L.failed, 'border-red-300');
                handoffCard();
            }
        }).catch(function () {
            typing.remove(); state.messages.pop(); save();
            bubble('assistant', L.failed, 'border-red-300');
            handoffCard();
        }).then(function () { busy = false; send.disabled = false; input.focus(); });
    }
    function open() {
        lastFocus = document.activeElement;
        panel.hidden = false;
        document.documentElement.classList.add('asst-open');
        redraw();
        setTimeout(function () { input.focus(); }, 50);
    }
    function close() {
        panel.hidden = true;
        document.documentElement.classList.remove('asst-open');
        if (lastFocus && lastFocus.focus) lastFocus.focus();
    }
    document.addEventListener('click', function (e) {
        var t = e.target.closest('[data-assistant-open]');
        if (t) { e.preventDefault(); open(); }
    });
    panel.querySelector('[data-asst-close]').addEventListener('click', close);
    panel.querySelector('[data-asst-reset]').addEventListener('click', function () { state = fresh(); save(); redraw(); input.focus(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !panel.hidden) close(); });
    form.addEventListener('submit', function (e) { e.preventDefault(); ask(input.value); });
    input.addEventListener('keydown', function (e) { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); ask(input.value); } });
    chips.addEventListener('click', function (e) { var b = e.target.closest('button'); if (b) ask(b.textContent); });
})();
</script>
