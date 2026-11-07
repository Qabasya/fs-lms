import assert from 'node:assert/strict';
import { test } from 'node:test';

/* Экран «Рейтинг»: селекты — разметка «Сводки по ученику» (sum-pickers, kp-кнопки),
   таблица — классы журнала (jgrid, cell-idx/cell-name, липкая шапка, hover). */

globalThis.window = { fsProfile: { groups: [] } };

const { rankingPickersHtml, rankingTableHtml, scoreBreakdownHtml } = await import('../../src/js/profile/ranking.js');

test('практика показывает расчёты друг под другом в одной строке работы', () => {
    const practice = { source: 'practice', amount: 123, weight: 1, task_groups: [
        { points: 1, count: 100 }, { points: 2, count: 10 }, { points: 3, count: 1 },
    ] };
    const html = scoreBreakdownHtml({ score: 123, breakdown: [practice] });
    assert.match(html, /<li><span>Практика<\/span><span class="ranking-score-values">/);
    assert.equal((html.match(/<li>/g) || []).length, 1);
    assert.match(html, /<strong>100<\/strong><br>10 × 2/);
    assert.doesNotMatch(html, /Стоимость задания|решений:/);
    assert.match(html, /100 × 1 = <strong>100/);
    assert.match(html, /10 × 2 = <strong>20/);
    assert.match(html, /1 × 3 = <strong>3/);
    assert.doesNotMatch(html, /123 × 1/);
    assert.match(scoreBreakdownHtml({ score: 61.5, breakdown: [{ ...practice, weight: 0.5 }] }), /123 × 0,5 = <strong>61,5/);
    assert.doesNotMatch(scoreBreakdownHtml({ score: 0, breakdown: [{ ...practice, weight: 0 }] }), /Практика|123 × 0/);
});

test('нулевые категории и группы не показываются, коэффициент подписан явно', () => {
    const html = scoreBreakdownHtml({ score: 4, breakdown: [
        { source: 'practice', amount: 5, weight: 0, task_groups: [{ points: 2, count: 1 }, { points: 3, count: 1 }] },
        { source: 'independent', amount: 0, weight: 1 },
        { source: 'survey', amount: 1, weight: 1 },
        { source: 'assessment', amount: 3, weight: 1 },
        { source: 'attendance_bonus', amount: 0, weight: 0 },
    ] });
    assert.doesNotMatch(html, /Практика|Самостоятельная работа|Серии посещений/);
    assert.match(html, /Опрос/);
    assert.match(html, /Итого: <strong>4/);
    const weighted = scoreBreakdownHtml({ score: 1, breakdown: [
        { source: 'practice', amount: 2, weight: 0.5, task_groups: [{ points: 0, count: 5 }, { points: 2, count: 1 }] },
    ] });
    assert.doesNotMatch(weighted, /5 × 0/);
    assert.match(weighted, /С коэффициентом: 2 × 0,5 = <strong>1/);
});

test('расчёт баллов показывает типы, веса, бонус и итог; кнопка доступна только сотруднику', () => {
    const row = { person_id: 7, name: 'Ученик', score: 7, groups: [], breakdown: [
        { source: 'homework', amount: 3, weight: 2 },
        { source: 'attendance_bonus', amount: 1, weight: 1, streak_length: 3 },
    ] };
    const details = scoreBreakdownHtml(row);
    assert.match(details, /Домашняя работа/);
    assert.match(details, /3 × 2 = <strong>6/);
    assert.match(details, /длина серии: 3/);
    assert.match(details, /Итого: <strong>7/);
    assert.match(rankingTableHtml({ rows: [row] }), /data-score-pid="7"/);
    assert.doesNotMatch(rankingTableHtml({ rows: [row] }, false, true), /data-score-pid/);
});

const subject = { key: 'inf_ege', name: 'Информатика ЕГЭ' };
const groups = [
    { id: 1, name: 'МЕГЕ-1', subject: 'Информатика ЕГЭ', subject_key: 'inf_ege' },
    { id: 2, name: 'МЕГЕ-2', subject: 'Информатика ЕГЭ', subject_key: 'inf_ege' },
];
const periods = [
    { from: '2026-09-01 00:00:00', until: '2026-12-01 00:00:00', label: 'Сентябрь — ноябрь 2026', current: true },
    { from: '2026-06-01 00:00:00', until: '2026-09-01 00:00:00', label: 'Июнь — август 2026', current: false },
];

test('селекты: разметка «Сводки» (sum-pickers, prof-ktp-pick), группа — чип-кнопка без направления', () => {
    const html = rankingPickersHtml(subject, groups, 1, periods, '');

    assert.match(html, /^<div class="sum-pickers">/);
    assert.match(html, /kp-label">Период<\/span>/);
    assert.match(html, /kp-label">Направление<\/span>/);
    assert.match(html, /kp-label">Группа<\/span>/);
    assert.match(html, /id="rankingPeriod"/);
    assert.match(html, /id="rankingSubject"/);
    assert.match(html, /id="rankingGroup"/);
    assert.match(html, /<span class="kp-txt">МЕГЕ-1<\/span>/);
    assert.doesNotMatch(html, /МЕГЕ-1 · Информатика ЕГЭ/);
    assert.match(rankingPickersHtml(subject, groups, 1, periods, '', false, 1), /Моя группа/);
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
    assert.match(html, /<span class="ranking-rank-value">2<\/span><span class="ranking-trend-slot" aria-hidden="true"><\/span>/);
    assert.doesNotMatch(html, /<caption/, 'подпись периода над таблицей убрана — период виден в селекте');
});

test('динамика места: неизменный контейнер и доступные стрелки без даты снимка', () => {
    const row = { person_id: 1, rank: 2, name: 'Ученик', groups: [], score: 20 };
    const html = rankingTableHtml({ rows: [
        { ...row, trend: { direction: 'up', baseline_at: '2026-11-06 00:00:00' } },
        { ...row, trend: { direction: 'down', baseline_at: '2026-11-06 00:00:00' } },
        { ...row, trend: null },
        row,
        { ...row, trend: { direction: 'same', baseline_at: '2026-11-06 00:00:00' } },
    ] });
    assert.equal((html.match(/class="ranking-trend-slot"/g) || []).length, 5);
    assert.match(html, /ranking-trend--up" tabindex="0" role="img" aria-label="Место поднялось" title="Место поднялось"/);
    assert.match(html, /ranking-trend--down" tabindex="0" role="img" aria-label="Место опустилось" title="Место опустилось"/);
    assert.doesNotMatch(html, /2026-11-06|06\.11\.2026/);
    assert.equal((html.match(/aria-hidden="true"/g) || []).length, 3);
    const escaped = rankingTableHtml({ rows: [{ ...row, trend: { direction: 'up', baseline_at: '" onmouseover="bad' } }] });
    assert.doesNotMatch(escaped, /title="[^"]*" onmouseover=/);
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

test('в ученическом рейтинге отмечена только строка текущего ученика', () => {
    const data = { viewer_person_id: 12, rows: [
        { person_id: 11, name: 'Первый', groups: [], score: 4, rank: 1 },
        { person_id: 12, name: 'Я', groups: [], score: 3, rank: 2 },
    ] };
    const html = rankingTableHtml(data, false, true);
    assert.match(html, /ranking-table--learner/);
    assert.equal((html.match(/class="ranking-self"/g) || []).length, 1);
    assert.match(html, /<tr class="ranking-self">\s*<td class="cell-name">[^]*?Я/);
    assert.doesNotMatch(rankingTableHtml(data, true), /ranking-self|ranking-table--learner/);
    assert.doesNotMatch(rankingTableHtml({ ...data, viewer_person_id: 99 }, false, true), /ranking-self/);
});

test('селекты: без выбора направления (showSubject=false) — только период и группа', () => {
    const html = rankingPickersHtml(subject, groups, 1, periods, '', false);

    assert.match(html, /kp-label">Период<\/span>/);
    assert.match(html, /kp-label">Группа<\/span>/);
    assert.doesNotMatch(html, /kp-label">Направление<\/span>/);
    assert.doesNotMatch(html, /id="rankingSubject"/);
});
