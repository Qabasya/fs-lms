import assert from 'node:assert/strict';
import test from 'node:test';

import { appendParam } from '../../src/js/profile/api.js';

const encode = (params) => {
    const body = new URLSearchParams();
    Object.entries(params).forEach(([k, v]) => appendParam(body, k, v));
    return [...body.entries()];
};

test('массив скаляров уходит как key[]', () => {
    assert.deepEqual(encode({ ids: [1, 2] }), [['ids[]', '1'], ['ids[]', '2']]);
});

test('массив объектов уходит как key[i][поле] (массовое утверждение)', () => {
    assert.deepEqual(encode({ items: [{ attempt_id: 5, result_version: 2 }] }), [['items[0][attempt_id]', '5'], ['items[0][result_version]', '2']]);
});

test('скаляры остаются как были, пустые значения не отправляются', () => {
    assert.deepEqual(encode({ a: 'x', b: 0, c: null, d: undefined }), [['a', 'x'], ['b', '0']]);
});
