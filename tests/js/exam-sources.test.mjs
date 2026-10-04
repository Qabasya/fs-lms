import assert from 'node:assert/strict';
import test from 'node:test';

import { REISSUE_TEXT, gradeOfDirection, sourceRowHtml } from '../../src/js/profile/exams/exam-sources.js';

const source = (over = {}) => ({ id: 7, school_name: 'Лицей №2', teacher_name: 'Иванова И. И.', grade: 11, is_active: true, has_link: true, active_holds: 0, ...over });

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

test('«Скопировать» — только пока ключ этой ссылки в памяти формы; «Перевыпустить» и «Отозвать» — всегда, когда ссылка есть', () => {
	const withKey = sourceRowHtml(source(), true);
	assert.match(withKey, /data-src="copy"/);
	assert.match(withKey, /data-src="reissue"/);
	assert.match(withKey, /data-src="revoke"/);

	const noKey = sourceRowHtml(source(), false);
	assert.doesNotMatch(noKey, /data-src="copy"/, 'после закрытия формы открытого ключа нет — копировать нечего');
	assert.match(noKey, /data-src="reissue"/);
});

test('источник без ссылки предлагает «Создать ссылку», а не перевыпуск', () => {
	const html = sourceRowHtml(source({ has_link: false }), false);

	assert.match(html, /data-src="issue"/);
	assert.doesNotMatch(html, /data-src="reissue"|data-src="revoke"|data-src="copy"/);
});

test('школа и преподаватель экранируются, неактивный источник помечен', () => {
	const html = sourceRowHtml(source({ school_name: '<img src=x onerror=alert(1)>', teacher_name: 'А & Б', is_active: false }), false);

	assert.doesNotMatch(html, /<img src=x/);
	assert.match(html, /&lt;img/);
	assert.match(html, /А &amp; Б/);
	assert.match(html, /class="exam-source is-off"/);
});

test('число действующих броней показывается у преподавателя только когда оно больше нуля', () => {
	assert.doesNotMatch(sourceRowHtml(source({ active_holds: 0 }), false), /броней/);
	assert.match(sourceRowHtml(source({ active_holds: 3 }), false), /броней: 3/);
});

test('в строке нет ни ключа, ни хеша ссылки', () => {
	const html = sourceRowHtml(source({ key: 'secret', link_hash: 'abc' }), true);

	assert.doesNotMatch(html, /secret|abc/);
});
