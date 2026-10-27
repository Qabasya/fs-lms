import assert from 'node:assert/strict';
import test from 'node:test';

import { lastDeadline } from '../../src/js/profile/utils.js';

/* Плитка «Дедлайнов» ведёт к последней несданной работе: с самым поздним сроком и ссылкой в плеер. */
test('lastDeadline picks the latest due work that has a player link', () => {
    const list = [
        { due_at: '2026-10-28 10:00:00', player_url: '/a' },
        { due_at: '2026-11-03 10:00:00', player_url: '/c' },
        { due_at: '2026-11-09 10:00:00', player_url: '' },
        { due_at: '2026-10-30 10:00:00', player_url: '/b' },
    ];
    assert.equal(lastDeadline(list).player_url, '/c');
});

test('lastDeadline returns null without a reachable work', () => {
    assert.equal(lastDeadline([]), null);
    assert.equal(lastDeadline([{ due_at: '2026-10-28 10:00:00', player_url: '' }]), null);
});
