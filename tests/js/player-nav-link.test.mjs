import assert from 'node:assert/strict';
import test from 'node:test';

import { backAction, profileReferrer, stepIndexFromParam, stepNumberOf } from '../../src/js/player/nav-link.js';

const PROFILE = 'https://example.test/profile/';
const panel = (step, virtual = '') => ({ dataset: { step, virtual } });

test('referrer counts only when it is the profile page of the same site', () => {
    assert.equal(profileReferrer(`${PROFILE}?screen=ktp&ktp_g=4`, PROFILE), `${PROFILE}?screen=ktp&ktp_g=4`);
    assert.equal(profileReferrer('https://other.test/profile/?screen=ktp', PROFILE), '');
    assert.equal(profileReferrer('https://example.test/group/?gl=1', PROFILE), '');
    assert.equal(profileReferrer('', PROFILE), '');
});

test('back returns through history when the profile is the previous page', () => {
    const ref = `${PROFILE}?screen=journal&journal_g=7&journal_m=2026-10`;

    assert.deepEqual(
        backAction({ referrer: ref, profileUrl: PROFILE, stored: '', historyLength: 3 }),
        { action: 'back', url: ref },
    );
});

test('back opens the profile address in a fresh tab (empty history)', () => {
    const ref = `${PROFILE}?screen=ktp&ktp_g=4`;

    assert.deepEqual(
        backAction({ referrer: ref, profileUrl: PROFILE, stored: '', historyLength: 1 }),
        { action: 'go', url: ref },
    );
});

test('back survives a hop between lessons through the remembered profile screen', () => {
    const stored = `${PROFILE}?screen=ktp&ktp_g=4&ktp_m=2026-10`;

    assert.deepEqual(
        backAction({ referrer: 'https://example.test/group/?gl=2', profileUrl: PROFILE, stored, historyLength: 4 }),
        { action: 'go', url: stored },
    );
});

test('back falls back to the button link when the profile was never opened', () => {
    assert.deepEqual(
        backAction({ referrer: '', profileUrl: PROFILE, stored: '', historyLength: 1 }),
        { action: 'link', url: '' },
    );
});

test('step numbers skip the virtual broadcast step', () => {
    const panels = [panel('live', '1'), panel('a'), panel('b'), panel('c')];

    assert.equal(stepNumberOf(panels, 0), 0);
    assert.deepEqual([1, 2, 3].map((i) => stepNumberOf(panels, i)), [1, 2, 3]);
});

test('?step= accepts a step number or a step key', () => {
    const panels = [panel('live', '1'), panel('a'), panel('b'), panel('c')];

    assert.equal(stepIndexFromParam(panels, '2'), 2);
    assert.equal(stepIndexFromParam(panels, 'c'), 3);
    assert.equal(stepIndexFromParam(panels, '9'), -1);
    assert.equal(stepIndexFromParam(panels, 'zzz'), -1);
    assert.equal(stepIndexFromParam(panels, ''), -1);
});
