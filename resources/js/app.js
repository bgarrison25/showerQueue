import { profilePhotoPicker } from './profile-photo';
window.profilePhotoPicker = profilePhotoPicker;

// Livewire provides Alpine. The countdown uses server time plus a monotonic clock,
// so an incorrectly set phone clock cannot extend a reservation.
window.showerTimer = (end, serverNow) => ({
    display: '', interval: null, origin: 0, remaining: 0,
    init() {
        this.origin = performance.now();
        this.remaining = Math.max(0, Date.parse(end) - Date.parse(serverNow));
        const tick = () => { const seconds = Math.max(0, Math.ceil((this.remaining - (performance.now() - this.origin)) / 1000)); this.display = `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`; };
        tick(); this.interval = setInterval(tick, 1000);
    },
    destroy() { clearInterval(this.interval); },
});

let installPrompt;
window.addEventListener('beforeinstallprompt', event => { event.preventDefault(); installPrompt = event; document.querySelectorAll('[data-install-app]').forEach(button => button.hidden = false); });
const csrf = () => document.querySelector('meta[name="csrf-token"]').content;
const subscribeRequest = (method, body) => fetch('/push-subscriptions', { method, credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf() }, body: JSON.stringify(body) });
const applicationKey = value => Uint8Array.from(atob(value.replace(/-/g, '+').replace(/_/g, '/').padEnd(Math.ceil(value.length / 4) * 4, '=')), char => char.charCodeAt(0));

document.addEventListener('click', async event => {
    if (event.target.closest('[data-theme-toggle]')) {
        const next = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
        document.documentElement.dataset.theme = next;
        document.querySelector('meta[name=theme-color]').content = next === 'dark' ? '#101416' : '#f8f7f4';
        try { localStorage.setItem('sq-theme', next); } catch { /* Appearance still works when storage is unavailable. */ }
    }
    if (event.target.closest('[data-install-app]') && installPrompt) { await installPrompt.prompt(); installPrompt = null; }
    const enable = event.target.closest('[data-enable-push]');
    const disable = event.target.closest('[data-disable-push]');
    if (!enable && !disable) return;
    const container = (enable || disable).closest('[data-push-settings]');
    const status = container.querySelector('[data-push-status]');
    if (!('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) { status.textContent = 'Push is unavailable here. Install the app or use your notification inbox.'; return; }
    if (!container.dataset.vapid) { status.textContent = 'Phone push is awaiting server setup. Your in-app inbox is ready to use.'; return; }
    (enable || disable).disabled = true;
    try {
        if (enable) {
            const permission = await Notification.requestPermission();
            if (permission !== 'granted') { status.textContent = 'Permission wasn’t granted. You can enable it in browser settings, or use your inbox.'; return; }
            const registration = await navigator.serviceWorker.ready;
            const subscription = await registration.pushManager.getSubscription() || await registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: applicationKey(container.dataset.vapid) });
            const response = await subscribeRequest('POST', subscription.toJSON());
            if (!response.ok) throw new Error('Couldn’t connect this phone. Reconnect and try again; your inbox still works.');
            status.textContent = 'Phone notifications are enabled for this account on this device.';
        } else {
            const registration = await navigator.serviceWorker.ready;
            const subscription = await registration.pushManager.getSubscription();
            if (subscription) { const response = await subscribeRequest('DELETE', { endpoint: subscription.endpoint }); if (!response.ok) throw new Error('Couldn’t disconnect this device. Please try again.'); await subscription.unsubscribe(); }
            status.textContent = 'Phone notifications are off on this device. Your inbox remains available.';
        }
    } catch (error) { status.textContent = error.message || 'Push couldn’t be enabled. Use the inbox for now.'; }
    finally { (enable || disable).disabled = false; }
});

if ('serviceWorker' in navigator) window.addEventListener('load', () => navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch(() => {}));
// Offline mutations are never queued or retried automatically.
document.addEventListener('submit', event => { if (!navigator.onLine) { event.preventDefault(); event.stopImmediatePropagation(); } }, true);
