import assert from 'node:assert/strict';
import test from 'node:test';

import { submissionDeepLink, attemptDeepLink } from '../../src/js/profile/app.js';

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

test('attempt deep-link opens an exam work from the screen it was opened on', () => {
    for (const from of ['exam-results', 'exam-conduct', 'exam-stats']) {
        assert.deepEqual(
            attemptDeepLink(new URLSearchParams(`screen=${from}&attempt=77`), [from]),
            { from, sourceId: 77 },
            from,
        );
    }
});

test('attempt deep-link rejects invalid ids, foreign screens and screens the role lacks', () => {
    for (const [query, screens] of [
        ['screen=exam-results&attempt=0', ['exam-results']],
        ['screen=exam-results&attempt=1.5', ['exam-results']],
        ['screen=exam-results&attempt=1e2', ['exam-results']],
        ['screen=exam-results&attempt=abc', ['exam-results']],
        ['screen=exam-results', ['exam-results']],
        ['screen=works&attempt=5', ['works']],
        ['screen=exam-results&attempt=5', ['works']],
    ]) {
        assert.equal(attemptDeepLink(new URLSearchParams(query), screens), null, query);
    }
});
