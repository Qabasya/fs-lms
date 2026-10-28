import assert from 'node:assert/strict';
import test from 'node:test';

globalThis.window = { location: { href: 'https://site.test/profile/?screen=exam-results&exam_x=1' } };

const { hrefOf } = await import('../../src/js/profile/new-tab.js');

/** Строка кабинета: только то, что читает hrefOf. */
const row = (screen, dataset) => ({
    dataset,
    matches: () => false,
    closest: () => ({ dataset: { screen } }),
});

test('exam attempt row in results opens its work through a deep link', () => {
    const href = hrefOf(row('exam-results', { srcType: 'attempt', srcId: '77' }));

    assert.equal(href, 'https://site.test/profile/?screen=exam-results&attempt=77');
});

test('exam attempt row has no address on screens without the deep link', () => {
    assert.equal(hrefOf(row('works', { srcType: 'attempt', srcId: '77' })), null);
});

test('submission rows keep their own deep link', () => {
    assert.equal(
        hrefOf(row('works', { srcType: 'submission', srcId: '5' })),
        'https://site.test/profile/?screen=works&submission=5',
    );
});
