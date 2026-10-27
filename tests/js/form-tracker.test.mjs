import assert from 'node:assert/strict';
import test from 'node:test';

/* Общее ядро трекеров публичных форм (заявка, регистрация родителя, запись на экзамен). */

function setup() {
    const listeners = { form: {}, window: {} };
    const sent = [];
    globalThis.window = {
        addEventListener: (name, fn) => { listeners.window[name] = fn; },
    };
    globalThis.fetch = (url, init) => { sent.push({ url, init, body: Object.fromEntries(init.body) , fields: init.body.getAll('fields[]') }); return Promise.resolve(); };
    const form = { addEventListener: (name, fn) => { listeners.form[name] = fn; } };
    return { listeners, sent, form };
}

async function load() {
    return import('../../src/js/frontend/services/form-tracker.js');
}

const config = (extra = {}) => ({
    ajaxUrl: '/ajax',
    params: () => ({ action: 'track', security: 'n', visit: 'v1' }),
    fieldStats: () => ({ total: 4, filled: 1 }),
    invalidFieldNames: () => ['email'],
    ...extra,
});

test('first input sends "started" once, with counters and no field values', async () => {
    const { createFormTracker } = await load();
    const { listeners, sent, form } = setup();
    createFormTracker(form, config());

    listeners.form.input();
    listeners.form.input();

    assert.equal(sent.length, 1);
    assert.equal(sent[0].url, '/ajax');
    assert.equal(sent[0].body.event, 'started');
    assert.equal(sent[0].body.filled, '1');
    assert.equal(sent[0].body.total, '4');
    assert.equal(sent[0].body.visit, 'v1');
    assert.equal(sent[0].init.keepalive, true);
});

test('invalid sends the invalid field names; failed sends the server field', async () => {
    const { createFormTracker } = await load();
    const { sent, form } = setup();
    const tracker = createFormTracker(form, config());

    tracker.submitAttempt();
    tracker.invalid('нет email');
    tracker.failed('отказ', 'phone');

    assert.equal(sent[0].body.event, 'invalid');
    assert.deepEqual(sent[0].fields, ['email']);
    assert.equal(sent[0].body.submits, '1');
    assert.equal(sent[0].body.message, 'нет email');
    assert.equal(sent[1].body.event, 'submit_failed');
    assert.deepEqual(sent[1].fields, ['phone']);
});

test('page hide sends "left" once unless the form succeeded', async () => {
    const { createFormTracker } = await load();
    const a = setup();
    createFormTracker(a.form, config());
    a.listeners.window.pagehide();
    a.listeners.window.pagehide();
    assert.equal(a.sent.filter((s) => s.body.event === 'left').length, 1);

    const b = setup();
    const done = createFormTracker(b.form, config());
    done.succeeded();
    b.listeners.window.pagehide();
    assert.equal(b.sent.length, 0);
});

test('bfcache restore lets "left" be sent again', async () => {
    const { createFormTracker } = await load();
    const { listeners, sent, form } = setup();
    createFormTracker(form, config());

    listeners.window.pagehide();
    listeners.window.pageshow({ persisted: true });
    listeners.window.pagehide();

    assert.equal(sent.filter((s) => s.body.event === 'left').length, 2);
});

test('leftRequiresStarted: no "left" before the first input', async () => {
    const { createFormTracker } = await load();
    const { listeners, sent, form } = setup();
    createFormTracker(form, config({ leftRequiresStarted: true }));

    listeners.window.pagehide();
    assert.equal(sent.length, 0);
});

test('params are evaluated on every send, so stage changes are picked up', async () => {
    const { createFormTracker } = await load();
    const { sent, form } = setup();
    let stage = 'form';
    const tracker = createFormTracker(form, config({ params: () => ({ action: 'track', security: 'n', visit: 'v1', stage }) }));

    tracker.invalid();
    stage = 'otp';
    tracker.invalid();

    assert.equal(sent[0].body.stage, 'form');
    assert.equal(sent[1].body.stage, 'otp');
});

test('extra params of one event (captcha) go only into that event', async () => {
    const { createFormTracker } = await load();
    const { sent, form } = setup();
    const tracker = createFormTracker(form, config());

    tracker.send('captcha_failed', { captcha: 'closed' });
    tracker.invalid();

    assert.equal(sent[0].body.captcha, 'closed');
    assert.equal(sent[1].body.captcha, undefined);
});
