import assert from 'node:assert/strict';
import test from 'node:test';
import { ordinaryGroups } from '../../src/js/profile/ordinary-groups.js';

test('ordinary planning selectors exclude service groups while keeping shared scheduled and legacy groups', () => {
    const groups = [
        { id: 1, is_shared: 1, access_mode: 'scheduled', self_paced_course_id: null },
        { id: 2, is_shared: 0, access_mode: 'open', self_paced_course_id: 100 },
        { id: 3, access_mode: 'open' },
    ];
    assert.deepEqual(ordinaryGroups(groups).map(g => g.id), [1, 3]);
    assert.equal(groups.length, 3, 'works and summary still receive the complete source list');
});
