import assert from 'node:assert/strict';
import { test } from 'node:test';

const windowEvents = {};
const documentEvents = {};
const element = {innerHTML: '', addEventListener() {}, querySelectorAll: () => []};
const root = { innerHTML: '', querySelector: selector => selector === '#learnerChild' ? null : element };
const pending = [];
let requests = 0;
globalThis.window = {
    fsProfile: { learner: {nonce: 'n', actions: {getProfile: 'get_profile'}} },
    addEventListener: (name, callback) => { windowEvents[name] = callback; },
    FS_LMS_API: { request: () => { requests++; return new Promise(() => {}); } },
};
globalThis.document = {
    visibilityState: 'visible',
    addEventListener: (name, callback) => { documentEvents[name] = callback; },
    querySelector: () => root,
    querySelectorAll: () => [],
};
const { renderLearnerHome, enterHomeCustomize } = await import('../../src/js/profile/learner.js');
window.FS_LMS_API.request = () => { requests++; return new Promise((resolve, reject) => pending.push({resolve, reject})); };

test('возврат на главную, из кеша браузера и из другой вкладки получает свежий профиль; редактор не прерывается', async () => {
    const settle = () => new Promise(resolve => setImmediate(resolve));
    renderLearnerHome(root);
    await settle();
    assert.equal(requests, 1);
    renderLearnerHome(root);
    await settle();
    assert.equal(requests, 2);
    windowEvents.pageshow({persisted: false});
    await settle();
    assert.equal(requests, 2);
    windowEvents.pageshow({persisted: true});
    await settle();
    assert.equal(requests, 3);
    document.visibilityState = 'hidden';
    documentEvents.visibilitychange();
    await settle();
    assert.equal(requests, 3);
    document.visibilityState = 'visible';
    documentEvents.visibilitychange();
    await settle();
    assert.equal(requests, 4);
    const profile = topic => ({deadlines: [], groups: [], homework: {status: 'active', topic, subject: 'Алгебра', remaining: 1, total: 2, player_url: '/work'}});
    pending[3].resolve(profile('Свежая домашняя работа'));
    await settle();
    assert.match(root.innerHTML, /Свежая домашняя работа/);
    pending[2].resolve(profile('Устаревшая домашняя работа'));
    await settle();
    assert.match(root.innerHTML, /Свежая домашняя работа/);
    assert.doesNotMatch(root.innerHTML, /Устаревшая домашняя работа/);
    pending[0].reject(new Error('Устаревшая ошибка'));
    await settle();
    assert.match(root.innerHTML, /Свежая домашняя работа/);
    assert.doesNotMatch(root.innerHTML, /Устаревшая ошибка/);
    enterHomeCustomize();
    windowEvents.pageshow({persisted: true});
    documentEvents.visibilitychange();
    await settle();
    assert.equal(requests, 4);
});
