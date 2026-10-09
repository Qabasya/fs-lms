import assert from 'node:assert/strict';
import test from 'node:test';

import { shouldReloadOnPageshow } from '../../src/js/frontend/services/exam-guest-model.js';

test('возврат из кеша переходов требует перезагрузки', () => {
    assert.equal(shouldReloadOnPageshow({ persisted: true }), true);
});

test('обычная загрузка страницы не перезагружается', () => {
    assert.equal(shouldReloadOnPageshow({ persisted: false }), false);
    assert.equal(shouldReloadOnPageshow({}), false);
    assert.equal(shouldReloadOnPageshow(null), false);
});
