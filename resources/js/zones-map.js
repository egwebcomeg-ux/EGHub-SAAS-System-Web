// Delivery zones map: shows every zone's area and lets the owner draw one by clicking its corners.
// Leaflet comes from the CDN (window.L). Saving posts the points as a normal form.

const dataEl = document.getElementById('zones-map-data');
if (dataEl && window.L) {
    const D = JSON.parse(dataEl.textContent);
    const $ = (s) => document.querySelector(s);
    const colors = ['#E8642C', '#1E2D4F', '#0E9F6E', '#7E3AF2', '#C81E1E', '#0694A2', '#B45309'];
    const map = L.map('zones-map').setView(D.center || [30.0444, 31.2357], 12);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19, attribution: '&copy; OpenStreetMap',
    }).addTo(map);

    const layers = {};
    const bounds = [];
    D.zones.forEach((z, i) => {
        if (!z.polygon || z.polygon.length < 3) return;
        layers[z.id] = L.polygon(z.polygon, { color: colors[i % colors.length], weight: 2, fillOpacity: 0.15 })
            .bindTooltip(z.name, { sticky: true }).addTo(map);
        bounds.push(...z.polygon);
    });
    if (bounds.length && !D.center) map.fitBounds(bounds, { padding: [20, 20] });

    // ---- drawing ----
    let points = [];
    let zoneId = null;
    const draft = L.polygon([], { color: '#E8642C', weight: 3, dashArray: '6 4', fillOpacity: 0.25 }).addTo(map);
    const dots = L.layerGroup().addTo(map);
    function redraw() {
        draft.setLatLngs(points);
        dots.clearLayers();
        points.forEach((p) => L.circleMarker(p, { radius: 5, color: '#E8642C', fillOpacity: 1 }).addTo(dots));
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
