import assert from 'node:assert/strict';
import { test } from 'node:test';

import { individualMonths, durationMinutes, endAfter, hasStarted, suggestLessonTime } from '../../src/js/profile/ktp/ktp-calendar-model.js';

const NOW = new Date(2026, 9, 19); // 19 октября 2026

test('без периода и занятий — от текущего месяца на год вперёд', () => {
	const months = individualMonths(null, [], NOW);
	assert.deepEqual(months[0], { y: 2026, m: 9 });
	assert.deepEqual(months[months.length - 1], { y: 2027, m: 9 });
	assert.equal(months.length, 13);
});

test('период и занятия вне него расширяют диапазон в обе стороны', () => {
	const months = individualMonths(
		{ start_date: '2026-09-01', end_date: '2026-12-31' },
		[{ scheduled_at: '2026-06-03 15:00:00' }, { scheduled_at: '2027-12-20 10:00:00' }, { scheduled_at: null }],
		NOW,
	);
	assert.deepEqual(months[0], { y: 2026, m: 5 });
	assert.deepEqual(months[months.length - 1], { y: 2027, m: 11 });
});

test('переход через границу года не пропускает месяцы', () => {
	const months = individualMonths(null, [], new Date(2026, 10, 1));
	const keys = months.map(mm => `${mm.y}-${mm.m}`);
	assert.ok(keys.includes('2026-11') && keys.includes('2027-0'));
	assert.equal(new Set(keys).size, keys.length);
});

test('длительность занятия — по концу, без конца 60 минут', () => {
	assert.equal(durationMinutes({ scheduled_at: '2026-09-10 10:00:00', ends_at: '2026-09-10 11:30:00' }), 90);
	assert.equal(durationMinutes({ scheduled_at: '2026-09-10 10:00:00', ends_at: null }), 60);
	assert.equal(durationMinutes({ scheduled_at: '2026-09-10 10:00:00', ends_at: '2026-09-10 09:00:00' }), 60);
});

test('конец занятия при переносе: тот же интервал на новом дне, через полночь — следующий день', () => {
	assert.equal(endAfter('2026-09-12', '12:00', 90), '2026-09-12 13:30:00');
	assert.equal(endAfter('2026-09-12', '23:30', 60), '2026-09-13 00:30:00');
});

test('начавшимся считается проведённое и уже наступившее занятие', () => {
	const now = new Date(2026, 8, 10, 10, 30);
	assert.equal(hasStarted({ scheduled_at: '2026-09-10 10:00:00', status: 'scheduled' }, now), true);
	assert.equal(hasStarted({ scheduled_at: '2026-09-10 11:00:00', status: 'scheduled' }, now), false);
	assert.equal(hasStarted({ scheduled_at: '2026-09-10 11:00:00', status: 'held' }, now), true);
	assert.equal(hasStarted({ scheduled_at: null, status: 'scheduled' }, now), false);
});

test('время нового занятия берётся у прошлого занятия в тот же день недели', () => {
	const themes = [
		{ scheduled_at: '2026-10-05 16:00:00', ends_at: '2026-10-05 17:30:00' }, // понедельник
		{ scheduled_at: '2026-10-07 11:00:00', ends_at: null },                  // среда, позднее
	];
	assert.deepEqual(suggestLessonTime(themes, '2026-10-12'), { start: '16:00', end: '17:30' }); // следующий понедельник
});

test('нет занятия в тот же день недели — берётся последнее до этой даты, затем последнее вообще', () => {
	const themes = [
		{ scheduled_at: '2026-10-05 16:00:00', ends_at: null },
		{ scheduled_at: '2026-10-07 11:00:00', ends_at: '2026-10-07 12:00:00' },
	];
	assert.deepEqual(suggestLessonTime(themes, '2026-10-09'), { start: '11:00', end: '12:00' }); // пятница
	assert.deepEqual(suggestLessonTime(themes, '2026-10-01'), { start: '11:00', end: '12:00' }); // раньше всех
	assert.equal(suggestLessonTime([], '2026-10-09'), null);
	assert.equal(suggestLessonTime([{ scheduled_at: null }], '2026-10-09'), null);
});

test('в тот же день недели берётся ближайшее раньше этой даты, а не самое раннее', () => {
	const themes = [
		{ scheduled_at: '2026-09-07 09:00:00', ends_at: null },
		{ scheduled_at: '2026-10-05 16:00:00', ends_at: null },
	];
	assert.equal(suggestLessonTime(themes, '2026-10-12').start, '16:00');
});
