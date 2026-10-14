import { test } from 'node:test';
import assert from 'node:assert/strict';
import { computeMonths, initialCursor } from '../../src/js/profile/ktp/ktp-calendar-model.js';

const months = computeMonths({ start_date: '2026-09-01', end_date: '2027-05-31' });
const themes = [{ scheduled_at: '2026-09-03 10:00:00' }];

test('КТП открывается на текущем месяце, если он в периоде', () => {
    assert.equal(initialCursor(themes, months, new Date(2026, 9, 14)), 1);
});

test('вне периода — месяц самой ранней размещённой темы', () => {
    assert.equal(initialCursor(themes, months, new Date(2026, 7, 20)), 0);
    assert.equal(initialCursor([{ scheduled_at: '2026-11-05 10:00:00' }], months, new Date(2027, 8, 1)), 2);
});

test('без тем и вне периода — первый месяц', () => {
    assert.equal(initialCursor([], months, new Date(2030, 0, 1)), 0);
    assert.equal(initialCursor([], [], new Date()), 0);
});
