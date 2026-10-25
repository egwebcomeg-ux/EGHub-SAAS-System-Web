// The EgHub Android apps (pos, restaurant, driver, kiosk, waiter) are WebViews around app.eghub.app that expose
// window.EgHubNative: every method returns a string (JSON where it says so) and results that take time come back as
// window events (eg-native-print, eg-native-push-token). Without the app every helper here returns null / false, so
// the page keeps working exactly as in a browser.

export function bridge() {
    return typeof window !== 'undefined' && window.EgHubNative ? window.EgHubNative : null;
}

/** Calls a method of the bridge; null when there is no app, no such method, or it threw. */
export function call(method, ...args) {
    const b = bridge();
    if (!b || typeof b[method] !== 'function') return null;
    try {
        return b[method](...args);
    } catch (e) {
        console.warn('EgHubNative.' + method, e);
        return null;
    }
}

/** The same, with the returned string read as JSON (the raw value when it is not JSON). */
export function callJson(method, ...args) {
    const raw = call(method, ...args);
    if (raw === null || raw === undefined || raw === '') return null;
    if (typeof raw !== 'string') return raw;
    try {
        return JSON.parse(raw);
    } catch {
        return raw;
    }
}

let info;
/** {app, version, versionCode, platform, model, manufacturer, sdk} or null outside the apps. */
export function nativeInfo() {
    if (info === undefined) {
        const i = callJson('getInfo');
        info = i && typeof i === 'object' ? i : null;
    }
    return info;
}

/** True inside one of the given apps (any EgHub app when none is given). */
export function inApp(...apps) {
    const i = nativeInfo();
    return !!i && (apps.length === 0 || apps.includes(i.app));
}

/** Listens to an event the app dispatches (on window or document); the handler gets its detail as an object. */
export function onNative(name, handler) {
    const seen = new WeakSet();
    const listener = (e) => {
        if (seen.has(e)) return;
        seen.add(e);
        let d = e.detail;
        if (typeof d === 'string') {
            try { d = JSON.parse(d); } catch { /* keep the string */ }
        }
        handler(d || {});
    };
    window.addEventListener(name, listener);
    document.addEventListener(name, listener);
}

/** localStorage that never throws (private mode, blocked storage). */
export const store = {
    get(key, fallback = null) {
        try {
            const v = localStorage.getItem(key);
            return v === null ? fallback : JSON.parse(v);
        } catch {
            return fallback;
        }
    },
    set(key, value) {
        try { localStorage.setItem(key, JSON.stringify(value)); } catch { /* storage full or blocked */ }
    },
};
