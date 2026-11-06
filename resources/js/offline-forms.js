// navigator.onLine is only a hint. Native forms must be allowed to reach the
// server (especially sign-in and password changes on a reachable local network).
export function handleOfflineSubmit(event) {
    if (navigator.onLine || !event.target.hasAttribute('wire:submit')) return;
    event.preventDefault();
    event.stopImmediatePropagation();
    let notice = event.target.querySelector('[data-offline-error]');
    if (!notice) {
        notice = document.createElement('p');
        notice.setAttribute('data-offline-error', '');
        notice.setAttribute('role', 'alert');
        notice.setAttribute('tabindex', '-1');
        notice.className = 'error-notice';
        event.target.prepend(notice);
    }
    notice.textContent = 'This change was not saved. Reconnect and try again.';
    notice.focus();
}
