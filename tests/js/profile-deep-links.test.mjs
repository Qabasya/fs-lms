import assert from 'node:assert/strict';
import test from 'node:test';

import { submissionDeepLink } from '../../src/js/profile/app.js';

test('notification deep-link resolves a valid submission and parent screen', () => {
    const params = new URLSearchParams('screen=works&submission=321');

    assert.deepEqual(submissionDeepLink(params, ['works', 'summary']), {
        from: 'works',
        sourceId: 321,
    });
});

test('submission deep-link rejects invalid ids and unavailable screens', () => {
    for (const query of [
        'screen=works&submission=0',
        'screen=works&submission=1.5',
        'screen=works&submission=9007199254740992',
        'screen=works&submission=1e2',
        'screen=works&submission=0x10',
        'screen=dashboard&submission=321',
        'screen=works&submission=not-a-number',
    ]) {
        assert.equal(submissionDeepLink(new URLSearchParams(query), ['works']), null, query);
    }
});
