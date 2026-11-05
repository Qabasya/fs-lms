import { test } from 'node:test';
import assert from 'node:assert/strict';
import { groupTypeFromStorage, groupTypeHasSchedule } from '../../src/js/admin/modals/enrollment/group-type.js';

test('group form distinguishes all six persisted combinations, preserving legacy flags', () => {
    assert.equal(groupTypeFromStorage('scheduled', false), 'scheduled');
    assert.equal(groupTypeFromStorage('scheduled', true), 'shared');
    assert.equal(groupTypeFromStorage('individual', false), 'individual');
    assert.equal(groupTypeFromStorage('open', false), 'open');
    assert.equal(groupTypeFromStorage('open', true), 'legacy_open_shared');
    assert.equal(groupTypeFromStorage('individual', true), 'legacy_individual_shared');
});

test('shared group keeps schedule controls, scheduleless and legacy types do not get a schedule', () => {
    for (const type of ['scheduled', 'shared']) assert.equal(groupTypeHasSchedule(type), true);
    for (const type of ['open', 'individual', 'legacy_open_shared', 'legacy_individual_shared']) assert.equal(groupTypeHasSchedule(type), false);
});
