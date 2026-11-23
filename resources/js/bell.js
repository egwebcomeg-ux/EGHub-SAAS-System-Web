// The new-order alert: a loud electric bell made with Web Audio (no sound file to load). A hammer strikes the bell
// 18 times a second; each strike sounds the bell's own uneven overtones, so it cuts through a busy kitchen.
const STRIKES_PER_SECOND = 18;
const BASE_HZ = 1050;
// [overtone ratio, loudness] of a struck metal bell
const PARTIALS = [[0.5, 0.18], [1, 0.42], [2.76, 0.26], [5.4, 0.14]];

export function ringBell(ctx, seconds = 1.5) {
    if (!ctx) return;
    const master = ctx.createGain();
    master.gain.value = 1;
    master.connect(ctx.destination);
    const start = ctx.currentTime + 0.01;
    const strikes = Math.round(seconds * STRIKES_PER_SECOND);
    for (let i = 0; i < strikes; i++) {
        const at = start + i / STRIKES_PER_SECOND;
        PARTIALS.forEach(([ratio, level]) => {
            const osc = ctx.createOscillator();
            const gain = ctx.createGain();
            osc.type = 'triangle';
            osc.frequency.value = BASE_HZ * ratio;
            gain.gain.setValueAtTime(0.0001, at);
            gain.gain.exponentialRampToValueAtTime(level, at + 0.004);
            gain.gain.exponentialRampToValueAtTime(0.0001, at + 0.11);
            osc.connect(gain).connect(master);
            osc.start(at);
            osc.stop(at + 0.12);
        });
    }
}
