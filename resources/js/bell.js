// The new-order alert: the loudest bell a page can make with Web Audio (no sound file to load). A hammer strikes
// the bell 18 times a second; each strike sounds the bell's uneven overtones in square waves (the 2–4 kHz range
// the ear hears best), and a hard clipper holds the whole ring at full volume so it cuts through a busy kitchen.
const STRIKES_PER_SECOND = 18;
const BASE_HZ = 1050;
// [overtone ratio, loudness] of a struck metal bell
const PARTIALS = [[0.5, 0.5], [1, 1], [2.76, 0.7], [3.6, 0.45]];
const DRIVE = 6;

let clipCurve = null;
function clipper(ctx) {
    if (!clipCurve) {
        clipCurve = new Float32Array(1024);
        for (let i = 0; i < clipCurve.length; i++) {
            const x = (i / (clipCurve.length - 1)) * 2 - 1;
            clipCurve[i] = Math.max(-1, Math.min(1, x * DRIVE));
        }
    }
    const shaper = ctx.createWaveShaper();
    shaper.curve = clipCurve;
    return shaper;
}

export function ringBell(ctx, seconds = 2.5) {
    if (!ctx) return;
    const shaper = clipper(ctx);
    const master = ctx.createGain();
    master.gain.value = 0.98;
    shaper.connect(master).connect(ctx.destination);
    const bus = ctx.createGain();
    bus.gain.value = 0.25;
    bus.connect(shaper);

    const start = ctx.currentTime + 0.01;
    const end = start + seconds;
    PARTIALS.forEach(([ratio, level]) => {
        const osc = ctx.createOscillator();
        const gain = ctx.createGain();
        osc.type = 'square';
        osc.frequency.value = BASE_HZ * ratio;
        gain.gain.setValueAtTime(0, start);
        // each hammer strike: full at once, sinking only to 60% before the next one (the bell never goes quiet)
        for (let at = start; at < end; at += 1 / STRIKES_PER_SECOND) {
            gain.gain.setValueAtTime(level, at);
            gain.gain.linearRampToValueAtTime(level * 0.6, at + 1 / STRIKES_PER_SECOND - 0.002);
        }
        gain.gain.setValueAtTime(level * 0.6, end);
        gain.gain.linearRampToValueAtTime(0, end + 0.05);
        osc.connect(gain).connect(bus);
        osc.start(start);
        osc.stop(end + 0.06);
    });
}
