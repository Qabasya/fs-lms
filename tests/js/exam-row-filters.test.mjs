import assert from 'node:assert/strict';
import { test } from 'node:test';

import { rowMatches, groupSelectHtml, schoolSelectHtml, pruneView, SELF_REGISTERED, UNREGISTERED } from '../../src/js/profile/exams/exam-row-filters.js';

const rows = [
	{ audience: 'student', name: 'Иванов Пётр', group: '10А', school: 'Лицей 5' },
	{ audience: 'student', name: 'Петрова Ёлка', group: '11Б', school: '' },
	{ audience: 'guest', name: 'Сидоров Иван', group: '', school: 'Школа 7' },
	{ audience: 'guest', name: 'Кузнецов Олег', group: '', school: '' },
];
const view = (over = {}) => ({ audience: 'all', search: '', group: '', school: '', ...over });
const names = v => rows.filter(r => rowMatches(r, v)).map(r => r.name);

test('кого показывать: все, ученики, гости', () => {
	assert.equal(names(view()).length, 4);
	assert.deepEqual(names(view({ audience: 'student' })), ['Иванов Пётр', 'Петрова Ёлка']);
	assert.deepEqual(names(view({ audience: 'guest' })), ['Сидоров Иван', 'Кузнецов Олег']);
});

test('поиск по ФИО без учёта регистра и «ё»', () => {
	assert.deepEqual(names(view({ search: 'елка' })), ['Петрова Ёлка']);
	assert.deepEqual(names(view({ search: ' ИВАН' })), ['Иванов Пётр', 'Сидоров Иван']);
});

test('группа оставляет только учеников группы, школа — только гостей школы', () => {
	assert.deepEqual(names(view({ group: '10А' })), ['Иванов Пётр']);
	assert.deepEqual(names(view({ school: 'Школа 7' })), ['Сидоров Иван']);
	assert.deepEqual(names(view({ school: SELF_REGISTERED })), ['Кузнецов Олег']);
});

test('группы не показываются для гостей, школы — для учеников; варианты из строк', () => {
	assert.equal(groupSelectHtml(rows, 'guest', ''), '');
	assert.equal(schoolSelectHtml(rows, 'student', ''), '');
	assert.match(groupSelectHtml(rows, 'all', ''), /10А[\s\S]*11Б/);
	assert.match(schoolSelectHtml(rows, 'all', ''), /Самозапись[\s\S]*Школа 7/);
	assert.equal(groupSelectHtml([{ audience: 'guest', group: '' }], 'all', ''), '');
});

test('смена предмета: аудитория и поиск остаются, группа и школа — только если есть в новом списке', () => {
	const kept = pruneView(view({ audience: 'student', search: 'пет', group: '10А' }), rows);
	assert.deepEqual(kept, view({ audience: 'student', search: 'пет', group: '10А' }));

	const dropped = pruneView(view({ audience: 'student', search: 'пет', group: '9В', school: 'Школа 99' }), rows);
	assert.deepEqual(dropped, view({ audience: 'student', search: 'пет' }));
});

test('смена предмета: служебные значения фильтров не сбрасываются', () => {
	assert.equal(pruneView(view({ group: UNREGISTERED }), rows).group, UNREGISTERED);
	assert.equal(pruneView(view({ school: SELF_REGISTERED }), rows).school, SELF_REGISTERED);
	assert.equal(pruneView(view({ school: SELF_REGISTERED }), rows.filter(r => r.school)).school, '', 'самозаписанных гостей в списке нет');
});
