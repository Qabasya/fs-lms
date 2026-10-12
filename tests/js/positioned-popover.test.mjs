import assert from 'node:assert/strict';
import test from 'node:test';

import { openGradePopPositioned, closeGradePop } from '../../src/js/profile/utils.js';

function stand(t) {
    const previousWindow = globalThis.window;
    const previousDocument = globalThis.document;
    const listeners = new Set();
    const classes = new Set();
    let writes = 0;
    const style = new Proxy({}, { set(target, key, value) { writes++; target[key] = value; return true; } });
    const pop = {
        style,
        get offsetWidth() { return Math.min(620, globalThis.window.innerWidth - 20); },
        offsetHeight: 400,
        classList: {
            add: (...names) => names.forEach(name => classes.add(name)),
            remove: (...names) => names.forEach(name => classes.delete(name)),
            contains: name => classes.has(name),
        },
    };
    const anchor = {
        isConnected: true,
        getBoundingClientRect: () => anchor.isConnected ? { left: 1400, top: 200, bottom: 240, width: 100 } : { left: 0, top: 0, bottom: 0, width: 0 },
    };
    globalThis.window = {
        innerWidth: 3008, innerHeight: 1500,
        addEventListener: (name, callback) => { if (name === 'resize') listeners.add(callback); },
        removeEventListener: (name, callback) => { if (name === 'resize') listeners.delete(callback); },
    };
    globalThis.document = { getElementById: id => id === 'profGradePop' ? pop : null };
    t.after(() => {
        closeGradePop();
        globalThis.window = previousWindow;
        globalThis.document = previousDocument;
    });
    return { pop, anchor, listeners, classes, writes: () => writes, resize: () => [...listeners].forEach(fn => fn()) };
}

test('открытая форма следует за сужением окна и остаётся внутри мобильного экрана', t => {
    const s = stand(t);
    openGradePopPositioned(s.pop, s.anchor);
    assert.ok(Number.parseFloat(s.pop.style.left) > 1000);
    globalThis.window.innerWidth = 390;
    globalThis.window.innerHeight = 844;
    s.resize();
    assert.equal(s.pop.style.left, '10px');
    assert.ok(Number.parseFloat(s.pop.style.top) + s.pop.offsetHeight <= 834);
});

test('повторное открытие не накапливает подписки; закрытие снимает подписку', t => {
    const s = stand(t);
    openGradePopPositioned(s.pop, s.anchor);
    openGradePopPositioned(s.pop, s.anchor);
    assert.equal(s.listeners.size, 1);
    closeGradePop();
    assert.equal(s.listeners.size, 0);
    assert.equal(s.classes.has('open'), false);
});

test('центрированная модальная форма не получает координаты якорного поповера', t => {
    const s = stand(t);
    openGradePopPositioned(s.pop, s.anchor);
    s.classes.add('is-modal');
    const before = s.writes();
    s.resize();
    assert.equal(s.writes(), before);
});

test('исчезнувший якорь не оставляет открытую форму вне экрана', t => {
    const s = stand(t);
    openGradePopPositioned(s.pop, s.anchor);
    s.anchor.isConnected = false;
    globalThis.window.innerWidth = 390;
    s.resize();
    assert.equal(s.pop.style.left, '10px');
    assert.equal(s.pop.style.top, '10px');
    assert.equal(s.listeners.size, 1);
});
