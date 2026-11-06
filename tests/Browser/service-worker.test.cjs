const { test } = require('node:test');
const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const { join } = require('node:path');
const { runInNewContext } = require('node:vm');

function worker(existingWindow = false) {
    const listeners = {}, calls = [];
    const client = {
        url: 'https://showerqueue.test/inbox',
        navigate: async url => calls.push(['navigate', url]),
        focus: async () => calls.push(['focus']),
    };
    runInNewContext(readFileSync(join(__dirname, '../../public/sw.js'), 'utf8'), {
        URL,
        self: {
            location: { origin: 'https://showerqueue.test' },
            addEventListener: (name, handler) => listeners[name] = handler,
            registration: { showNotification: async (title, options) => calls.push(['show', options.data.url]) },
            clients: {
                matchAll: async () => existingWindow ? [client] : [],
                openWindow: async url => calls.push(['open', url]),
            },
        },
    });
    return { calls, async emit(name, event) {
        let pending;
        listeners[name]({ ...event, waitUntil: promise => pending = promise });
        await pending;
    } };
}

test('phone notifications retain their destination and open it in a new window', async () => {
    for (const url of ['/swaps#swap-42', '/?day=2026-11-10', '/calendar?day=2026-12-24#selected-day-events']) {
        const w = worker();
        await w.emit('push', { data: { json: () => ({ title: 'Update', data: { url } }) } });
        assert.deepEqual(w.calls, [['show', url]]);
        await w.emit('notificationclick', { notification: { close() {}, data: { url: w.calls[0][1] } } });
        assert.deepEqual(w.calls[1], ['open', url]);
    }
});

test('clicking a notification navigates and focuses an existing app window', async () => {
    const w = worker(true);
    await w.emit('notificationclick', { notification: { close() {}, data: { url: '/swaps#swap-12' } } });
    assert.deepEqual(w.calls, [['navigate', '/swaps#swap-12'], ['focus']]);
});

test('missing, malformed, external and unsupported destinations fall back to inbox', async () => {
    for (const url of [undefined, 'javascript:alert(1)', '//evil.test/swaps', 'https://evil.test/calendar', '/logout']) {
        const w = worker();
        await w.emit('push', { data: { json: () => ({ data: { url } }) } });
        assert.deepEqual(w.calls, [['show', '/inbox']]);
        await w.emit('notificationclick', { notification: { close() {}, data: { url } } });
        assert.deepEqual(w.calls[1], ['open', '/inbox']);
    }
    const w = worker();
    await w.emit('push', { data: { json: () => null } });
    await w.emit('push', { data: { json() { throw Error('Bad JSON'); } } });
    assert.deepEqual(w.calls, [['show', '/inbox'], ['show', '/inbox']]);
});
