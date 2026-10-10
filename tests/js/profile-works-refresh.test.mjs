import assert from 'node:assert/strict';
import test from 'node:test';


class FakeElement {
    constructor(dataset = {}) {
        this.dataset = dataset;
        this.listeners = {};
        this.value = '';
    }

    addEventListener(type, callback) {
        this.listeners[type] = callback;
    }

    fire(type) {
        this.listeners[type]?.({ currentTarget: this, target: this });
    }
}

class FakeRoot {
    set innerHTML(html) {
        this.html = html;
    }

    querySelectorAll(selector) {
        if (selector === '[data-tab]') {
            this.tabs = ['pending', 'confirm', 'done'].map((tab) => new FakeElement({ tab }));
            return this.tabs;
        }
        if (selector === '.wk-row[data-src-id]') {
            this.workRows = [new FakeElement({ srcType: 'work', srcId: '50' })];
            return this.workRows;
        }
        return [];
    }

    querySelector(selector) {
        if (selector === '[data-refresh]') {
            this.refreshButton = new FakeElement();
            return this.refreshButton;
        }
        if (selector === '.wk-back') {
            this.backButton = new FakeElement();
            return this.backButton;
        }
        if (selector === '#wkGroupBtn') { return null; }
        if (selector === '#wkTypeFilter' || selector === '#wkSort') {
            this[selector] ||= new FakeElement();
            return this[selector];
        }
        return null;
    }
}

const tick = () => new Promise((resolve) => setImmediate(resolve));
const list = [{ source_type: 'work', source_id: 50, title: 'Практика', label: 'Практика', count: 2, group_ids: [], latest_at: '' }];
const submissions = [{ source_type: 'submission', source_id: 91, student_name: 'Иванов Иван', marks: [], submitted_at: '2026-10-10 10:00:00' }];

test('refresh preserves the selected tab, filter, and work detail while refreshing its rows and counts', async () => {
    const calls = [];
    let failRefresh = false;
    globalThis.window = {
        fsProfile: {
            works: { nonce: 'nonce', actions: { getPendingWorks: 'pending', getWorkSubmissions: 'submissions' } },
            groups: [],
        },
    };
    const { renderWorks } = await import('../../src/js/profile/works.js');
    const { FS_LMS_API } = await import('../../src/js/profile/api.js');
    window.FS_LMS_API = FS_LMS_API;
    window.FS_LMS_API.request = async (action, nonce, params) => {
        calls.push({ action, params });
        if (failRefresh) { throw new Error('network error'); }
        return action === 'pending' ? list : submissions;
    };
    globalThis.document = { getElementById: () => null };
    const root = new FakeRoot();
    renderWorks(root);
    await tick();

    root.tabs.find((tab) => tab.dataset.tab === 'done').fire('click');
    await tick();
    root['#wkTypeFilter'].value = 'Практика';
    root['#wkTypeFilter'].fire('change');
    root.workRows[0].fire('click');
    await tick();

    const beforeRefresh = calls.length;
    root.refreshButton.fire('click');
    assert.match(root.html, /Обновление…/);
    const afterFirstClick = calls.length;
    root.refreshButton.fire('click');
    assert.equal(calls.length, afterFirstClick, 'a second click must not start a duplicate refresh');
    await tick();

    assert.equal(calls.length, beforeRefresh + 4, 'refresh reloads all three queue tabs and the selected work submissions');
    assert.ok(calls.slice(beforeRefresh).some((call) => call.action === 'submissions' && call.params.tab === 'done' && call.params.source_id === 50));
    assert.match(root.html, /Иванов Иван/);
    assert.match(root.html, /class="wk-tab active" data-tab="done"/);

    failRefresh = true;
    root.refreshButton.fire('click');
    await tick();
    assert.match(root.html, /Иванов Иван/, 'a failed refresh keeps the last displayed submissions');

    root.backButton.fire('click');
    assert.match(root.html, /value="Практика" selected/);
});

test('a tab change during refresh prevents stale rows and counts from replacing the new tab', async () => {
    const delayed = [];
    let refreshPendingRequests = 0;
    let holdRefresh = false;
    globalThis.window = {
        fsProfile: {
            works: { nonce: 'nonce', actions: { getPendingWorks: 'pending', getWorkSubmissions: 'submissions' } },
            groups: [],
        },
    };
    const { renderWorks } = await import('../../src/js/profile/works.js');
    const { FS_LMS_API } = await import('../../src/js/profile/api.js');
    window.FS_LMS_API = FS_LMS_API;
    window.FS_LMS_API.request = async (action, nonce, params) => {
        if (holdRefresh && action === 'pending' && refreshPendingRequests < 3) {
            refreshPendingRequests += 1;
            return new Promise((resolve) => delayed.push({ tab: params.tab, resolve }));
        }
        return [{ ...list[0], count: params.tab === 'done' ? 1 : 2 }];
    };
    const root = new FakeRoot();
    renderWorks(root);
    await tick();

    // Hold the three refresh queue calls, then let the new tab request complete first.
    refreshPendingRequests = 0;
    holdRefresh = true;
    root.refreshButton.fire('click');
    holdRefresh = false;
    root.tabs.find((tab) => tab.dataset.tab === 'done').fire('click');
    await tick();
    delayed.forEach(({ tab, resolve }) => resolve([{ ...list[0], count: 99, title: `stale ${tab}` }]));
    await tick();

    assert.match(root.html, /class="wk-tab active" data-tab="done"[\s\S]*?<span class="wk-tab-badge">1<\/span>/);
    assert.doesNotMatch(root.html, /stale done/);
});
