// Corporate vouchers, shared by the POS payment dialog (pos.js) and the "redeem vouchers" screen (voucher-redeem.js).

const cents = (n) => Math.round(n * 100) / 100;

/**
 * Earliest expiry first, until the bill is covered (the same rule as the server); a single-use voucher loses the rest.
 * @param {Array<{id:number, balance:number, mode:string, expiresSort:string}>} vouchers
 * @param {number} total the bill
 */
export function planVouchers(vouchers, total) {
    let due = cents(total);
    const lines = [...vouchers].sort((a, b) => a.expiresSort.localeCompare(b.expiresSort) || a.id - b.id).map((v) => {
        const used = Math.min(v.balance, Math.max(0, due));
        due = cents(due - used);
        return { ...v, used, lost: used > 0 && v.mode === 'single' ? cents(v.balance - used) : 0, left: v.mode === 'single' && used > 0 ? 0 : cents(v.balance - used) };
    });
    const covered = cents(lines.reduce((s, l) => s + l.used, 0));
    return { lines, covered, rest: Math.max(0, cents(total - covered)) };
}

export const canScanQr = () => 'BarcodeDetector' in window && !!navigator.mediaDevices?.getUserMedia;

/**
 * Reads one QR code with the back camera into <video> (where the browser has BarcodeDetector, like the loyalty
 * counter); a USB scanner just types into the field. Returns stop().
 */
export async function scanQr(video, onCode, onError) {
    let stream = null;
    const stop = () => { stream?.getTracks().forEach((t) => t.stop()); stream = null; video.classList.add('hidden'); };
    try {
        stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } });
    } catch {
        onError?.();
        return stop;
    }
    video.srcObject = stream;
    video.classList.remove('hidden');
    await video.play();
    const detector = new BarcodeDetector({ formats: ['qr_code'] });
    const tick = async () => {
        if (!stream) return;
        const codes = await detector.detect(video).catch(() => []);
        if (codes.length) { stop(); onCode(codes[0].rawValue); } else requestAnimationFrame(tick);
    };
    tick();
    return stop;
}
