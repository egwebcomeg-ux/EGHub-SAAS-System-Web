// Delivery pricing sums in the browser, the same as App\Services\Delivery\DeliveryPricing on the server. They only
// drive the live preview (checkout map, dashboard test pin): the server prices every order again from the pin.
// Points are [lat, lng].

// ray casting, the same test as App\Models\DeliveryZone::contains
export function inside(poly, lat, lng) {
    let hit = false;
    for (let i = 0, j = poly.length - 1; i < poly.length; j = i++) {
        const [ai, oi] = poly[i];
        const [aj, oj] = poly[j];
        if ((oi > lng) !== (oj > lng) && lat < ((aj - ai) * (lng - oi)) / (oj - oi) + ai) hit = !hit;
    }
    return hit;
}

// shoelace area in squared degrees, only to rank overlapping zones (smallest first)
function area(poly) {
    let sum = 0;
    for (let i = 0, j = poly.length - 1; i < poly.length; j = i++) sum += (poly[j][1] + poly[i][1]) * (poly[j][0] - poly[i][0]);
    return Math.abs(sum / 2);
}

// straight-line (great-circle) distance in km
export function km(lat1, lng1, lat2, lng2) {
    const rad = (d) => (d * Math.PI) / 180;
    const a = Math.sin(rad(lat2 - lat1) / 2) ** 2 + Math.cos(rad(lat1)) * Math.cos(rad(lat2)) * Math.sin(rad(lng2 - lng1) / 2) ** 2;
    return 2 * 6371.0088 * Math.asin(Math.min(1, Math.sqrt(a)));
}

// the drawn zone holding the point: a sub-zone beats its main zone, then the smallest area
export function zoneAt(zones, lat, lng) {
    const hits = (zones || []).filter((z) => z.polygon && z.polygon.length >= 3 && inside(z.polygon, lat, lng));
    hits.sort((a, b) => (a.parent ? 0 : 1) - (b.parent ? 0 : 1) || area(a.polygon) - area(b.polygon));
    return hits[0] || null;
}

// the delivery to a point: {id (zone or null), name, fee, min, eta, km} or null when the branch does not deliver there.
// pricing = {method, center, rings: [{km, fee, min, eta}]}; zones = [{id, name, fee, min, eta, parent, polygon}]
export function quoteAt(pricing, zones, lat, lng, distanceName) {
    if (pricing?.method === 'distance') {
        if (!pricing.center || !(pricing.rings || []).length) return null;
        const d = km(pricing.center[0], pricing.center[1], lat, lng);
        const ring = [...pricing.rings].sort((a, b) => a.km - b.km).find((r) => d <= r.km);
        return ring ? { id: null, name: distanceName(d), fee: ring.fee, min: ring.min, eta: ring.eta ?? null, km: d } : null;
    }
    const zone = zoneAt(zones, lat, lng);
    return zone ? { id: zone.id, name: zone.name, fee: zone.fee, min: zone.min, eta: zone.eta ?? null, km: null } : null;
}
