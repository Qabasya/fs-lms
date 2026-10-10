import assert from 'node:assert/strict';
import test from 'node:test';

import { buildSummary, isSessionChosen, formatLeft, secondsLeft, formatDateLong } from '../../src/js/frontend/services/exam-signup-model.js';

test('сеанс не выбран автоматически', () => {
    assert.equal(isSessionChosen(''), false);
    assert.equal(isSessionChosen('0'), false);
    assert.equal(isSessionChosen('7'), true);
});

test('резюме: ФИО как введено, дата с годом и днём недели', () => {
    const s = buildSummary({ last_name: ' Иванов', first_name: 'Пётр ', middle_name: '' }, { date: '2026-03-12', weekday: 'четверг', time: '10:00' });

    assert.deepEqual(s, { name: 'Иванов Пётр', date: '12 марта 2026, четверг', time: '10:00' });
    assert.deepEqual(buildSummary({}, null), { name: '—', date: '—', time: '—' });
    assert.equal(formatDateLong('мусор'), '—');
});

test('отсчёт брони идёт от серверного значения и не уходит в минус', () => {
    assert.equal(secondsLeft(1200, 5000), 1195);
    assert.equal(secondsLeft(10, 60000), 0);
    assert.equal(formatLeft(1195), '19:55');
    assert.equal(formatLeft(-3), '00:00');
});
