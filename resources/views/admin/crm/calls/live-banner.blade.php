{{-- A call on this admin's linked phone: who it is, on the computer (admin.crm.calls.current). --}}
<div class="fixed inset-x-3 bottom-3 z-50 mx-auto hidden max-w-lg rounded-2xl bg-navy-900 p-4 text-white shadow-xl" data-live-call role="status" aria-live="polite"
    data-url="{{ route('admin.crm.calls.current') }}" data-incoming="{{ __('Incoming call') }}" data-outgoing="{{ __('Outgoing call') }}" data-new-number="{{ __('New number') }}">
    <div class="flex items-start justify-between gap-3">
        <div class="min-w-0">
            <div class="text-xs font-semibold text-navy-200" data-live-direction></div>
            <div class="truncate text-lg font-black" data-live-name></div>
            <div class="text-sm text-navy-200" data-live-detail></div>
            <div class="mt-1 line-clamp-2 text-sm text-navy-100" data-live-note></div>
        </div>
        <button type="button" class="min-h-9 shrink-0 rounded-lg px-2 text-navy-200 hover:text-white" data-live-close aria-label="{{ __('Close') }}">✕</button>
    </div>
    <a class="btn-primary mt-3 hidden w-full" data-live-open>{{ __('Open the file') }}</a>
</div>
<script>
    (() => {
        const box = document.querySelector('[data-live-call]');
        // the app itself shows the card on the phone
        if (!box || window.EgHubNative) return;
        const part = (name) => box.querySelector(`[data-live-${name}]`);
        let closedAt = 0;
        let shownAt = 0;
        const show = (call) => {
            if (!call || call.at === closedAt) {
                box.classList.add('hidden');
                return;
            }
            part('direction').textContent = call.direction === 'incoming' ? box.dataset.incoming : box.dataset.outgoing;
            part('name').textContent = call.known ? call.name : (call.phone || box.dataset.newNumber);
            part('detail').textContent = [call.known ? call.phone : box.dataset.newNumber, call.kind, call.stage, call.plan].filter(Boolean).join(' · ');
            part('note').textContent = call.last_note || '';
            part('open').classList.toggle('hidden', !call.url);
            if (call.url) part('open').href = call.url;
            box.classList.remove('hidden');
            shownAt = call.at;
        };
        part('close').addEventListener('click', () => { closedAt = shownAt; box.classList.add('hidden'); });
        const ask = async () => {
            if (document.visibilityState !== 'visible') return;
            try {
                const response = await fetch(box.dataset.url, { headers: { Accept: 'application/json' } });
                if (response.ok) show((await response.json()).call);
            } catch (error) { /* the next round asks again */ }
        };
        setInterval(ask, 5000);
    })();
</script>
