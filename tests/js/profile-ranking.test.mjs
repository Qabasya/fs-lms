import assert from 'node:assert/strict';
import { test } from 'node:test';

/* Экран «Рейтинг»: селекты — разметка «Сводки по ученику» (sum-pickers, kp-кнопки),
   таблица — классы журнала (jgrid, cell-idx/cell-name, липкая шапка, hover). */

globalThis.window = { fsProfile: { groups: [] } };

const { rankingPickersHtml, rankingTableHtml } = await import('../../src/js/profile/ranking.js');

const subject = { key: 'inf_ege', name: 'Информатика ЕГЭ' };
const groups = [
    { id: 1, name: 'МЕГЕ-1', subject: 'Информатика ЕГЭ', subject_key: 'inf_ege' },
    { id: 2, name: 'МЕГЕ-2', subject: 'Информатика ЕГЭ', subject_key: 'inf_ege' },
];
const periods = [
    { from: '2026-09-01 00:00:00', until: '2026-12-01 00:00:00', label: 'Сентябрь — ноябрь 2026', current: true },
    { from: '2026-06-01 00:00:00', until: '2026-09-01 00:00:00', label: 'Июнь — август 2026', current: false },
];

test('селекты: разметка «Сводки» (sum-pickers, prof-ktp-pick), группа — чип-кнопка с «Название · предмет»', () => {
    const html = rankingPickersHtml(subject, groups, 1, periods, '');

    assert.match(html, /^<div class="sum-pickers">/);
    assert.match(html, /kp-label">Период<\/span>/);
    assert.match(html, /kp-label">Направление<\/span>/);
    assert.match(html, /kp-label">Группа<\/span>/);
    assert.match(html, /id="rankingPeriod"/);
    assert.match(html, /id="rankingSubject"/);
    assert.match(html, /id="rankingGroup"/);
    assert.match(html, /МЕГЕ-1 · Информатика ЕГЭ/);
});

test('селекты: текущий период без выбора, выбранный — по метке, без выбранной группы — «Все группы»', () => {
    assert.match(rankingPickersHtml(subject, groups, 0, periods, ''), /Текущий/);
    assert.match(rankingPickersHtml(subject, groups, 0, periods, '2026-06-01 00:00:00'), /Июнь — август 2026/);
    assert.doesNotMatch(rankingPickersHtml(subject, groups, 0, periods, ''), /МЕГЕ-1 · Информатика ЕГЭ/);
});

test('таблица: классы журнала; место и ученик — cell-idx/cell-name, группа и баллы — по центру', () => {
    const html = rankingTableHtml({
        period: 'Сентябрь — ноябрь',
        rows: [
            { person_id: 11, rank: 1, name: 'Белов Иван', groups: [{ id: 1, name: 'МЕГЕ-1' }, { id: 2, name: 'МЕГЕ-2' }], score: 90 },
            { person_id: 12, rank: 2, name: 'Андреев Иван', groups: [{ id: 1, name: 'МЕГЕ-1' }], score: 89.5 },
        ],
    });

    assert.match(html, /<table class="jgrid ranking-table">/);
    assert.match(html, /<th class="col-name"><div class="hd-name">Ученик<\/div><\/th>/);
    assert.match(html, /<th class="col-mid"><div class="hd-name">Группа<\/div><\/th>/);
    assert.match(html, /<th class="col-mid"><div class="hd-name">Баллы<\/div><\/th>/);
    assert.match(html, /<th class="col-rank"><div class="hd-name">Место в рейтинге<\/div><\/th>/);
    assert.match(html, /<td class="cell-name"><div class="cn-wrap"><span class="cn-name">/);
    assert.doesNotMatch(html, /cn-ava/, 'аватарки из таблицы убраны');
    assert.match(html, /cn-name">Белов Иван<\/span><\/div><\/td>/);
    assert.match(html, /<td class="cell-c">МЕГЕ-1, МЕГЕ-2<\/td>/);
    assert.match(html, /<td class="cell-c">89,5<\/td>/);
    assert.match(html, /<td class="cell-rank">2<\/td>/);
    assert.doesNotMatch(html, /<caption/, 'подпись периода над таблицей убрана — период виден в селекте');
});

test('имя ученика — кнопка-ссылка в «Сводку», как в журнале; без обработчика — просто текст', () => {
    globalThis.window.location = { href: 'https://example.test/profile' };

    const linked = rankingTableHtml({
        period: 'Сентябрь — ноябрь',
        rows: [{ person_id: 11, rank: 1, name: 'Белов Иван', groups: [{ id: 1, name: 'МЕГЕ-1' }], score: 90 }],
    }, true);

    assert.match(linked, /<button type="button" class="cn-wrap cn-link" data-summary-pid="11" data-summary-gid="1" data-href="[^"]*screen=summary[^"]*gid=1[^"]*pid=11/);
    assert.match(linked, /title="Открыть сводку по ученику"/);

    const plain = rankingTableHtml({
        period: 'Сентябрь — ноябрь',
        rows: [{ person_id: 11, rank: 1, name: 'Белов Иван', groups: [{ id: 1, name: 'МЕГЕ-1' }], score: 90 }],
    });
    assert.doesNotMatch(plain, /cn-link/);
});

test('таблица: пустой срез — строка-заглушка на все колонки', () => {
    const html = rankingTableHtml({ period: 'Сентябрь — ноябрь', rows: [] });

    assert.match(html, /cell-empty" colspan="4">Нет активных учеников/);
});

test('селекты: без выбора направления (showSubject=false) — только период и группа', () => {
    const html = rankingPickersHtml(subject, groups, 1, periods, '', false);

    assert.match(html, /kp-label">Период<\/span>/);
    assert.match(html, /kp-label">Группа<\/span>/);
    assert.doesNotMatch(html, /kp-label">Направление<\/span>/);
    assert.doesNotMatch(html, /id="rankingSubject"/);
});
