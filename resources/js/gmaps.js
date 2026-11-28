// Google Maps behind the few Leaflet calls our delivery maps make (the guest's "pin my location" in menu.js, the call
// center's customer map in pos.js). Where the platform runs on Google (App\Services\Geocoding\MapProvider) the page
// gets a key instead of the Leaflet assets: loadGoogleMaps() loads the Maps JavaScript API and installs this object
// as window.L, so the map code stays one. Points are [lat, lng] or {lat, lng}, as with Leaflet.

let loading = null;

const point = (p) => (Array.isArray(p)
    ? { lat: Number(p[0]), lng: Number(p[1]) }
    : { lat: typeof p.lat === 'function' ? p.lat() : Number(p.lat), lng: typeof p.lng === 'function' ? p.lng() : Number(p.lng) });

function build(g) {
    class Layer {
        addTo(map) { this.g.setMap(map.g); return this; }

        remove() { this.g.setMap(null); return this; }

        bindTooltip(text) { if (this.g.setTitle) this.g.setTitle(String(text ?? '')); return this; }
    }

    class Marker extends Layer {
        constructor(p, o = {}) {
            super();
            this.g = new g.Marker({ position: point(p), draggable: !!o.draggable, title: o.title || '', icon: o.icon });
        }

        setLatLng(p) { this.g.setPosition(point(p)); return this; }

        getLatLng() { return point(this.g.getPosition()); }

        on(type, fn) { this.g.addListener(type, () => fn({ target: this })); return this; }
    }

    class Shape extends Layer {
        constructor(shape) { super(); this.g = shape; }
    }

    const stroke = (o) => ({ strokeColor: o.color || '#0A2540', strokeWeight: o.weight ?? 2, strokeOpacity: 1, fillColor: o.fillColor || o.color || '#0A2540', fillOpacity: o.fillOpacity ?? 0.1, clickable: false });

    const boundsOf = (points) => {
        const b = new g.LatLngBounds();
        points.forEach((p) => b.extend(point(p)));
        return b;
    };

    class GMap {
        constructor(el) {
            this.g = new g.Map(el, {
                center: { lat: 30.0444, lng: 31.2357 }, zoom: 12, zoomControl: true, mapTypeControl: false, streetViewControl: false,
                fullscreenControl: false, clickableIcons: false, gestureHandling: 'greedy',
            });
        }

        setView(p, zoom) {
            this.g.setCenter(point(p));
            if (zoom != null) this.g.setZoom(zoom);
            return this;
        }

        fitBounds(b, o = {}) {
            this.g.fitBounds(b instanceof g.LatLngBounds ? b : boundsOf(b), Array.isArray(o.padding) ? o.padding[0] : 0);
            return this;
        }

        on(type, fn) {
            this.g.addListener(type, (e) => fn({ latlng: e?.latLng ? point(e.latLng) : null }));
            return this;
        }

        // the map measures itself; after its box was hidden it only needs its centre back
        invalidateSize() {
            const c = this.g.getCenter();
            g.event.trigger(this.g, 'resize');
            if (c) this.g.setCenter(c);
            return this;
        }
    }

    return {
        google: true,
        map: (el) => new GMap(el),
        tileLayer: () => ({ addTo() { return this; } }),
        marker: (p, o) => new Marker(p, o),
        circle: (p, o = {}) => new Shape(new g.Circle({ center: point(p), radius: o.radius || 0, ...stroke(o) })),
        polygon: (points, o = {}) => new Shape(new g.Polygon({ paths: points.map(point), ...stroke(o) })),
        // a dot (the restaurant): a marker drawn as a circle, its tooltip is the marker's title
        circleMarker: (p, o = {}) => new Marker(p, {
            icon: { path: g.SymbolPath.CIRCLE, scale: o.radius || 7, fillColor: o.fillColor || '#0A2540', fillOpacity: o.fillOpacity ?? 1, strokeColor: o.color || '#fff', strokeWeight: o.weight ?? 2 },
        }),
        latLng: (p) => ({
            ...point(p),
            // a box of that many metres around the point, as Leaflet's LatLng.toBounds
            toBounds(metres) {
                const { lat, lng } = point(p);
                const dLat = (180 * metres) / 40075017;
                const dLng = dLat / Math.cos((Math.PI / 180) * lat);
                return new g.LatLngBounds({ lat: lat - dLat, lng: lng - dLng }, { lat: lat + dLat, lng: lng + dLng });
            },
        }),
    };
}

/** Loads the Maps JavaScript API once and installs the adapter as window.L. @param {{key: string, language?: string}} config */
export function loadGoogleMaps(config) {
    if (window.L?.google) return Promise.resolve();
    loading ??= new Promise((resolve, reject) => {
        window.__eghubMapsReady = () => {
            window.L = build(window.google.maps);
            resolve();
        };
        const js = document.createElement('script');
        js.src = 'https://maps.googleapis.com/maps/api/js?' + new URLSearchParams({
            key: config.key, language: config.language || 'ar', region: 'EG', loading: 'async', callback: '__eghubMapsReady',
        });
        js.async = true;
        js.onerror = () => { loading = null; reject(new Error('google maps failed')); };
        document.head.appendChild(js);
    });
    return loading;
}
