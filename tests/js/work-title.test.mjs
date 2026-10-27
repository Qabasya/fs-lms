import assert from 'node:assert/strict';
import test from 'node:test';

import { suggestWorkTitle } from '../../src/js/admin/modules/work-title.js';

/* Название новой работы из конструктора курса: «Тема. Тип работы». */
test('practice and homework take the current topic', () => {
    assert.equal(suggestWorkTitle('homework', 'Списки', 'Строки'), 'Списки. Домашняя работа');
    assert.equal(suggestWorkTitle('practice', 'Списки', 'Строки'), 'Списки. Практика');
});

test('survey and independent take the previous topic', () => {
    assert.equal(suggestWorkTitle('survey', 'Списки', 'Строки'), 'Строки. Опрос');
    assert.equal(suggestWorkTitle('independent', 'Списки', 'Строки'), 'Строки. Самостоятельная работа');
});

test('first lesson has no previous topic: falls back to the current one', () => {
    assert.equal(suggestWorkTitle('survey', 'Списки', ''), 'Списки. Опрос');
});

test('no topic at all gives just the work name; unknown type gives nothing', () => {
    assert.equal(suggestWorkTitle('homework', '', ''), 'Домашняя работа');
    assert.equal(suggestWorkTitle('', 'Списки', ''), '');
});
