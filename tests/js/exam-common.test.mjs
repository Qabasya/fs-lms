import assert from 'node:assert/strict';
import { test } from 'node:test';

import { endTime, toDateTimeLocal, sessionsByDate, inPeriod, validateEventForm } from '../../src/js/profile/exams/exam-common.js';

test('окончание сеанса — начало плюс длительность формата: 10:00 + 235 мин = 13:55', () => {
	assert.equal(endTime('10:00', 235), '13:55');
	assert.equal(endTime('9:05', 150), '11:35');
});

test('окончание не выходит за сутки и не выдаёт 25:10', () => {
	assert.equal(endTime('23:00', 130), '01:10');
});

test('некорректное время даёт пустую строку, а не «NaN:NaN»', () => {
	assert.equal(endTime('', 235), '');
	assert.equal(endTime('abc', 235), '');
	assert.equal(endTime('10:00', Number.NaN), '');
});

test('значение datetime-local: пробел → T, секунды отрезаются', () => {
	assert.equal(toDateTimeLocal('2026-03-02 09:00:00'), '2026-03-02T09:00');
	assert.equal(toDateTimeLocal(''), '');
	assert.equal(toDateTimeLocal(null), '');
});

test('сеансы группируются по дате и упорядочиваются по времени начала', () => {
	const byDate = sessionsByDate([
		{ id: 3, date: '2026-10-31', time_start: '15:00' },
		{ id: 1, date: '2026-10-30', time_start: '10:00' },
		{ id: 2, date: '2026-10-31', time_start: '10:00' },
	]);

	assert.deepEqual(Object.keys(byDate), ['2026-10-31', '2026-10-30']);
	assert.deepEqual(byDate['2026-10-31'].map((s) => s.id), [2, 3]);
	assert.deepEqual(sessionsByDate(null), {});
});

test('период проведения включает обе границы, выходные не исключаются', () => {
	const event = { period_from: '2026-10-30', period_to: '2026-11-08' };

	assert.equal(inPeriod('2026-10-30', event), true);
	assert.equal(inPeriod('2026-11-08', event), true);
	assert.equal(inPeriod('2026-11-01', event), true, '1 ноября 2026 — воскресенье, но в периоде');
	assert.equal(inPeriod('2026-10-29', event), false);
	assert.equal(inPeriod('2026-11-09', event), false);
	assert.equal(inPeriod('2026-11-01', null), false);
});

const valid = { title: 'Пробный', period_from: '2026-10-30', period_to: '2026-11-08', registration_opens_at: '2026-10-01T09:00', registration_closes_at: '2026-11-06T18:00' };

test('верная форма проведения — без ошибок', () => {
	assert.deepEqual(validateEventForm(valid), {});
});

test('форма проведения: пустое название, перевёрнутый период, открытие записи позже закрытия', () => {
	assert.equal(validateEventForm({ ...valid, title: '   ' }).title, 'Укажите название проведения.');
	assert.equal(validateEventForm({ ...valid, period_from: '2026-11-09' }).period_from, 'Дата начала позже даты окончания.');
	assert.equal(validateEventForm({ ...valid, registration_opens_at: '2026-11-07T00:00' }).registration_opens_at, 'Открытие записи позже её закрытия.');
});

test('форма проведения: период через границу месяцев и выходные допустим; даты записи необязательны', () => {
	assert.deepEqual(validateEventForm({ ...valid, registration_opens_at: '', registration_closes_at: '' }), {});
	assert.equal(validateEventForm({ ...valid, period_to: '' }).period_from, 'Укажите даты проведения.');
});
