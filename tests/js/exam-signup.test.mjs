import assert from 'node:assert/strict';
import test from 'node:test';

import { buildSummary, validateForm, isSessionChosen, formatLeft, secondsLeft, phoneDigits, formatDateLong } from '../../src/js/frontend/services/exam-signup-model.js';

const valid = { last_name: 'Иванов', first_name: 'Пётр', middle_name: '', phone: '+7 (900) 111-22-33', messenger: '@ivan', session_id: '7', consents: ['pd_processing'] };

test('корректная форма не даёт ошибок; отчество и мессенджер необязательны', () => {
    assert.deepEqual(validateForm(valid), {});
    assert.deepEqual(validateForm({ ...valid, messenger: '', middle_name: '' }), {});
});

test('каждое правило даёт ошибку у своего поля', () => {
    assert.ok(validateForm({ ...valid, last_name: ' ' }).last_name);
    assert.ok(validateForm({ ...valid, first_name: 'а'.repeat(101) }).first_name);
    assert.ok(validateForm({ ...valid, phone: '+7 900' }).phone);
    assert.ok(validateForm({ ...valid, messenger: 'x'.repeat(101) }).messenger);
    assert.ok(validateForm({ ...valid, session_id: '' }).session_id);
    assert.ok(validateForm({ ...valid, consents: [] }).consent_pd_processing);
});

test('сеанс не выбран автоматически', () => {
    assert.equal(isSessionChosen(''), false);
    assert.equal(isSessionChosen('0'), false);
    assert.equal(isSessionChosen('7'), true);
});

test('телефон: формат не важен, 8 приводится к 7', () => {
    assert.equal(phoneDigits('8 900 111-22-33'), '79001112233');
    assert.equal(phoneDigits('+7 (900) 111-22-33'), '79001112233');
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
