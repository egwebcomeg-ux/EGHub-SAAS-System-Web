// Delivery pricing of a branch (dashboard): zones or distance rings from the branch pin. The map previews the rings
// (or the drawn zones), the restaurant pin is dragged (or set by a tap, or by pasted coordinates), and "try an
// address" drops a test pin that shows what a guest there pays. Leaflet comes from the CDN (window.L).
import { quoteAt } from './delivery-geo';

const ringColors = ['#0E9F6E', '#F59E0B', '#F26522', '#C81E1E', '#7E3AF2', '#0694A2', '#B45309', '#0A2540'];

document.querySelectorAll('[data-pricing]').forEach((card) => {
    if (!window.L) return;
    const $ = (s) => card.querySelector(s);
    const D = JSON.parse($('[data-pricing-data]').textContent);
    const T = D.t;
    const money = (n) => `${Math.round(n * 100) / 100} ${D.currency}`;
    const method = () => card.querySelector('[data-pricing-method]:checked')?.value || 'zones';

    const start = D.pin || D.fallback || D.zones.find((z) => z.polygon)?.polygon[0] || [30.0444, 31.2357];
    const map = L.map($('[data-pricing-map]')).setView(start, D.pin ? 13 : 12);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; OpenStreetMap' }).addTo(map);

    // ---- the restaurant pin ----
    const pinIcon = L.divIcon({ className: '', html: '<div style="font-size:30px;line-height:30px;transform:translate(-4px,-26px)">🏪</div>', iconSize: [30, 30] });
    let pin = D.pin;
    const marker = L.marker(start, { draggable: true, icon: pinIcon, zIndexOffset: 1000 }).bindTooltip(T.pin);
    function setPin(latlng, pan) {
        pin = [+latlng[0].toFixed(7), +latlng[1].toFixed(7)];
        marker.setLatLng(pin).addTo(map);
        $('[data-pin-lat]').value = pin[0];
        $('[data-pin-lng]').value = pin[1];
        $('[data-pin-coords]').value = `${pin[0]}, ${pin[1]}`;
        if (pan) map.panTo(pin);
        draw();
    }
    marker.on('drag', () => { const p = marker.getLatLng(); pin = [p.lat, p.lng]; draw(); });
    marker.on('dragend', () => { const p = marker.getLatLng(); setPin([p.lat, p.lng]); });
    $('[data-pin-coords]').addEventListener('change', (e) => {
        const m = e.target.value.match(/(-?\d+(?:\.\d+)?)\s*[,،\s]\s*(-?\d+(?:\.\d+)?)/);
        if (m && Math.abs(+m[1]) <= 90 && Math.abs(+m[2]) <= 180) setPin([+m[1], +m[2]], true);
    });

    // ---- the rings editor ----
    const ringsBody = $('[data-rings]');
    let next = ringsBody.querySelectorAll('[data-ring]').length;
    function rings() {
        return [...ringsBody.querySelectorAll('[data-ring]')].map((row) => ({
            km: parseFloat(row.querySelector('[data-ring-km]').value),
            fee: parseFloat(row.querySelector('[data-ring-fee]').value) || 0,
            min: parseFloat(row.querySelector('[data-ring-min]').value) || 0,
            eta: parseInt(row.querySelector('[data-ring-minutes]').value, 10) || null,
        })).filter((r) => r.km > 0).sort((a, b) => a.km - b.km);
    }
    $('[data-ring-add]').addEventListener('click', () => {
        const last = rings().at(-1);
        const html = $('[data-ring-template]').innerHTML.replaceAll('__I__', String(next++)).trim();
        ringsBody.insertAdjacentHTML('beforeend', html);
        const row = ringsBody.lastElementChild;
        row.querySelector('[data-ring-km]').value = last ? last.km + 2 : 2;
        row.querySelector('[data-ring-fee]').focus();
        draw();
    });
    ringsBody.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-ring-remove]');
        if (!btn || ringsBody.querySelectorAll('[data-ring]').length < 2) return;
        btn.closest('[data-ring]').remove();
        draw();
    });
    ringsBody.addEventListener('input', () => draw());

    // ---- the method switch: distance fields only for distance (hidden inputs are not sent, nor checked) ----
    function syncMethod() {
        const distance = method() === 'distance';
        $('[data-distance-fields]').classList.toggle('hidden', !distance);
        $('[data-distance-zones-note]')?.classList.toggle('hidden', !distance);
        $('[data-distance-fields]').querySelectorAll('input').forEach((i) => { i.disabled = !distance; });
        if (distance && pin) marker.addTo(map);
        else marker.remove();
        draw();
    }
    card.querySelectorAll('[data-pricing-method]').forEach((r) => r.addEventListener('change', syncMethod));

    // ---- the preview: rings around the pin, or the branch's drawn zones (sub-zones dashed) ----
    const shapes = L.layerGroup().addTo(map);
    let fitted = false;
    function draw() {
        shapes.clearLayers();
        if (method() === 'distance') {
            if (pin) {
                rings().slice().reverse().forEach((r, i, all) => {
                    const color = ringColors[(all.length - 1 - i) % ringColors.length];
                    L.circle(pin, { radius: r.km * 1000, color, weight: 2, fillColor: color, fillOpacity: 0.08 })
                        .bindTooltip(`${T.upTo.replace('__K__', r.km)} · ${T.fee.replace('__A__', money(r.fee))}`, { sticky: true }).addTo(shapes);
                });
                if (!fitted && rings().length) {
                    map.fitBounds(L.latLng(pin).toBounds(rings().at(-1).km * 2000), { padding: [10, 10] });
                    fitted = true;
                }
            }
        } else {
            const drawn = D.zones.filter((z) => z.polygon);
            drawn.forEach((z, i) => {
                L.polygon(z.polygon, { color: ringColors[i % ringColors.length], weight: z.parent ? 1.5 : 2.5, dashArray: z.parent ? '5 5' : null, fillOpacity: z.parent ? 0.18 : 0.08 })
                    .bindTooltip(`${z.name} · ${T.fee.replace('__A__', money(z.fee))}`, { sticky: true }).addTo(shapes);
            });
            if (!fitted && drawn.length) {
                map.fitBounds(drawn.flatMap((z) => z.polygon), { padding: [20, 20] });
                fitted = true;
            }
        }
        test();
    }

    // ---- "try an address": a test pin and what a guest there pays ----
    let testing = false;
    let testMarker = null;
    const toggle = $('[data-test-toggle]');
    toggle.addEventListener('click', () => {
        testing = !testing;
        toggle.setAttribute('aria-pressed', String(testing));
        toggle.classList.toggle('ring-2', testing);
        toggle.classList.toggle('ring-brand-600', testing);
        if (!testing && testMarker) { testMarker.remove(); testMarker = null; }
        test();
    });
    function test() {
        const out = $('[data-test-result]');
        if (!testMarker) { out.classList.add('hidden'); return; }
        const { lat, lng } = testMarker.getLatLng();
        const pricing = { method: method(), center: pin, rings: rings() };
        let text;
        let ok = false;
        if (pricing.method === 'distance' && !pin) text = T.noPin;
        else if (pricing.method === 'distance' && !pricing.rings.length) text = T.noRings;
        else {
            const q = quoteAt(pricing, D.zones, lat, lng, (d) => T.km.replace('__K__', d.toFixed(1)));
            ok = !!q;
            text = q
                ? [`🧪 ${q.name}`, T.fee.replace('__A__', money(q.fee)), q.min > 0 ? T.min.replace('__A__', money(q.min)) : null, q.eta ? T.eta.replace('__N__', q.eta) : null].filter(Boolean).join(' · ')
                : `🧪 ${pricing.method === 'distance' ? T.outside : T.noZone}`;
        }
        out.textContent = text;
        out.className = `rounded-xl px-3 py-2 text-sm font-semibold ${ok ? 'bg-emerald-50 text-emerald-900 ring-1 ring-emerald-200' : 'bg-amber-50 text-amber-900 ring-1 ring-amber-200'}`;
    }
    map.on('click', (e) => {
        if (testing) {
            testMarker ??= L.marker(e.latlng, { draggable: true }).bindTooltip(T.test).addTo(map);
            testMarker.setLatLng(e.latlng);
            testMarker.off('drag').on('drag', test);
            test();
        } else if (method() === 'distance') {
            setPin([e.latlng.lat, e.latlng.lng]);
        }
    });

    syncMethod();
    setTimeout(() => map.invalidateSize(), 60);
});
