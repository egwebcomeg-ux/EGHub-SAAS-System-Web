// The guest pager (/p/{key}, App\Http\Controllers\Menu\PagerController): the guest's own phone replaces the buzzer.
// The page polls its state every few seconds and rings when staff call (or the order turns ready with auto-call):
// a full-screen alert in the restaurant's colour, a loud chime written in memory (no sound file), the phone
// vibrating and the tab title flashing, until the guest taps "I picked it up" (or silences it until the next call).
// Phones only play sound after a tap, hence "Test the sound". Nothing works once the page is closed: say so honestly.

const root = document.getElementById('pager');
if (root) {
    const T = JSON.parse(root.dataset.i18n);
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
    const pollMs = Number(root.dataset.poll || 5) * 1000;
    const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const $ = (s) => document.querySelector(s);
    const baseTitle = document.title;
    const storeKey = `egmenu.pager.${root.dataset.key}`;
    let state = JSON.parse(root.dataset.state);
    // the call count the guest silenced ("I am on my way"): a newer call rings again
    let silenced = 0;
    try { silenced = Number(sessionStorage.getItem(storeKey) || 0); } catch {}
    let notified = silenced;

    // ---- the chime: three rising bell notes, twice, then a short pause, as a WAV made here ----
    function chimeUrl() {
        const rate = 22050;
        const seconds = 2.6; // the pause before the loop starts again is part of the clip
        const n = Math.floor(rate * seconds);
        const pcm = new Float32Array(n);
        const notes = [[0, 784, 0.22], [0.24, 988, 0.22], [0.48, 1319, 0.42], [1.1, 784, 0.22], [1.34, 988, 0.22], [1.58, 1319, 0.42]];
        for (const [at, freq, length] of notes) {
            const from = Math.floor(at * rate);
            const to = Math.min(n, Math.floor((at + length + 0.25) * rate));
            for (let i = from; i < to; i++) {
                const t = (i - from) / rate;
                const envelope = Math.min(1, t / 0.008) * Math.exp((-3 * t) / length);
                const wave = Math.sin(2 * Math.PI * freq * t) * 0.6 + Math.sin(4 * Math.PI * freq * t) * 0.25 + Math.sign(Math.sin(2 * Math.PI * freq * t)) * 0.15;
                pcm[i] += wave * envelope * 0.95;
            }
        }
        const buffer = new ArrayBuffer(44 + n * 2);
        const view = new DataView(buffer);
        const text = (offset, s) => [...s].forEach((c, i) => view.setUint8(offset + i, c.charCodeAt(0)));
        text(0, 'RIFF'); view.setUint32(4, 36 + n * 2, true); text(8, 'WAVE'); text(12, 'fmt ');
        view.setUint32(16, 16, true); view.setUint16(20, 1, true); view.setUint16(22, 1, true);
        view.setUint32(24, rate, true); view.setUint32(28, rate * 2, true); view.setUint16(32, 2, true); view.setUint16(34, 16, true);
        text(36, 'data'); view.setUint32(40, n * 2, true);
        const samples = new Int16Array(buffer, 44, n);
        for (let i = 0; i < n; i++) samples[i] = Math.max(-1, Math.min(1, pcm[i])) * 32767;

        return URL.createObjectURL(new Blob([buffer], { type: 'audio/wav' }));
    }

    // iPhone: an <audio> element as "playback" plays with the silent switch on (Web Audio would not)
    try { if (navigator.audioSession) navigator.audioSession.type = 'playback'; } catch {}
    const audio = new Audio(chimeUrl());
    audio.preload = 'auto';
    let soundWorks = false;

    async function play(loop) {
        audio.loop = loop;
        try {
            audio.currentTime = 0;
            await audio.play();
            soundWorks = true;
            return true;
        } catch {
            return false;
        }
    }
    function stopSound() {
        audio.loop = false;
        audio.pause();
    }
    const vibrate = (pattern) => { try { navigator.vibrate?.(pattern); } catch {} };

    // ---- keep the screen on while waiting (where the browser allows it) ----
    let wakeLock = null;
    async function keepAwake() {
        if (state.done || document.hidden || wakeLock || !('wakeLock' in navigator)) return;
        try {
            wakeLock = await navigator.wakeLock.request('screen');
            wakeLock.addEventListener('release', () => { wakeLock = null; });
            const note = $('[data-awake]');
            if (note) { note.textContent = T.awake; note.classList.remove('hidden'); }
        } catch {}
    }
    function letSleep() {
        wakeLock?.release().catch(() => {});
        wakeLock = null;
    }

    // ---- "Test the sound": the tap that lets the phone play it later ----
    const testBtn = $('[data-test-sound]');
    const failNote = $('[data-sound-fail]');
    testBtn?.addEventListener('click', async () => {
        const ok = await play(false);
        vibrate([200, 100, 200]);
        failNote.classList.toggle('hidden', ok);
        failNote.textContent = ok ? '' : T.soundFail;
        if (ok) testBtn.textContent = T.soundOn;
        keepAwake();
    });

    // ---- notifications: only if the guest asks for them, never needed ----
    const notifyBtn = $('[data-notify]');
    if (notifyBtn && 'Notification' in window && Notification.permission === 'default' && !state.done) notifyBtn.classList.remove('hidden');
    notifyBtn?.addEventListener('click', async () => {
        try {
            const answer = await Notification.requestPermission();
            if (answer === 'granted') {
                notifyBtn.textContent = T.notifyOn;
                notifyBtn.disabled = true;
            } else {
                notifyBtn.classList.add('hidden');
            }
        } catch {
            notifyBtn.classList.add('hidden');
        }
    });
    async function notify() {
        if (!('Notification' in window) || Notification.permission !== 'granted') return;
        const title = T.ready;
        const options = { body: `${T.number.replace('__N__', state.number)} · ${T.come}`, tag: 'egmenu-pager', renotify: true, requireInteraction: true, vibrate: [400, 150, 400] };
        try {
            const reg = await navigator.serviceWorker?.getRegistration();
            if (reg) return await reg.showNotification(title, options);
        } catch {}
        try { new Notification(title, options); } catch {}
    }

    // ---- the alert ----
    const alertBox = $('[data-alert]');
    const tapHint = $('[data-tap-hint]');
    let ringing = false;
    let titleTimer = null;
    let buzzTimer = null;
    function startAlert() {
        if (state.calls > notified) {
            notified = state.calls;
            if (document.hidden) notify();
        }
        if (ringing) return;
        ringing = true;
        alertBox.hidden = false;
        alertBox.classList.toggle('pg-alert-flash', !reduce);
        play(true).then((ok) => tapHint.classList.toggle('hidden', ok));
        const buzz = () => vibrate([500, 150, 500, 150, 900]);
        buzz();
        buzzTimer = setInterval(buzz, 3500);
        titleTimer = setInterval(() => { document.title = document.title === T.titleReady ? baseTitle : T.titleReady; }, 900);
    }
    function stopAlert() {
        if (!ringing) return;
        ringing = false;
        alertBox.hidden = true;
        stopSound();
        vibrate(0);
        clearInterval(buzzTimer);
        clearInterval(titleTimer);
        document.title = baseTitle;
    }
    // the sound was blocked (no tap yet): any tap on the alert starts it
    alertBox.addEventListener('click', (e) => {
        if (!ringing || e.target.closest('button')) return;
        play(true).then((ok) => tapHint.classList.toggle('hidden', ok));
    });
    $('[data-silence]').addEventListener('click', () => {
        silenced = state.calls;
        try { sessionStorage.setItem(storeKey, String(silenced)); } catch {}
        stopAlert();
    });
    $('[data-picked]').addEventListener('click', async (e) => {
        const btn = e.currentTarget;
        btn.disabled = true;
        stopAlert();
        try {
            const res = await fetch(root.dataset.pickedUrl, { method: 'POST', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf } });
            if (res.ok) state = await res.json();
        } catch {}
        btn.disabled = false;
        render();
    });

    // ---- redraw from the state ----
    const live = $('[data-live]');
    function render() {
        $('[data-headline]').textContent = state.headline;
        const hint = $('[data-hint]');
        hint.textContent = state.hint;
        hint.hidden = !state.hint;
        state.steps.forEach((s) => {
            const li = root.querySelector(`[data-step="${s.key}"]`);
            li?.classList.toggle('is-done', s.done);
            li?.classList.toggle('is-current', s.current);
        });
        $('[data-live-box]').hidden = state.done;
        $('[data-waiting]').hidden = state.done;
        const rate = $('[data-rate]');
        if (rate) rate.hidden = !state.picked_up;
        if (!state.done && state.calls > silenced) startAlert();
        else stopAlert();
        if (state.done) letSleep();
    }

    // ---- polling: every few seconds while open, slower while hidden, at once when the guest comes back ----
    let timer = null;
    let failures = 0;
    async function tick() {
        timer = null;
        try {
            const res = await fetch(root.dataset.stateUrl, { headers: { Accept: 'application/json' }, cache: 'no-store' });
            if (res.status === 404) return; // the pager is gone (plan changed): stop asking
            if (!res.ok) throw new Error(String(res.status));
            state = await res.json();
            failures = 0;
            live?.classList.remove('is-off');
            if (live) live.textContent = T.live;
            render();
        } catch {
            failures++;
            live?.classList.add('is-off');
            if (live) live.textContent = T.offline;
        }
        if (!state.done) schedule();
    }
    function schedule() {
        clearTimeout(timer);
        const base = document.hidden ? pollMs * 3 : pollMs;
        timer = setTimeout(tick, Math.min(60000, base * 2 ** Math.min(failures, 3)));
    }
    document.addEventListener('visibilitychange', () => {
        if (document.hidden || state.done) return;
        keepAwake();
        clearTimeout(timer);
        tick();
    });

    // ---- one-tap rating after the pickup: 4-5 → Google, 1-3 → a private note to the restaurant ----
    const rateBox = $('[data-rate]');
    if (rateBox) {
        let rating = 0;
        const done = (message, google) => {
            rateBox.querySelector('[data-stars]').classList.add('hidden');
            rateBox.querySelector('[data-rate-note]').classList.add('hidden');
            rateBox.querySelector('[data-rate-done]').classList.remove('hidden');
            if (message) rateBox.querySelector('[data-rate-thanks]').textContent = message;
            const link = rateBox.querySelector('[data-google]');
            if (google) {
                link.href = google;
                link.classList.remove('hidden');
            }
        };
        async function send(comment) {
            try {
                const res = await fetch(root.dataset.rateUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
                    body: JSON.stringify({ rating, comment }),
                });
                const data = await res.json().catch(() => ({}));
                done(res.status === 429 ? T.tooMany : T.sent, data.google || null);
            } catch {
                done(T.sent, null);
            }
        }
        rateBox.querySelectorAll('[data-star]').forEach((b) => b.addEventListener('click', () => {
            rating = Number(b.dataset.star);
            rateBox.querySelectorAll('[data-star]').forEach((s) => s.classList.toggle('is-on', Number(s.dataset.star) <= rating));
            if (rating >= 4) return send('');
            rateBox.querySelector('[data-rate-note]').classList.remove('hidden');
            rateBox.querySelector('[data-comment]').focus();
        }));
        rateBox.querySelector('[data-send-note]').addEventListener('click', (e) => {
            e.currentTarget.disabled = true;
            send(rateBox.querySelector('[data-comment]').value);
        });
    }

    render();
    keepAwake();
    if (!state.done) schedule();
}
