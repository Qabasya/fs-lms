import assert from 'node:assert/strict';
import test from 'node:test';

import { REISSUE_TEXT, gradeOfDirection, sourceRowHtml, sessionsLabel } from '../../src/js/profile/exams/exam-sources.js';

const source = (over = {}) => ({ id: 7, school_name: 'Лицей №2', teacher_name: 'Иванова И. И.', grade: 11, is_active: true, has_link: true, url: 'https://x.test/?k=abc', active_holds: 0, registered: 0, session_ids: [], ...over });
const sessions = [{ id: 1, status: 'open' }, { id: 2, status: 'open' }, { id: 3, status: 'cancelled' }];

test('класс определяется направлением варианта: ЕГЭ — 11, ОГЭ — 9, без варианта — нет', () => {
	assert.equal(gradeOfDirection('ege'), 11);
	assert.equal(gradeOfDirection('oge'), 9);
	assert.equal(gradeOfDirection(''), 0);
	assert.equal(gradeOfDirection(undefined), 0);
});

test('текст перевыпуска содержит все последствия из спецификации', () => {
	assert.match(REISSUE_TEXT, /Старая ссылка перестанет работать сразу/);
	assert.match(REISSUE_TEXT, /формы потеряют доступ/);
	assert.match(REISSUE_TEXT, /оплаченные записи и действующие брони сохраняются/);
	assert.match(REISSUE_TEXT, /новую ссылку нужно отправить школе заново/);
});

test('строка: «Скопировать», выбор сеансов и меню «⋯» есть; аккордеона, переключателя и «Отозвать» нет', () => {
	const html = sourceRowHtml(source(), sessions);

	assert.match(html, /data-src="copy"/);
	assert.match(html, /data-src="sessions"/);
	assert.match(html, /data-src="menu"/);
	assert.match(html, /data-src="edit-teacher"/);
	assert.doesNotMatch(html, /data-src="toggle"|data-src="revoke"|data-src="reissue"|accordion/);
});

test('«Скопировать» недоступна, пока у ссылки нет сохранённого адреса', () => {
	assert.doesNotMatch(sourceRowHtml(source(), sessions), /data-src="copy"[^>]*disabled/);
	assert.match(sourceRowHtml(source({ url: '' }), sessions), /data-src="copy"[^>]*disabled/);
});

test('«Скопировать» синяя; пока ссылка не создана (создаётся сама), кнопка неактивна, «Создать ссылку» нет', () => {
	assert.match(sourceRowHtml(source(), sessions), /class="prof-btn prof-btn-sm prof-btn-primary es-copy"/);

	const html = sourceRowHtml(source({ has_link: false }), sessions);

	assert.match(html, /data-src="copy"[^>]*disabled/);
	assert.doesNotMatch(html, /data-src="issue"|Создать ссылку/);
});

test('школа и преподаватель экранируются, отключённая ссылка помечена', () => {
	const html = sourceRowHtml(source({ school_name: '<img src=x onerror=alert(1)>', teacher_name: 'А & Б', is_active: false }), sessions);

	assert.doesNotMatch(html, /<img src=x/);
	assert.match(html, /&lt;img/);
	assert.match(html, /А &amp; Б/);
	assert.match(html, /class="exam-source is-off"/);
	assert.match(html, /отключена/);
});

test('число записанных и скидка показываются в строке', () => {
	assert.match(sourceRowHtml(source({ registered: 12 }), sessions), /Записано: <b>12<\/b>/);
	assert.match(sourceRowHtml(source({ discount_percent: 50 }), sessions), /скидка 50%/);
	assert.match(sourceRowHtml(source({ discount_percent: 100 }), sessions), /без оплаты/);
	assert.doesNotMatch(sourceRowHtml(source({ discount_percent: 0 }), sessions), /es-free/);
});

test('число действующих броней показывается только когда оно больше нуля', () => {
	assert.doesNotMatch(sourceRowHtml(source({ active_holds: 0 }), sessions), /броней/);
	assert.match(sourceRowHtml(source({ active_holds: 3 }), sessions), /броней: 3/);
});

test('подпись выбора сеансов: «Все сеансы» без ограничения, иначе число существующих отмеченных (отменённые не считаются)', () => {
	assert.equal(sessionsLabel(source({ session_ids: [] }), sessions), 'Все сеансы');
	assert.equal(sessionsLabel(source({ session_ids: [1, 2] }), sessions), 'Сеансы: 2');
	assert.equal(sessionsLabel(source({ session_ids: [3, 99] }), sessions), 'Все сеансы', 'отменённого и несуществующего сеанса в выборе нет');
});

test('в строке нет ни ключа, ни хеша ссылки', () => {
	const html = sourceRowHtml(source({ key: 'secret', link_hash: 'abc', url: '' }), sessions);

	assert.doesNotMatch(html, /secret|abc/);
});
