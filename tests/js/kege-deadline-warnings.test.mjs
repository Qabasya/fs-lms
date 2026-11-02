import assert from 'node:assert/strict';
import test from 'node:test';

globalThis.jQuery = () => ({});
globalThis.window = {};
const { deadlineWarnings } = await import('../../src/js/kege/kege-exam.js');

test('предупреждает ровно один раз за 30, 10 и 5 минут', () => {
    const calls = [];
    const modal = { init: () => calls.push('init'), show: message => calls.push(message) };
    const tick = deadlineWarnings((message, kind) => calls.push([message, kind]), modal);
    [1801, 1800, 1799, 600, 599, 300, 299].forEach(tick);
    assert.equal(calls.length, 4);
    assert.match(calls[0][0], /30 минут/);
    assert.equal(calls[0][1], 'warning');
    assert.match(calls[1][0], /10 минут/);
    assert.equal(calls[1][1], 'error');
    assert.equal(calls[2], 'init');
    assert.match(calls[3], /автоматически завершится через 5 минут/);
});

test('при позднем входе показывает только актуальное предупреждение', () => {
    const calls = [];
    const tick = deadlineWarnings((message, kind) => calls.push(kind), { init() {}, show() { calls.push('alert'); } });
    tick(250); tick(249);
    assert.deepEqual(calls, ['alert']);
});
