import assert from 'node:assert/strict';
import test from 'node:test';

import { urlencodedBody } from '../../src/js/common/nonce-refresh.js';

test('FormData без файлов превращается в urlencoded с теми же полями', () => {
    const fd = new FormData();
    fd.append('action', 'submit_batch_work');
    fd.append('answers', '{"1":"a"}');
    fd.append('ids[]', '1');
    fd.append('ids[]', '2');

    const body = urlencodedBody(fd);

    assert.ok(body instanceof URLSearchParams);
    assert.deepEqual([...body.entries()], [['action', 'submit_batch_work'], ['answers', '{"1":"a"}'], ['ids[]', '1'], ['ids[]', '2']]);
});

test('FormData с файлом остаётся multipart', () => {
    const fd = new FormData();
    fd.append('action', 'upload');
    fd.append('file', new Blob(['x']), 'a.txt');

    assert.equal(urlencodedBody(fd), fd);
});

test('не FormData возвращается как есть', () => {
    assert.equal(urlencodedBody('a=1'), 'a=1');
    assert.equal(urlencodedBody(undefined), undefined);
});
