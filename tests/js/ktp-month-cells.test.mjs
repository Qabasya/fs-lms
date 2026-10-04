import assert from 'node:assert/strict';
import { test } from 'node:test';

import { monthCells } from '../../src/js/profile/ktp/ktp-calendar-model.js';

const empties = (cells) => cells.filter((c) => 'empty' === c.type).length;
const days = (cells) => cells.filter((c) => 'day' === c.type);

test('октябрь 2026: три ведущие пустые ячейки (1 октября — четверг) и 31 день', () => {
	const cells = monthCells(2026, 9);

	assert.equal(empties(cells), 3);
	assert.equal(days(cells).length, 31);
	assert.deepEqual(cells[3], { type: 'day', date: '2026-10-01', day: 1 });
	assert.deepEqual(cells.at(-1), { type: 'day', date: '2026-10-31', day: 31 });
});

test('пустые ячейки только в начале, дальше идут дни подряд', () => {
	const cells = monthCells(2026, 9);
	const firstDay = cells.findIndex((c) => 'day' === c.type);

	assert.ok(cells.slice(0, firstDay).every((c) => 'empty' === c.type));
	assert.ok(cells.slice(firstDay).every((c) => 'day' === c.type));
	assert.deepEqual(days(cells).map((c) => c.day), Array.from({ length: 31 }, (_, i) => i + 1));
});

test('февраль високосного года — 29 дней, невисокосного — 28', () => {
	assert.equal(days(monthCells(2028, 1)).length, 29);
	assert.equal(days(monthCells(2027, 1)).length, 28);
	assert.equal(days(monthCells(2100, 1)).length, 28, '2100 — не високосный');
});

test('месяц, начинающийся с понедельника, без пустых ячеек', () => {
	assert.equal(new Date(2026, 5, 1).getDay(), 1, 'предусловие: 1 июня 2026 — понедельник');
	assert.equal(empties(monthCells(2026, 5)), 0);
});

test('месяц, начинающийся с воскресенья, — шесть пустых ячеек', () => {
	assert.equal(new Date(2026, 1, 1).getDay(), 0, 'предусловие: 1 февраля 2026 — воскресенье');
	assert.equal(empties(monthCells(2026, 1)), 6);
});

test('дата ячейки — с ведущими нулями; переход декабрь → январь не ломает год', () => {
	assert.equal(monthCells(2026, 0).find((c) => 'day' === c.type).date, '2026-01-01');
	assert.equal(monthCells(2026, 11).at(-1).date, '2026-12-31');
	assert.equal(monthCells(2026, 2)[monthCells(2026, 2).findIndex((c) => 'day' === c.type) + 8].date, '2026-03-09');
});

test('сетка совпадает с прежним расчётом КТП во всех месяцах 2020–2035', () => {
	// Формула из ktp.js::renderCalendar до выделения monthCells: смещение первого дня и число дней.
	for (let year = 2020; year <= 2035; year++) {
		for (let month = 0; month < 12; month++) {
			const offset = (new Date(year, month, 1).getDay() + 6) % 7;
			const last = new Date(year, month + 1, 0).getDate();
			const expected = [];
			for (let i = 0; i < offset; i++) expected.push({ type: 'empty' });
			for (let d = 1; d <= last; d++) {
				expected.push({ type: 'day', date: `${year}-${String(month + 1).padStart(2, '0')}-${String(d).padStart(2, '0')}`, day: d });
			}

			assert.deepEqual(monthCells(year, month), expected, `${year}-${month + 1}`);
		}
	}
});
