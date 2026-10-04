import assert from 'node:assert/strict';
import test from 'node:test';

import { resultCaption, resultPercent, UNIT_STATUS } from '../../src/js/profile/exams/exam-result.js';
import { courseTabsShell } from '../../src/js/profile/course-tabs.js';
import { ajaxErrorText } from '../../src/js/common/utils.js';

test('итог ЕГЭ — вторичный балл из максимума, пока ручной части нет', () => {
    const r = { direction: 'ege', primary: 18, primary_max: 29, secondary: 72, secondary_max: 100, grade: null, final: true };

    assert.equal(resultCaption(r), '72 из 100');
});

test('неокончательный итог показывается первичным баллом, без вторичного и отметки', () => {
    const ege = { direction: 'ege', primary: 12, primary_max: 29, secondary: null, secondary_max: 100, grade: null, final: false };
    const oge = { direction: 'oge', primary: 12, primary_max: 21, secondary: null, secondary_max: null, grade: null, final: false };

    assert.equal(resultCaption(ege), '12 из 29');
    assert.equal(resultCaption(oge), '12 из 21');
});

test('итог ОГЭ — первичный балл и отметка', () => {
    const r = { direction: 'oge', primary: 16, primary_max: 21, secondary: null, secondary_max: null, grade: 4, final: true };

    assert.equal(resultCaption(r), '16 из 21, отметка 4');
});

test('нет итога — пустая подпись', () => {
    assert.equal(resultCaption(null), '');
    assert.equal(resultCaption(undefined), '');
});

test('доля первичного балла ограничена 0–100 и не делит на ноль', () => {
    assert.equal(resultPercent({ primary: 18, primary_max: 29 }), 62);
    assert.equal(resultPercent({ primary: 40, primary_max: 29 }), 100);
    assert.equal(resultPercent({ primary: -1, primary_max: 29 }), 0);
    assert.equal(resultPercent({ primary: 5, primary_max: 0 }), 0);
    assert.equal(resultPercent(null), 0);
});

test('у каждого серверного статуса единицы есть подпись и вариант пилюли', () => {
    for (const status of ['correct', 'partial', 'unanswered', 'incorrect', 'pending']) {
        assert.ok(UNIT_STATUS[status]?.label, status);
        assert.ok(UNIT_STATUS[status]?.pill, status);
    }
    assert.equal(UNIT_STATUS.unanswered.label, 'Не решено');
});

test('оболочка ленты берёт подписи стрелок параметрами, по умолчанию — «курсы»', () => {
    const exams = courseTabsShell('exSlots', { prevLabel: 'Предыдущие сеансы', nextLabel: 'Следующие сеансы' });
    const tabs = courseTabsShell('exTabs');

    assert.match(exams, /aria-label="Предыдущие сеансы"/);
    assert.match(exams, /aria-label="Следующие сеансы"/);
    assert.match(exams, /id="exSlots"/);
    assert.match(tabs, /aria-label="Предыдущие курсы"/);
});

test('текст ошибки AJAX берётся и из строки, и из объекта {message}', () => {
    assert.equal(ajaxErrorText({ data: 'Просто текст' }, 'запасной'), 'Просто текст');
    assert.equal(ajaxErrorText({ data: { message: 'Время начала истекло.', code: 'X-NOT-OPEN', ref: 'AB12CD' } }, 'запасной'), 'Время начала истекло.');
    assert.equal(ajaxErrorText({ data: {} }, 'запасной'), 'запасной');
    assert.equal(ajaxErrorText(null, 'запасной'), 'запасной');
});
