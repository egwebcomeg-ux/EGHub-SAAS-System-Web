// Inside the EgHub restaurant and waiter apps: hands this phone's push token (FCM) to the server for the signed-in
// staff member on every page load and whenever the app refreshes it, and takes it back on sign-out. Loaded on every
// dashboard page (layouts.base, when signed in on app.<domain>); a no-op in a browser and in the other apps.
import { call, inApp, nativeInfo, onNative, store } from './native';

const meta = document.querySelector('meta[name="eg-push-register"]');
if (meta && inApp('restaurant', 'waiter')) {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const KEY = 'eg-push-sent';
    const post = (url, body, keepalive = false) => fetch(url, {
        method: 'POST', keepalive, credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
        body: JSON.stringify(body),
    });
    const send = (token) => {
        if (!token || typeof token !== 'string') return;
        const last = store.get(KEY);
        // the same token for the same account is refreshed at most every few hours
        if (last && last.token === token && last.user === meta.dataset.user && Date.now() - last.at < 6 * 3600e3) return;
        const i = nativeInfo() || {};
        post(meta.content, {
            token, app: i.app, platform: 'android', version: String(i.version || ''),
            device: [i.manufacturer, i.model].filter(Boolean).join(' ').slice(0, 120) || null,
        }).then((r) => { if (r.ok) store.set(KEY, { token, user: meta.dataset.user, at: Date.now() }); }).catch(() => {});
    };
    send(call('getPushToken'));
    onNative('eg-native-push-token', (d) => send(d.token));

    // signing out: this phone stops ringing for this account
    document.querySelectorAll('form[action$="/logout"]').forEach((form) => form.addEventListener('submit', () => {
        const last = store.get(KEY);
        if (last?.token && meta.dataset.unregister) {
            post(meta.dataset.unregister, { token: last.token }, true).catch(() => {});
            store.set(KEY, null);
        }
    }));
}
