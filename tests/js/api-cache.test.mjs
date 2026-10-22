import assert from 'node:assert/strict';
import test from 'node:test';

import { FS_LMS_API, clearApiCache } from '../../src/js/profile/api.js';

const block = { nonce: 'n', actions: { getThing: 'get_thing', getOther: 'get_other', saveThing: 'save_thing', log: 'log_evt' } };

/** Подменяет транспорт и считает вызовы по action. */
function stubTransport(responses = {}) {
    const calls = [];
    FS_LMS_API.request = async (action, nonce, params) => {
        calls.push(action);
        const r = responses[action];
        return typeof r === 'function' ? r(calls.filter((a) => a === action).length, params) : (r ?? { ok: true });
    };
    clearApiCache();
    return calls;
}

test('повторное чтение с теми же параметрами берётся из кеша', async () => {
    const calls = stubTransport();
    const api = FS_LMS_API.createApi(block, { cache: { getThing: 10000 } });
    await api('getThing', { id: 1 });
    await api('getThing', { id: 1 });
    assert.deepEqual(calls, ['get_thing']);
});

test('другие параметры — другая запись; порядок ключей не важен', async () => {
    const calls = stubTransport();
    const api = FS_LMS_API.createApi(block, { cache: { getThing: 10000 } });
    await api('getThing', { a: 1, b: 2 });
    await api('getThing', { b: 2, a: 1 });
    await api('getThing', { a: 1, b: 3 });
    assert.equal(calls.length, 2);
});

test('действие без правила не кешируется', async () => {
    const calls = stubTransport();
    const api = FS_LMS_API.createApi(block, { cache: { getThing: 10000 } });
    await api('getOther', {});
    await api('getOther', {});
    assert.equal(calls.length, 2);
});

test('запись сбрасывает кеш, пассивное действие — нет', async () => {
    const calls = stubTransport();
    const api = FS_LMS_API.createApi(block, { cache: { getThing: 10000 }, passive: ['log'] });
    await api('getThing', {});
    await api('log', {});
    await api('getThing', {});
    assert.equal(calls.filter((a) => a === 'get_thing').length, 1);
    await api('saveThing', {});
    await api('getThing', {});
    assert.equal(calls.filter((a) => a === 'get_thing').length, 2);
});

test('истёкший ttl — повторный запрос', async () => {
    const calls = stubTransport();
    const api = FS_LMS_API.createApi(block, { cache: { getThing: 0 } });
    await api('getThing', {});
    await api('getThing', {});
    assert.equal(calls.length, 2);
});

test('вызывающий получает копию: правка не портит кеш', async () => {
    stubTransport({ get_thing: { list: [1] } });
    const api = FS_LMS_API.createApi(block, { cache: { getThing: 10000 } });
    (await api('getThing', {})).list.push(2);
    assert.deepEqual((await api('getThing', {})).list, [1]);
});

test('ответ, начатый до записи, в кеш не попадает', async () => {
    let release;
    const gate = new Promise((r) => { release = r; });
    const calls = [];
    FS_LMS_API.request = async (action) => {
        calls.push(action);
        if ('get_thing' === action && 1 === calls.filter((a) => a === 'get_thing').length) { await gate; return { v: 'old' }; }
        return { v: 'new' };
    };
    clearApiCache();
    const api = FS_LMS_API.createApi(block, { cache: { getThing: 10000 } });
    const first = api('getThing', {});
    await api('saveThing', {});
    release();
    await first;
    assert.deepEqual(await api('getThing', {}), { v: 'new' });
});

test('swr: из кеша сразу, при изменении на сервере — onUpdate', async () => {
    const calls = stubTransport({ get_thing: (n) => ({ v: n }) });
    const api = FS_LMS_API.createApi(block, { cache: { getThing: { ttl: 10000, swr: true } } });
    await api('getThing', {});
    let updated = null;
    const shown = await api('getThing', {}, { onUpdate: (d) => { updated = d; } });
    assert.deepEqual(shown, { v: 1 });
    await new Promise((r) => setTimeout(r, 0));
    assert.deepEqual(updated, { v: 2 });
    assert.equal(calls.length, 2);
});

test('prefetch прогревает кеш', async () => {
    const calls = stubTransport();
    const api = FS_LMS_API.createApi(block, { cache: { getThing: 10000 } });
    api.prefetch('getThing', { id: 7 });
    await api('getThing', { id: 7 });
    assert.equal(calls.length, 1);
});
