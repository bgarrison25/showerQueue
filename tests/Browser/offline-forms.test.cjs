const { test } = require('node:test');
const assert = require('node:assert/strict');
test('native password forms submit even when the browser reports offline', async () => {
    const { handleOfflineSubmit } = await import('../../resources/js/offline-forms.js');
    Object.defineProperty(globalThis, 'navigator', { configurable: true, value: { onLine: false } });
    handleOfflineSubmit({ target: { hasAttribute: () => false }, preventDefault: () => assert.fail('native submission blocked'), stopImmediatePropagation: () => assert.fail('native submission blocked') });
});
test('blocked offline Livewire forms explain that nothing was saved', async () => {
    const { handleOfflineSubmit } = await import('../../resources/js/offline-forms.js');
    Object.defineProperty(globalThis, 'navigator', { configurable: true, value: { onLine: false } });
    let prevented = false, stopped = false, focused = false;
    const notice = { focus: () => { focused = true; } };
    handleOfflineSubmit({ target: { hasAttribute: () => true, querySelector: () => notice }, preventDefault: () => { prevented = true; }, stopImmediatePropagation: () => { stopped = true; } });
    assert.ok(prevented && stopped && focused);
    assert.match(notice.textContent, /not saved/);
});
