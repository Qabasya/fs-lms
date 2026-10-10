import assert from 'node:assert/strict';
import test from 'node:test';

import { shouldReloadOnPageshow } from '../../src/js/frontend/services/exam-guest-model.js';
import { initExamGuest } from '../../src/js/frontend/services/exam-guest.js';

test('возврат из кеша переходов требует перезагрузки', () => {
    assert.equal(shouldReloadOnPageshow({ persisted: true }), true);
});

test('обычная загрузка страницы не перезагружается', () => {
    assert.equal(shouldReloadOnPageshow({ persisted: false }), false);
    assert.equal(shouldReloadOnPageshow({}), false);
    assert.equal(shouldReloadOnPageshow(null), false);
});

for (const id of ['fs-exam-signup', 'fs-exam-entry', 'fs-exam-result', 'fs-exam-report']) {
    test(`${id}: восстановление перепроверяет доступ без AJAX-конфигурации`, t => {
        const previousWindow = globalThis.window;
        const previousDocument = globalThis.document;
        t.after(() => {
            globalThis.window = previousWindow;
            globalThis.document = previousDocument;
        });
        const listeners = new Map();
        let reloads = 0;
        globalThis.window = {
            addEventListener: (name, callback) => listeners.set(name, callback),
            location: { reload: () => { reloads++; } },
        };
        globalThis.document = {
            getElementById: name => name === id ? { dataset: {}, querySelector: () => null } : null,
        };

        initExamGuest();
        assert.equal(typeof listeners.get('pageshow'), 'function');
        listeners.get('pageshow')({ persisted: false });
        assert.equal(reloads, 0);
        listeners.get('pageshow')({ persisted: true });
        assert.equal(reloads, 1);
    });
}
