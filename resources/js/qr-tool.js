/*
 * The free QR code generator (/qr-code-generator). The page builds the payload from the chosen content type, asks the
 * server for the module matrix (POST /qr-code-generator/matrix, BaconQrCode; no JS QR library ships with the site) and
 * draws the code here as SVG with the chosen shapes, colours, logo and frame. Downloads: SVG, PNG (SVG on a canvas)
 * and a print-ready A4 page (window.print). The logo stays in the browser: it is never sent anywhere.
 */
import '../css/qr-tool.css';

/* ---------------------------------------------------------------- geometry ---------------------------------------------------------------- */

const r3 = (n) => Math.round(n * 1000) / 1000;

/** A rectangle with its own radius per corner [top-left, top-right, bottom-right, bottom-left]. */
function rrect(x, y, w, h, radii) {
    const [tl, tr, br, bl] = typeof radii === 'number' ? [radii, radii, radii, radii] : radii;
    let d = `M${r3(x + tl)} ${r3(y)}H${r3(x + w - tr)}`;
    if (tr) {
        d += `A${tr} ${tr} 0 0 1 ${r3(x + w)} ${r3(y + tr)}`;
    }
    d += `V${r3(y + h - br)}`;
    if (br) {
        d += `A${br} ${br} 0 0 1 ${r3(x + w - br)} ${r3(y + h)}`;
    }
    d += `H${r3(x + bl)}`;
    if (bl) {
        d += `A${bl} ${bl} 0 0 1 ${r3(x)} ${r3(y + h - bl)}`;
    }
    d += `V${r3(y + tl)}`;
    if (tl) {
        d += `A${tl} ${tl} 0 0 1 ${r3(x + tl)} ${r3(y)}`;
    }
    return d + 'Z';
}

const circle = (cx, cy, r) => `M${r3(cx - r)} ${r3(cy)}a${r} ${r} 0 1 0 ${r3(2 * r)} 0a${r} ${r} 0 1 0 ${r3(-2 * r)} 0Z`;
const diamond = (cx, cy, h) => `M${r3(cx)} ${r3(cy - h)}L${r3(cx + h)} ${r3(cy)}L${r3(cx)} ${r3(cy + h)}L${r3(cx - h)} ${r3(cy)}Z`;

/**
 * The data modules as one path. `on(x, y)` says whether a module is dark and drawn (finder patterns and the area behind
 * the logo are left out), `light(x, y)` whether it is a visible light module (where "liquid" joins may round a corner).
 */
function bodyPath(n, shape, on, light) {
    let d = '';
    if (shape === 'vlines' || shape === 'hlines') {
        const vertical = shape === 'vlines';
        for (let a = 0; a < n; a++) {
            let start = -1;
            for (let b = 0; b <= n; b++) {
                const dark = b < n && (vertical ? on(a, b) : on(b, a));
                if (dark && start < 0) {
                    start = b;
                } else if (!dark && start >= 0) {
                    const len = b - start;
                    d += vertical ? rrect(a + 0.1, start + 0.06, 0.8, len - 0.12, 0.4) : rrect(start + 0.06, a + 0.1, len - 0.12, 0.8, 0.4);
                    start = -1;
                }
            }
        }
        return d;
    }
    for (let y = 0; y < n; y++) {
        let run = -1;
        for (let x = 0; x <= n; x++) {
            const dark = x < n && on(x, y);
            if (shape === 'square') {
                // one rectangle per horizontal run: fewer nodes and no hairlines between neighbours
                if (dark && run < 0) {
                    run = x;
                } else if (!dark && run >= 0) {
                    d += `M${run} ${y}h${x - run}v1h${run - x}Z`;
                    run = -1;
                }
                continue;
            }
            if (x === n) {
                continue;
            }
            if (!dark) {
                if (shape === 'liquid' && light(x, y)) {
                    // concave joins: a light module whose two neighbours and the diagonal between them are dark
                    const t = on(x, y - 1), b = on(x, y + 1), l = on(x - 1, y), r = on(x + 1, y);
                    if (t && l && on(x - 1, y - 1)) d += `M${x} ${y}h.5A.5 .5 0 0 0 ${x} ${y + 0.5}Z`;
                    if (t && r && on(x + 1, y - 1)) d += `M${x + 1} ${y}v.5A.5 .5 0 0 0 ${x + 0.5} ${y}Z`;
                    if (b && r && on(x + 1, y + 1)) d += `M${x + 1} ${y + 1}h-.5A.5 .5 0 0 0 ${x + 1} ${y + 0.5}Z`;
                    if (b && l && on(x - 1, y + 1)) d += `M${x} ${y + 1}v-.5A.5 .5 0 0 0 ${x + 0.5} ${y + 1}Z`;
                }
                continue;
            }
            const t = on(x, y - 1), b = on(x, y + 1), l = on(x - 1, y), r = on(x + 1, y);
            switch (shape) {
                case 'dots':
                    d += circle(x + 0.5, y + 0.5, 0.45);
                    break;
                case 'rounded':
                    d += rrect(x + 0.05, y + 0.05, 0.9, 0.9, 0.3);
                    break;
                case 'diamond':
                    d += diamond(x + 0.5, y + 0.5, 0.54);
                    break;
                case 'liquid':
                    d += rrect(x, y, 1, 1, [!t && !l ? 0.5 : 0, !t && !r ? 0.5 : 0, !b && !r ? 0.5 : 0, !b && !l ? 0.5 : 0]);
                    break;
                case 'classy':
                    d += rrect(x, y, 1, 1, [!t && !l ? 0.5 : 0, 0, !b && !r ? 0.5 : 0, 0]);
                    break;
                default:
                    d += `M${x} ${y}h1v1h-1Z`;
            }
        }
    }
    return d;
}

/** Corner radii of an eye drawn at the top-left; the other two eyes are mirrored. */
const FRAME_RADII = {
    square: [[0, 0, 0, 0], [0, 0, 0, 0]],
    rounded: [[2.2, 2.2, 2.2, 2.2], [1.3, 1.3, 1.3, 1.3]],
    circle: [[3.5, 3.5, 3.5, 3.5], [2.5, 2.5, 2.5, 2.5]],
    leaf: [[3, 3, 0, 3], [2, 2, 0, 2]],
    double: [[3, 0, 3, 0], [2, 0, 2, 0]],
};
const BALL_RADII = { square: [0, 0, 0, 0], rounded: [0.9, 0.9, 0.9, 0.9], circle: [1.5, 1.5, 1.5, 1.5], leaf: [1.4, 1.4, 0, 1.4], double: [1.4, 0, 1.4, 0] };

function mirror(radii, corner) {
    const [tl, tr, br, bl] = radii;
    if (corner === 'tr') return [tr, tl, bl, br];
    if (corner === 'bl') return [bl, br, tr, tl];
    return radii;
}

function eyePaths(n, frameShape, ballShape) {
    let frames = '';
    let balls = '';
    for (const [ex, ey, corner] of [[0, 0, 'tl'], [n - 7, 0, 'tr'], [0, n - 7, 'bl']]) {
        const [outer, inner] = FRAME_RADII[frameShape] ?? FRAME_RADII.square;
        frames += rrect(ex, ey, 7, 7, mirror(outer, corner)) + rrect(ex + 1, ey + 1, 5, 5, mirror(inner, corner));
        balls += ballShape === 'diamond' ? diamond(ex + 3.5, ey + 3.5, 1.95) : rrect(ex + 2, ey + 2, 3, 3, mirror(BALL_RADII[ballShape] ?? BALL_RADII.square, corner));
    }
    return { frames, balls };
}

/* ---------------------------------------------------------------- colours ---------------------------------------------------------------- */

function luminance(hex) {
    const v = hex.replace('#', '');
    const c = [0, 2, 4].map((i) => parseInt(v.slice(i, i + 2), 16) / 255).map((u) => (u <= 0.03928 ? u / 12.92 : ((u + 0.055) / 1.055) ** 2.4));
    return 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2];
}

const contrast = (a, b) => {
    const [x, y] = [luminance(a), luminance(b)].sort((p, q) => q - p);
    return (x + 0.05) / (y + 0.05);
};

const esc = (s) => String(s).replace(/[&<>"']/g, (ch) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[ch]);
const svgDataUrl = (svg) => 'data:image/svg+xml;base64,' + btoa(unescape(encodeURIComponent(svg)));

/* ---------------------------------------------------------------- built-in logos (drawn for EgMenu) ---------------------------------------------------------------- */

const ICONS = {
    whatsapp: '<circle cx="32" cy="32" r="32" fill="#25D366"/><path d="M32 13.5a18.5 18.5 0 0 0-16 27.8L13.5 50.5l9.5-2.5A18.5 18.5 0 1 0 32 13.5z" fill="none" stroke="#fff" stroke-width="3.4" stroke-linejoin="round"/><path d="M25.3 23.3c.7-.7 1.8-.6 2.2.3l1.6 3.6c.3.7.1 1.4-.4 1.9l-1.2 1.1c1.2 2.7 3.4 4.9 6.1 6.1l1.1-1.2c.5-.5 1.2-.7 1.9-.4l3.6 1.6c.9.4 1 1.5.3 2.2l-1.5 1.5c-1.2 1.2-3 1.5-4.5.9-5.1-2.2-9.2-6.3-11.4-11.4-.6-1.5-.3-3.3.9-4.5z" fill="#fff"/>',
    instagram: '<defs><linearGradient id="g" x1="0" y1="1" x2="1" y2="0"><stop offset="0" stop-color="#ffc94a"/><stop offset=".35" stop-color="#f5762b"/><stop offset=".68" stop-color="#d42c7c"/><stop offset="1" stop-color="#5a4fd6"/></linearGradient></defs><rect width="64" height="64" rx="16" fill="url(#g)"/><rect x="15.5" y="15.5" width="33" height="33" rx="10" fill="none" stroke="#fff" stroke-width="4"/><circle cx="32" cy="32" r="7.6" fill="none" stroke="#fff" stroke-width="4"/><circle cx="42.3" cy="21.7" r="2.6" fill="#fff"/>',
    facebook: '<circle cx="32" cy="32" r="32" fill="#1877F2"/><path d="M35.2 52V35.4h5.4l.9-6.6h-6.3v-4.1c0-1.9.6-3.2 3.3-3.2H42v-5.8c-.6-.1-2.6-.3-5-.3-4.9 0-8.3 3-8.3 8.6v4.8h-5.5v6.6h5.5V52z" fill="#fff"/>',
    location: '<circle cx="32" cy="32" r="32" fill="#E5483B"/><path d="M32 12.5c-7.6 0-13.8 6-13.8 13.5C18.2 36.2 32 51.5 32 51.5S45.8 36.2 45.8 26c0-7.5-6.2-13.5-13.8-13.5z" fill="#fff"/><circle cx="32" cy="26" r="5.4" fill="#E5483B"/>',
    wifi: '<circle cx="32" cy="32" r="32" fill="#005BA6"/><path d="M15.5 27.5a23.5 23.5 0 0 1 33 0M21.3 33.4a15.3 15.3 0 0 1 21.4 0M27 39.2a7 7 0 0 1 10 0" fill="none" stroke="#fff" stroke-width="4" stroke-linecap="round"/><circle cx="32" cy="45.5" r="3.2" fill="#fff"/>',
    menu: '<circle cx="32" cy="32" r="32" fill="#F26522"/><g fill="none" stroke="#fff" stroke-width="3.4" stroke-linecap="round" stroke-linejoin="round"><path d="M21 14v9a5 5 0 0 0 10 0v-9M26 14v9M26 28v22"/><path d="M42.5 50V14c-4.2 2.6-6.5 8-6.5 14.5V34h6.5"/></g>',
};
const iconSvg = (name) => `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64">${ICONS[name]}</svg>`;

/* ---------------------------------------------------------------- the whole picture ---------------------------------------------------------------- */

/**
 * The styled QR code as an SVG string. `matrix` holds rows of 0/1 without the quiet zone; `o` is the design
 * (see readDesign()). `pixels` sets the width/height attributes (the longer side).
 */
function buildSvg(matrix, o, pixels = null, idPrefix = 'q') {
    const n = matrix.length;
    const m = o.margin;
    const q = n + 2 * m;
    const inFinder = (x, y) => (x < 7 && y < 7) || (x >= n - 7 && y < 7) || (x < 7 && y >= n - 7);

    // logo box (module units, centred), the plate behind it and the modules it clears
    let logo = null;
    if (o.logo) {
        const s = (o.logoSize / 100) * n;
        const iw = o.logo.ratio >= 1 ? s : s * o.logo.ratio;
        const ih = o.logo.ratio >= 1 ? s / o.logo.ratio : s;
        const pad = o.logoPlate ? Math.max(0.6, s * 0.08) : 0.25;
        logo = { x: (n - iw) / 2, y: (n - ih) / 2, w: iw, h: ih, pad };
    }
    const cleared = (x, y) => {
        if (!logo || !(o.logoClear || o.logoPlate)) return false;
        return x + 1 > logo.x - logo.pad && x < logo.x + logo.w + logo.pad && y + 1 > logo.y - logo.pad && y < logo.y + logo.h + logo.pad;
    };
    const on = (x, y) => x >= 0 && y >= 0 && x < n && y < n && matrix[y][x] === 1 && !inFinder(x, y) && !cleared(x, y);
    const light = (x, y) => matrix[y][x] !== 1 && !inFinder(x, y) && !cleared(x, y);

    let clearedCount = 0;
    if (logo) {
        for (let y = 0; y < n; y++) for (let x = 0; x < n; x++) if (cleared(x, y)) clearedCount++;
    }

    // paint: one colour, or a gradient across the code
    const defs = [];
    let fill = o.color1;
    if (o.fillType === 'linear' || o.fillType === 'radial') {
        const id = `${idPrefix}-grad`;
        const stops = `<stop offset="0" stop-color="${o.color1}"/><stop offset="1" stop-color="${o.color2}"/>`;
        if (o.fillType === 'linear') {
            const a = (o.angle * Math.PI) / 180;
            const dx = (Math.cos(a) * n) / 2, dy = (Math.sin(a) * n) / 2;
            defs.push(`<linearGradient id="${id}" gradientUnits="userSpaceOnUse" x1="${r3(n / 2 - dx)}" y1="${r3(n / 2 - dy)}" x2="${r3(n / 2 + dx)}" y2="${r3(n / 2 + dy)}">${stops}</linearGradient>`);
        } else {
            defs.push(`<radialGradient id="${id}" gradientUnits="userSpaceOnUse" cx="${n / 2}" cy="${n / 2}" r="${r3(n * 0.72)}">${stops}</radialGradient>`);
        }
        fill = `url(#${id})`;
    }
    const frameFill = o.eyeCustom ? o.eyeFrameColor : fill;
    const ballFill = o.eyeCustom ? o.eyeBallColor : fill;
    const bg = o.transparent ? null : o.bg;
    const plateColor = o.transparent ? '#ffffff' : o.bg;

    // layout: the code with its quiet zone, inside an optional frame with a label
    let w = q, h = q, qx = 0, qy = 0;
    const parts = [];
    const text = o.frameText.trim();
    const hasFrame = o.frame !== 'none';
    let label = null;
    if (o.frame === 'bottom' || o.frame === 'top') {
        const b = Math.max(1, q * 0.04);
        const band = q * 0.2;
        w = q + 2 * b;
        h = q + 2 * b + band;
        qx = b;
        qy = o.frame === 'top' ? b + band : b;
        parts.push(`<path fill-rule="evenodd" fill="${o.frameColor}" d="${rrect(0, 0, w, h, q * 0.07)}${rrect(qx, qy, q, q, q * 0.035)}"/>`);
        if (bg) parts.push(`<path fill="${bg}" d="${rrect(qx, qy, q, q, q * 0.035)}"/>`);
        label = { cx: w / 2, cy: o.frame === 'top' ? (band + b) / 2 : q + b + (band + b) / 2, size: band * 0.46, max: w * 0.86 };
    } else if (o.frame === 'pill') {
        const ph = q * 0.15;
        const gap = ph * 0.32;
        h = q + gap + ph + q * 0.03;
        if (bg) parts.push(`<path fill="${bg}" d="${rrect(0, 0, w, h, q * 0.04)}"/>`);
        const pw = q * 0.8;
        const px = (w - pw) / 2, py = q + gap;
        const tip = ph * 0.26;
        parts.push(`<path fill="${o.frameColor}" d="${rrect(px, py, pw, ph, ph / 2)}M${r3(w / 2 - tip)} ${r3(py + 0.01)}L${r3(w / 2)} ${r3(py - tip)}L${r3(w / 2 + tip)} ${r3(py + 0.01)}Z"/>`);
        label = { cx: w / 2, cy: py + ph / 2, size: ph * 0.5, max: pw * 0.86 };
    } else if (bg) {
        parts.push(`<rect width="${q}" height="${q}" fill="${bg}"/>`);
    }

    const { frames, balls } = eyePaths(n, o.eyeFrame, o.eyeBall);
    let code = `<path fill="${fill}" d="${bodyPath(n, o.body, on, light)}"/>`;
    code += `<path fill="${frameFill}" fill-rule="evenodd" d="${frames}"/><path fill="${ballFill}" d="${balls}"/>`;
    if (logo) {
        if (o.logoPlate) {
            code += `<path fill="${plateColor}" d="${rrect(logo.x - logo.pad, logo.y - logo.pad, logo.w + 2 * logo.pad, logo.h + 2 * logo.pad, Math.min(logo.w, logo.h) * 0.18)}"/>`;
        }
        code += `<image href="${o.logo.src}" x="${r3(logo.x)}" y="${r3(logo.y)}" width="${r3(logo.w)}" height="${r3(logo.h)}" preserveAspectRatio="xMidYMid meet"/>`;
    }
    parts.push(`<g transform="translate(${r3(qx + m)} ${r3(qy + m)})">${code}</g>`);

    if (label && text !== '') {
        const size = Math.min(label.size, label.max / Math.max(3, [...text].length * 0.58));
        const rtl = /[֐-ࣿ]/.test(text) ? ' direction="rtl"' : '';
        parts.push(`<text x="${r3(label.cx)}" y="${r3(label.cy)}" font-size="${r3(size)}" font-family="Cairo, 'Segoe UI', Tahoma, Arial, sans-serif" font-weight="800" fill="${o.frameTextColor}" text-anchor="middle" dominant-baseline="central"${rtl}>${esc(text)}</text>`);
    }

    const scale = pixels ? pixels / Math.max(w, h) : null;
    const size = scale ? ` width="${Math.round(w * scale)}" height="${Math.round(h * scale)}"` : '';
    const svg = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${r3(w)} ${r3(h)}"${size}>${defs.length ? `<defs>${defs.join('')}</defs>` : ''}${parts.join('')}</svg>`;

    return { svg, width: w, height: h, hasFrame, logoShare: logo ? (logo.w * logo.h) / (n * n) : 0, clearedShare: clearedCount / (n * n) };
}

/* ---------------------------------------------------------------- payloads ---------------------------------------------------------------- */

const digits = (s) => String(s ?? '').replace(/[^\d]/g, '');

/** An Egyptian mobile written locally (01xxxxxxxxx) becomes international (201xxxxxxxxx); other numbers keep their code. */
function intlNumber(s) {
    let d = digits(String(s ?? '').replace(/[٠-٩]/g, (c) => '٠١٢٣٤٥٦٧٨٩'.indexOf(c)));
    if (d.startsWith('00')) d = d.slice(2);
    if (/^01\d{9}$/.test(d)) d = '2' + d;
    return d;
}

const wifiEscape = (s) => String(s).replace(/([\\;,:"])/g, '\\$1');
const vcardEscape = (s) => String(s).trim().replace(/([\\;,])/g, '\\$1').replace(/\n/g, '\\n');

function buildPayload(type, v) {
    const t = (k) => String(v[k] ?? '').trim();
    switch (type) {
        case 'url': {
            const u = t('url_url');
            if (u === '') return '';
            return /^[a-z][a-z0-9+.-]*:/i.test(u) ? u : 'https://' + u;
        }
        case 'text':
            return String(v.text_text ?? '').trim() === '' ? '' : String(v.text_text);
        case 'whatsapp': {
            const num = intlNumber(v.wa_phone);
            if (num === '') return '';
            return `https://wa.me/${num}` + (t('wa_message') ? `?text=${encodeURIComponent(t('wa_message'))}` : '');
        }
        case 'phone': {
            const num = t('tel_phone').replace(/[^\d+]/g, '');
            return num === '' ? '' : `tel:${num}`;
        }
        case 'email': {
            const to = t('email_to');
            if (to === '') return '';
            const q = [];
            if (t('email_subject')) q.push('subject=' + encodeURIComponent(t('email_subject')));
            if (t('email_body')) q.push('body=' + encodeURIComponent(t('email_body')));
            return `mailto:${to}` + (q.length ? '?' + q.join('&') : '');
        }
        case 'wifi': {
            const ssid = String(v.wifi_ssid ?? '');
            if (ssid.trim() === '') return '';
            const enc = v.wifi_enc === 'nopass' ? 'nopass' : v.wifi_enc === 'WEP' ? 'WEP' : 'WPA';
            return `WIFI:T:${enc};S:${wifiEscape(ssid)};` + (enc !== 'nopass' ? `P:${wifiEscape(v.wifi_password ?? '')};` : '') + (v.wifi_hidden ? 'H:true;' : '') + ';';
        }
        case 'vcard': {
            if (!t('vc_name') && !t('vc_phone') && !t('vc_email')) return '';
            const lines = ['BEGIN:VCARD', 'VERSION:3.0', `N:${vcardEscape(t('vc_name'))};;;;`, `FN:${vcardEscape(t('vc_name'))}`];
            if (t('vc_company')) lines.push(`ORG:${vcardEscape(t('vc_company'))}`);
            if (t('vc_phone')) lines.push(`TEL;TYPE=CELL:${t('vc_phone').replace(/[^\d+]/g, '')}`);
            if (t('vc_email')) lines.push(`EMAIL:${t('vc_email')}`);
            if (t('vc_website')) lines.push(`URL:${t('vc_website')}`);
            lines.push('END:VCARD');
            return lines.join('\n');
        }
        case 'location': {
            const lat = parseFloat(t('geo_lat')), lng = parseFloat(t('geo_lng'));
            if (!Number.isFinite(lat) || !Number.isFinite(lng) || Math.abs(lat) > 90 || Math.abs(lng) > 180) return '';
            return v.geo_format === 'geo' ? `geo:${lat},${lng}` : `https://maps.google.com/?q=${lat},${lng}`;
        }
        case 'sms': {
            const num = t('sms_phone').replace(/[^\d+]/g, '');
            if (num === '') return '';
            return `SMSTO:${num}:${t('sms_message')}`;
        }
    }
    return '';
}

/* ---------------------------------------------------------------- the page ---------------------------------------------------------------- */

function init(root) {
    const form = root.querySelector('[data-qr-form]');
    const preview = root.querySelector('[data-qr-preview]');
    const mini = root.querySelector('[data-qr-mini]');
    const warnings = root.querySelector('[data-qr-warnings]');
    const status = root.querySelector('[data-qr-status]');
    const sizeOut = root.querySelector('[data-qr-size-out]');
    const downloads = root.querySelectorAll('[data-qr-download]');
    const eccSelect = form.elements.ecc;
    const eccNote = root.querySelector('[data-qr-ecc-note]');
    const t = JSON.parse(root.dataset.qrI18n || '{}');
    const endpoint = root.dataset.endpoint;
    const maxLength = Number(root.dataset.max) || 2000;
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';

    const cache = new Map();
    let current = null; // {matrix, payload, ecc}
    let uploaded = null; // {src, ratio}: the owner's logo, downscaled, kept in memory only
    let timer = null;
    let seq = 0;

    /* ------- content type tabs ------- */
    const tabs = [...root.querySelectorAll('[data-qr-tab]')];
    const panels = [...root.querySelectorAll('[data-qr-panel]')];
    const setType = (type, focus = false) => {
        form.elements.type.value = type;
        tabs.forEach((b) => {
            const active = b.dataset.qrTab === type;
            b.setAttribute('aria-selected', active ? 'true' : 'false');
            b.tabIndex = active ? 0 : -1;
            if (active && focus) b.focus();
        });
        panels.forEach((p) => (p.hidden = p.dataset.qrPanel !== type));
        update();
    };
    tabs.forEach((b, i) => {
        b.addEventListener('click', () => setType(b.dataset.qrTab));
        b.addEventListener('keydown', (e) => {
            const rtl = document.dir === 'rtl';
            const step = { ArrowRight: rtl ? -1 : 1, ArrowLeft: rtl ? 1 : -1, Home: -i, End: tabs.length - 1 - i }[e.key];
            if (step === undefined) return;
            e.preventDefault();
            const next = tabs[(i + step + tabs.length) % tabs.length];
            setType(next.dataset.qrTab, true);
        });
    });

    /* ------- read the form ------- */
    const values = () => {
        const v = {};
        for (const el of form.elements) {
            if (!el.name || el.type === 'file') continue;
            if (el.type === 'checkbox') v[el.name] = el.checked;
            else if (el.type === 'radio') {
                if (el.checked) v[el.name] = el.value;
            } else v[el.name] = el.value;
        }
        return v;
    };

    const hex = (s, fallback) => (/^#[0-9a-f]{6}$/i.test(s ?? '') ? s : fallback);

    function readDesign(v) {
        let logo = null;
        if (v.logo === 'upload' && uploaded) logo = uploaded;
        else if (ICONS[v.logo]) logo = { src: svgDataUrl(iconSvg(v.logo)), ratio: 1 };
        return {
            body: v.body || 'square',
            eyeFrame: v.eye_frame || 'square',
            eyeBall: v.eye_ball || 'square',
            fillType: v.fill_type || 'solid',
            color1: hex(v.color1, '#000000'),
            color2: hex(v.color2, '#000000'),
            angle: Number(v.angle) || 0,
            eyeCustom: !!v.eye_custom,
            eyeFrameColor: hex(v.eye_frame_color, '#000000'),
            eyeBallColor: hex(v.eye_ball_color, '#000000'),
            bg: hex(v.bg_color, '#ffffff'),
            transparent: !!v.bg_transparent,
            margin: Math.min(8, Math.max(2, Number(v.margin) || 4)),
            logo,
            logoSize: Math.min(40, Math.max(10, Number(v.logo_size) || 22)),
            logoClear: !!v.logo_clear,
            logoPlate: !!v.logo_plate,
            frame: v.frame || 'none',
            frameText: String(v.frame_text ?? '').slice(0, 40),
            frameColor: hex(v.frame_color, '#0a2540'),
            frameTextColor: hex(v.frame_text_color, '#ffffff'),
        };
    }

    /* ------- warnings: keep it scannable ------- */
    function warn(o, result) {
        const list = [];
        const bg = o.transparent ? '#ffffff' : o.bg;
        const inks = [o.color1];
        if (o.fillType !== 'solid') inks.push(o.color2);
        if (o.eyeCustom) inks.push(o.eyeFrameColor, o.eyeBallColor);
        if (inks.some((c) => luminance(c) > luminance(bg))) list.push(t.inverted);
        else if (inks.some((c) => contrast(c, bg) < 3)) list.push(t.lowContrast);
        if (o.logo && (o.logoSize > 30 || result.clearedShare > 0.2)) list.push(t.bigLogo);
        if (o.transparent) list.push(t.transparent);
        warnings.innerHTML = list.map((w) => `<li>${esc(w)}</li>`).join('');
        warnings.hidden = list.length === 0;
    }

    /* ------- draw ------- */
    function draw() {
        const v = values();
        const o = readDesign(v);
        if (!current) return;
        const result = buildSvg(current.matrix, o);
        preview.innerHTML = result.svg;
        preview.querySelector('svg')?.setAttribute('aria-hidden', 'true');
        if (mini) mini.innerHTML = buildSvg(current.matrix, o, null, 'm').svg;
        warn(o, result);
        drawPresetThumbs();
    }

    function setEmpty(message) {
        current = null;
        preview.innerHTML = `<p class="qr-empty">${esc(message)}</p>`;
        if (mini) mini.innerHTML = '';
        warnings.hidden = true;
        downloads.forEach((b) => (b.disabled = true));
    }

    function effectiveEcc(v) {
        const withLogo = v.logo && v.logo !== 'none' && (v.logo !== 'upload' || uploaded);
        eccSelect.disabled = !!withLogo;
        if (eccNote) eccNote.hidden = !withLogo;
        return withLogo ? 'H' : ['L', 'M', 'Q', 'H'].includes(v.ecc) ? v.ecc : 'M';
    }

    /** Called on every change: redraw right away when the matrix is known, else fetch it (debounced). */
    function update() {
        const v = values();
        const payload = buildPayload(v.type, v);
        const ecc = effectiveEcc(v);
        sizeOut && (sizeOut.textContent = `${v.png_size} × ${v.png_size} px`);
        clearTimeout(timer);
        if (payload === '') {
            setEmpty(t.empty);
            return;
        }
        if ([...payload].length > maxLength) {
            setEmpty(t.tooLong);
            return;
        }
        const key = ecc + '|' + payload;
        if (cache.has(key)) {
            current = { matrix: cache.get(key), payload, ecc };
            status.textContent = '';
            downloads.forEach((b) => (b.disabled = false));
            draw();
            return;
        }
        if (current) draw(); // keep the last code on screen with the new design while the next matrix loads
        timer = setTimeout(() => fetchMatrix(key, payload, ecc), 250);
    }

    async function fetchMatrix(key, payload, ecc) {
        const mine = ++seq;
        status.textContent = t.loading;
        try {
            const res = await fetch(endpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify({ data: payload, ecc }),
                credentials: 'same-origin',
            });
            if (mine !== seq) return;
            if (res.status === 429) {
                status.textContent = t.slowDown;
                return;
            }
            const json = await res.json().catch(() => ({}));
            if (!res.ok) {
                setEmpty(json.message || t.failed);
                status.textContent = '';
                return;
            }
            if (cache.size > 60) cache.delete(cache.keys().next().value);
            cache.set(key, json.matrix);
            status.textContent = '';
            update();
        } catch {
            if (mine === seq) status.textContent = t.failed;
        }
    }

    /* ------- logo upload: read, downscale, keep in memory ------- */
    const fileInput = root.querySelector('[data-qr-logo-file]');
    const logoError = root.querySelector('[data-qr-logo-error]');
    fileInput?.addEventListener('change', () => {
        const file = fileInput.files?.[0];
        logoError.hidden = true;
        if (!file) return;
        if (!/^image\/(png|jpe?g|webp|gif|svg\+xml)$/.test(file.type) || file.size > 5 * 1024 * 1024) {
            logoError.hidden = false;
            fileInput.value = '';
            return;
        }
        const reader = new FileReader();
        reader.onload = () => {
            const img = new Image();
            img.onload = () => {
                const ratio = img.naturalWidth && img.naturalHeight ? img.naturalWidth / img.naturalHeight : 1;
                let src = reader.result;
                if (file.type !== 'image/svg+xml' || String(src).length > 300000) {
                    const k = Math.min(1, 600 / Math.max(img.naturalWidth || 600, img.naturalHeight || 600));
                    const c = document.createElement('canvas');
                    c.width = Math.max(1, Math.round((img.naturalWidth || 600) * k));
                    c.height = Math.max(1, Math.round((img.naturalHeight || 600) * k));
                    c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
                    src = c.toDataURL('image/png');
                }
                uploaded = { src, ratio };
                const radio = form.querySelector('input[name="logo"][value="upload"]');
                if (radio) {
                    radio.disabled = false;
                    radio.checked = true;
                }
                update();
            };
            img.onerror = () => (logoError.hidden = false);
            img.src = reader.result;
        };
        reader.readAsDataURL(file);
    });

    /* ------- dependent controls ------- */
    const syncControls = () => {
        const v = values();
        root.querySelectorAll('[data-qr-when]').forEach((el) => {
            const [name, expected] = el.dataset.qrWhen.split('=');
            const value = v[name];
            el.hidden = expected === undefined ? !value : !expected.split('|').includes(String(value));
        });
    };

    form.addEventListener('input', () => {
        syncControls();
        update();
    });
    form.addEventListener('change', () => {
        syncControls();
        update();
    });
    form.addEventListener('submit', (e) => e.preventDefault());

    /* ------- presets ------- */
    const presets = JSON.parse(root.dataset.presets || '{}');
    const presetButtons = [...root.querySelectorAll('[data-qr-preset]')];
    function applyPreset(name) {
        const p = presets[name];
        if (!p) return;
        for (const [k, val] of Object.entries(p)) {
            const els = form.querySelectorAll(`[name="${k}"]`);
            els.forEach((el) => {
                if (el.type === 'radio') el.checked = el.value === String(val);
                else if (el.type === 'checkbox') el.checked = !!val;
                else el.value = val;
            });
        }
        presetButtons.forEach((b) => b.setAttribute('aria-pressed', b.dataset.qrPreset === name ? 'true' : 'false'));
        syncControls();
        update();
    }
    presetButtons.forEach((b) => b.addEventListener('click', () => applyPreset(b.dataset.qrPreset)));

    function drawPresetThumbs() {
        if (!current) return;
        const v = values();
        presetButtons.forEach((b) => {
            const holder = b.querySelector('[data-qr-preset-thumb]');
            const merged = { ...v, ...presets[b.dataset.qrPreset] };
            const o = readDesign(merged);
            const key = JSON.stringify(o) + current.matrix.length + current.payload;
            if (holder.dataset.key === key) return;
            holder.dataset.key = key;
            holder.innerHTML = buildSvg(current.matrix, o, null, 'p' + b.dataset.qrPreset).svg;
        });
    }

    /* ------- shape thumbnails ------- */
    const sample = [
        [1, 1, 0, 1, 0, 1],
        [1, 0, 0, 1, 1, 1],
        [0, 1, 1, 1, 0, 0],
        [1, 1, 0, 0, 1, 1],
        [0, 1, 0, 1, 1, 0],
        [1, 1, 1, 0, 1, 1],
    ];
    root.querySelectorAll('[data-qr-thumb]').forEach((el) => {
        const [kind, shape] = el.dataset.qrThumb.split(':');
        let inner = '';
        let box = '0 0 7 7';
        if (kind === 'body') {
            const on = (x, y) => x >= 0 && y >= 0 && x < 6 && y < 6 && sample[y][x] === 1;
            inner = `<path d="${bodyPath(6, shape, on, (x, y) => sample[y][x] === 0)}"/>`;
            box = '-.5 -.5 7 7';
        } else {
            const { frames, balls } = eyePaths(7, kind === 'frame' ? shape : 'square', kind === 'ball' ? shape : 'square');
            // the top-left eye only: its frame (two rings) and its ball; the part not being picked is faded
            const frame = frames.split('Z').slice(0, 2).join('Z') + 'Z';
            const ball = balls.split('Z')[0] + 'Z';
            inner = `<path fill-rule="evenodd" opacity="${kind === 'frame' ? 1 : 0.25}" d="${frame}"/><path opacity="${kind === 'ball' ? 1 : 0.25}" d="${ball}"/>`;
            box = '-.5 -.5 8 8';
        }
        el.innerHTML = `<svg viewBox="${box}" fill="currentColor" aria-hidden="true">${inner}</svg>`;
    });
    root.querySelectorAll('[data-qr-icon]').forEach((el) => {
        // an <img> keeps each icon's gradient ids to itself
        el.innerHTML = `<img src="${svgDataUrl(iconSvg(el.dataset.qrIcon))}" alt="" width="28" height="28">`;
    });

    /* ------- downloads ------- */
    function exportSvg(pixels) {
        const o = readDesign(values());
        return buildSvg(current.matrix, o, pixels, 'x').svg;
    }

    function toPng(pixels) {
        return new Promise((resolve, reject) => {
            const svg = exportSvg(pixels);
            const img = new Image();
            img.onload = () => {
                const c = document.createElement('canvas');
                c.width = img.naturalWidth || pixels;
                c.height = img.naturalHeight || pixels;
                c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
                resolve(c.toDataURL('image/png'));
            };
            img.onerror = reject;
            img.src = svgDataUrl(svg);
        });
    }

    const save = (href, name) => {
        const a = document.createElement('a');
        a.href = href;
        a.download = name;
        document.body.appendChild(a);
        a.click();
        a.remove();
    };
    const fileBase = () => 'egmenu-qr-' + (values().type || 'code');

    function printPage() {
        const sheet = document.createElement('div');
        sheet.id = 'qr-print-sheet';
        const img = new Image();
        img.alt = '';
        img.src = svgDataUrl(exportSvg(1200));
        const credit = document.createElement('p');
        credit.textContent = root.dataset.credit || '';
        sheet.append(img, credit);
        document.body.appendChild(sheet);
        document.documentElement.classList.add('qr-printing');
        const done = () => {
            sheet.remove();
            document.documentElement.classList.remove('qr-printing');
            window.removeEventListener('afterprint', done);
        };
        window.addEventListener('afterprint', done);
        const go = () => setTimeout(() => window.print(), 50);
        img.complete ? go() : img.addEventListener('load', go, { once: true });
    }

    downloads.forEach((b) =>
        b.addEventListener('click', async () => {
            if (!current) return;
            const kind = b.dataset.qrDownload;
            if (kind === 'svg') {
                const url = URL.createObjectURL(new Blob([exportSvg(1024)], { type: 'image/svg+xml' }));
                save(url, fileBase() + '.svg');
                setTimeout(() => URL.revokeObjectURL(url), 2000);
            } else if (kind === 'png') {
                const px = Math.min(2048, Math.max(512, Number(values().png_size) || 1024));
                save(await toPng(px), fileBase() + '.png');
            } else if (kind === 'pdf') {
                printPage();
            }
        }),
    );

    /* ------- mobile: a small floating copy of the code while the big preview is off screen ------- */
    const previewCard = root.querySelector('[data-qr-preview-card]');
    if (mini && previewCard && 'IntersectionObserver' in window) {
        new IntersectionObserver(([entry]) => mini.parentElement.classList.toggle('is-on', !entry.isIntersecting && entry.boundingClientRect.top < 0), { threshold: 0 }).observe(previewCard);
        mini.parentElement.addEventListener('click', () => previewCard.scrollIntoView({ behavior: 'smooth', block: 'center' }));
    }

    // a hook for checks and screenshots (no network, no state)
    window.egmenuQr = { applyPreset, png: (px = 1024) => toPng(px), svg: () => (current ? exportSvg(1024) : null), ready: () => !!current, state: () => current, setType };

    syncControls();
    update();
}

// last, once every helper above is defined
const toolRoot = document.querySelector('[data-qr-tool]');
if (toolRoot) {
    init(toolRoot);
}
