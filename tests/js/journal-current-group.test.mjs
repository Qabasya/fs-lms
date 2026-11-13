import assert from 'node:assert/strict';
import { test } from 'node:test';

const root = { innerHTML: '' };
const groups = [{id: 1}, {id: 2}];
const requests = [];
let current = 2;
globalThis.window = {
    location: {href: 'https://example.test/profile/?screen=journal'},
    fsProfile: { groups, journal: {actions: {getJournal: 'journal'}}, dashboard: {actions: {getDashboard: 'dashboard'}} },
    FS_LMS_API: { request: (action, nonce, params) => {
        requests.push({action, params});
        return action === 'dashboard' ? Promise.resolve({current_group_id: current}) : new Promise(() => {});
    } },
};

const transport = window.FS_LMS_API.request;
const { renderJournal, setJournalGroup } = await import('../../src/js/profile/journal.js');
window.FS_LMS_API.request = transport;

test('журнал выбирает текущую группу, сохраняет defaults и явную ссылку', async () => {
    await renderJournal(root);
    assert.equal(requests.at(-1).params.group_id, 2);
    current = null;
    await renderJournal(root);
    assert.equal(requests.at(-1).params.group_id, 1);
    window.location.href += '&journal_g=2';
    requests.length = 0;
    await renderJournal(root);
    assert.deepEqual(requests.map(r => r.action), ['journal']);
    assert.equal(requests[0].params.group_id, 2);
});

test('задержавшийся автовыбор не отменяет ручной выбор группы', async () => {
    window.location.href = 'https://example.test/profile/?screen=journal';
    let resolve;
    window.FS_LMS_API.request = (action, nonce, params) => {
        if (action === 'dashboard') return new Promise(r => { resolve = r; });
        requests.push({action, params});
        return new Promise(() => {});
    };
    requests.length = 0;
    const pending = renderJournal(root);
    setJournalGroup(1);
    resolve({current_group_id: 2});
    await pending;
    assert.equal(requests.length, 1);
    assert.equal(requests[0].params.group_id, 1);
});
