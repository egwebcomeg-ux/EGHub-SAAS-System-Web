// Delivery zones map: shows every zone's area (sub-zones dashed) and lets the owner draw one by clicking its corners,
// drag a corner to move it, drag (or tap) the small dot between two corners to add one, right-click / long-press a
// corner to remove it.
// Leaflet comes from the CDN (window.L). Saving posts the points as a normal form.

const dataEl = document.getElementById('zones-map-data');
if (dataEl && window.L) {
    const D = JSON.parse(dataEl.textContent);
    const $ = (s) => document.querySelector(s);
    const colors = ['#F26522', '#0A2540', '#0E9F6E', '#7E3AF2', '#C81E1E', '#0694A2', '#B45309'];
    const map = L.map('zones-map').setView(D.center || [30.0444, 31.2357], 12);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19, attribution: '&copy; OpenStreetMap',
    }).addTo(map);

    const layers = {};
    const bounds = [];
    D.zones.forEach((z, i) => {
        if (!z.polygon || z.polygon.length < 3) return;
        layers[z.id] = L.polygon(z.polygon, { color: colors[i % colors.length], weight: z.parent ? 1.5 : 2.5, dashArray: z.parent ? '5 5' : null, fillOpacity: z.parent ? 0.2 : 0.12 })
            .bindTooltip(z.name, { sticky: true }).addTo(map);
        bounds.push(...z.polygon);
    });
    if (bounds.length && !D.center) map.fitBounds(bounds, { padding: [20, 20] });

    // ---- drawing ----
    let points = [];
    let zoneId = null;
    const draft = L.polygon([], { color: '#F26522', weight: 3, dashArray: '6 4', fillOpacity: 0.25 }).addTo(map);
    const dots = L.layerGroup().addTo(map);
    const handle = (size, fill) => L.divIcon({
        className: '', iconSize: [size, size],
        html: `<div style="width:${size}px;height:${size}px;border-radius:50%;background:${fill};border:2px solid #F26522;box-shadow:0 1px 3px rgba(0,0,0,.35)"></div>`,
    });
    const round = (ll) => [+ll.lat.toFixed(6), +ll.lng.toFixed(6)];
    function redraw() {
        draft.setLatLngs(points);
        dots.clearLayers();
        points.forEach((p, i) => {
            const corner = L.marker(p, { draggable: true, icon: handle(16, '#F26522'), zIndexOffset: 500 }).addTo(dots);
            corner.on('drag', () => { points[i] = round(corner.getLatLng()); draft.setLatLngs(points); });
            corner.on('dragend', redraw);
            corner.on('contextmenu', () => { points.splice(i, 1); redraw(); });
        });
        // the dot halfway along each side adds a corner there
        if (points.length >= 3) {
            points.forEach((p, i) => {
                const q = points[(i + 1) % points.length];
                const mid = L.marker([(p[0] + q[0]) / 2, (p[1] + q[1]) / 2], { draggable: true, icon: handle(11, '#fff'), opacity: 0.9 }).addTo(dots);
                const add = () => { points.splice(i + 1, 0, round(mid.getLatLng())); redraw(); };
                mid.on('dragend', add);
                mid.on('click', add);
            });
        }
        $('[data-points]').textContent = points.length;
        $('[data-save-area]').disabled = !zoneId || (points.length > 0 && points.length < 3);
    }
    $('[data-draw-zone]').addEventListener('change', (e) => {
        zoneId = e.target.value || null;
        const zone = D.zones.find((z) => String(z.id) === zoneId);
        Object.values(layers).forEach((l) => l.setStyle({ opacity: zoneId ? 0.35 : 1 }));
        points = zone?.polygon ? zone.polygon.map((p) => [...p]) : [];
        if (zone && layers[zone.id]) map.fitBounds(layers[zone.id].getBounds(), { padding: [30, 30] });
        $('[data-draw-tools]').classList.toggle('hidden', !zoneId);
        redraw();
    });
    map.on('click', (e) => {
        if (!zoneId) return;
        points.push([+e.latlng.lat.toFixed(6), +e.latlng.lng.toFixed(6)]);
        redraw();
    });
    $('[data-undo]').addEventListener('click', () => { points.pop(); redraw(); });
    $('[data-clear]').addEventListener('click', () => { points = []; redraw(); });
    $('[data-save-area]').addEventListener('click', () => {
        const form = $('[data-area-form]');
        form.action = D.saveUrl.replace('__ID__', zoneId);
        form.querySelectorAll('[data-pt]').forEach((i) => i.remove());
        points.forEach((p, i) => p.forEach((v, k) => {
            const input = Object.assign(document.createElement('input'), { type: 'hidden', name: `polygon[${i}][${k}]`, value: v });
            input.dataset.pt = '';
            form.append(input);
        }));
        form.submit();
    });
    redraw();
}
