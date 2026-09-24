/* ══════════════════════════════════════════════════════════════════════
   Журнал группы — реальные данные через AJAX (Эпик 2 + Эпик 10 T10.5).
   Ячейка (ученик×занятие) = посещаемость (+/Н) + результаты работ ЭТОГО занятия
   по типам (СР/ПР/ДЗ/КР/ЭКЗ, `GradeBadge`), с фильтрами. Отдельных столбцов-работ нет.
   D11: занятия с датой > сегодня недоступны для отметки. Создание индивидуальных
   занятий перенесено в экран «Группы» (T10.7).
   Результаты работ в ячейке не печатаются — ячейка несёт только отметку и точку
   «есть результаты», сами результаты — во всплывашке при наведении/фокусе.
   Клавиатура: стрелки — по ячейкам, пробел — был, Н — отсутствовал (после
   отметки фокус уходит на следующего ученика того же занятия), Del — снять отметку.
   ══════════════════════════════════════════════════════════════════════ */

import { esc, toast, initials, avaColor, todayIso, emptyState, openCtxMenuRaw, closeCtxMenu, openGradePopPositioned, closeGradePop } from './utils.js';
import { icoCheck, icoCross, icoJournal } from '../common/icons.js';
import { createApi } from './api.js';
import { MONTHS_RU } from './constants.js';
import { groupPickerBtnHtml, openGroupPicker } from './picker.js';

let root = null;
let state = null;
let api = null;

export function renderJournal(r) {
    root = r;
    const p = window.fsProfile || {};
    state = {
        groups:  Array.isArray(p.groups) ? p.groups : [],
        cfg:     p.journal || null,
        groupId: (p.groups && p.groups[0]) ? p.groups[0].id : null,
        data:    null,
        filters: null,
        months:  [],   // T11.5: список месяцев 'YYYY-MM', отсортированный
        monthIdx: 0,   // T11.5: индекс активного месяца в months
    };
    api = createApi(state.cfg);
    if (!state.groups.length || !state.cfg) { root.innerHTML = emptyHtml('Нет групп', 'За вами не закреплены группы.'); return; }
    load();
}

/** Вызывается из сайдбара (app.js) при выборе группы. */
export function setJournalGroup(gid) {
    if (!state) return;
    state.groupId = gid;
    load();
}

async function load() {
    if (!state.groupId) { root.innerHTML = emptyHtml('Нет группы', ''); return; }
    try {
        state.data = await api('getJournal', { group_id: state.groupId });
    } catch (e) {
        root.innerHTML = emptyHtml('Не удалось загрузить журнал', e.message);
        return;
    }
    // По умолчанию показываем все присутствующие типы работ.
    state.filters = new Set(state.data.types || []);
    computeMonths();
    render();
}

/* ── Помесячная пагинация (T11.5) ─────────────────────────────────────── */
/** Собирает отсортированный список месяцев из занятий и выбирает активный. */
function computeMonths() {
    // Эпик 15: открытая группа — занятия без дат, помесячная пагинация не применима.
    if (state.data.open) {
        state.months = [];
        state.monthIdx = 0;
        return;
    }
    const set = new Set((state.data.lessons || []).map(l => l.date.slice(0, 7)));
    state.months = [...set].sort();
    // По умолчанию — текущий месяц, иначе последний прошедший, иначе первый.
    const cur = todayIso().slice(0, 7);
    let idx = state.months.indexOf(cur);
    if (idx < 0) {
        idx = 0;
        for (let i = 0; i < state.months.length; i++) {
            if (state.months[i] <= cur) idx = i;
        }
    }
    state.monthIdx = state.months.length ? Math.max(0, idx) : 0;
}

/** Занятия активного месяца. */
function lessonsForMonth() {
    if (!state.data) return [];
    const m = state.months[state.monthIdx];
    if (!m) return state.data.lessons || [];
    return (state.data.lessons || []).filter(l => l.date.slice(0, 7) === m);
}

/** Человекочитаемая метка активного месяца, напр. «Июль 2026». */
function monthLabel() {
    const m = state.months[state.monthIdx];
    if (!m) return '';
    const [y, mm] = m.split('-');
    return `${MONTHS_RU[+mm - 1]} ${y}`;
}

function changeMonth(delta) {
    const next = state.monthIdx + delta;
    if (next < 0 || next >= state.months.length) return;
    state.monthIdx = next;
    render();
}

function group() { return state.groups.find(g => g.id === state.groupId) || state.groups[0]; }

/* T12.8: дропдаун выбора группы в шапке — общий пикер (picker.js). */
function openGroupMenu() {
    openGroupPicker(document.getElementById('jGroupBtn'), state.groups, state.groupId, setJournalGroup);
}

/* ── Render ───────────────────────────────────────────────────────────── */
function render() {
    const d = state.data;
    const g = group();

    if (!d.students.length) {
        root.innerHTML = wrap(g, emptyInline('В группе нет активных учеников.'));
        bindChrome();
        return;
    }

    const lessons = lessonsForMonth();
    if (!lessons.length) {
        root.innerHTML = wrap(g, emptyInline(state.data.open ? 'В программе нет занятий.' : 'В этом месяце нет занятий.'));
        bindChrome();
        return;
    }

    const head = `
        <thead>
            <tr>
                <th class="col-idx"><div class="hd-idx">#</div></th>
                <th class="col-name"><div class="hd-name">Ученик</div></th>
                ${lessons.map((l, i) => lessonHead(l, i)).join('')}
            </tr>
        </thead>`;

    const body = `<tbody>${d.students.map((s, i) => `
        <tr data-pid="${s.person_id}">
            <td class="col-idx cell-idx">${i + 1}</td>
            <td class="col-name cell-name">
                <div class="cn-wrap">
                    <span class="cn-ava" style="background:${avaColor(d.students, s.person_id)}">${initials(s.name)}</span>
                    <span class="cn-name">${esc(s.name)}</span>
                </div>
            </td>
            ${lessons.map((l, c) => attCell(s.person_id, l, i, c)).join('')}
        </tr>`).join('')}</tbody>`;

    root.innerHTML = wrap(g, `<div class="j-scroll" id="jScroll"><table class="jgrid">${head}${body}</table></div>`);
    const grid = root.querySelector('.jgrid');
    grid.addEventListener('click', onGridClick);
    grid.addEventListener('keydown', onGridKey);
    grid.addEventListener('mouseover', onCellHover);
    grid.addEventListener('mouseleave', hideWorksTip);
    grid.addEventListener('focusin', onCellHover);
    grid.addEventListener('focusout', hideWorksTip);
    root.querySelector('#jScroll').addEventListener('scroll', hideWorksTip, { passive: true });
    // Вход с клавиатуры: Tab попадает в первую ячейку, дальше — стрелки.
    const first = grid.querySelector('td.gc[data-r="0"][data-c="0"]');
    if (first) first.tabIndex = 0;
    bindChrome();
}

/** Навешивает обработчики на общие элементы шапки (группа + пагинация месяцев + фильтры). */
function bindChrome() {
    const gBtn = root.querySelector('#jGroupBtn');
    if (gBtn) gBtn.addEventListener('click', openGroupMenu);
    root.querySelectorAll('.jm-arrow[data-mnav]').forEach(btn =>
        btn.addEventListener('click', () => changeMonth(+btn.dataset.mnav)));
    root.querySelectorAll('.j-filters input[data-type]').forEach(cb =>
        cb.addEventListener('change', () => {
            if (cb.checked) state.filters.add(cb.dataset.type); else state.filters.delete(cb.dataset.type);
            render();
        }));
}

function wrap(g, inner) {
    const d = state.data || { students: [], lessons: [], types: [] };
    const monthLessons = lessonsForMonth();
    const hasNav = state.months.length > 0;
    const atFirst = state.monthIdx <= 0;
    const atLast = state.monthIdx >= state.months.length - 1;
    return `
    <div class="prof-journal">
        <div class="j-monthnav">
            ${groupPickerBtnHtml(g, 'jGroupBtn')}
            ${hasNav ? `
            <button type="button" class="jm-arrow" data-mnav="-1" ${atFirst ? 'disabled' : ''} aria-label="Предыдущий месяц">‹</button>
            <div class="jm-label">${esc(monthLabel())}</div>
            <button type="button" class="jm-arrow" data-mnav="1" ${atLast ? 'disabled' : ''} aria-label="Следующий месяц">›</button>` : ''}
            <span class="jm-count">${d.students.length} уч. · ${monthLessons.length} занятий</span>
            ${filterBar()}
        </div>
        <div class="prof-journal-wrap">${inner}</div>
        <div class="j-legend-bottom">
            ${d.open ? '<span class="jlb-label">Открытая группа — «+» проставляется автоматически, когда ученик проходит все шаги урока.</span>' : `
            <span class="jlb-label">Посещаемость:</span>
            <span class="jl"><span class="jl-sw jl-sw--present"></span>Присутствовал</span>
            <span class="jl"><span class="jl-sw jl-sw--absent"></span>Отсутствовал</span>`}
            <span class="jlb-label">Работы:</span>
            <span class="jl"><span class="cw-dot"></span>есть результаты — наведите на ячейку</span>
            <span class="jl"><span class="cw-dot cw-dot--warn"></span>на проверке или просрочено</span>
            ${d.open ? '' : `
            <span class="jlb-label">Клавиши:</span>
            <span class="jl"><kbd>←↑↓→</kbd> перемещение · <kbd>пробел</kbd> был · <kbd>Н</kbd> отсутствовал · <kbd>Del</kbd> снять</span>`}
        </div>
        <div class="j-works-tip" id="jWorksTip" hidden></div>
    </div>`;
}

function filterBar() {
    const types = (state.data && state.data.types) || [];
    if (!types.length || !state.filters) return '';
    return `<div class="j-filters">
        <span class="jf-label">Показывать:</span>
        ${types.map(t => `<label class="jf-chip ${state.filters.has(t) ? 'on' : ''}"><input type="checkbox" data-type="${esc(t)}" ${state.filters.has(t) ? 'checked' : ''}>${esc(t)}</label>`).join('')}
    </div>`;
}

function lessonHead(l, i) {
    // Эпик 15: открытая группа — занятия без дат, в шапке порядковый номер урока.
    if (state.data.open || !l.date) {
        return `<th class="hd-col" data-glid="${l.group_lesson_id}" title="${esc(l.topic)}">
            <div class="hd-date">№${i + 1}</div>
        </th>`;
    }
    const [, m, dd] = l.date.split('-');
    const future = isFutureDate(l.date);
    // НБ-3: в шапке столбца — только дата; день недели и кабинет убраны из
    // видимой шапки (кабинет остаётся во всплывающей подсказке title).
    const roomTip = l.room ? ` · ауд. ${l.room}` : '';
    // T12.6 (D14): продолжение темы — второй столбец той же темы, помечается «(прод.)».
    const contTag = l.is_continuation ? ' (прод.)' : '';
    return `<th class="hd-col${future ? ' future' : ''}" data-glid="${l.group_lesson_id}" title="${esc(l.topic)}${esc(contTag)} · ${l.date}${roomTip}${future ? ' · ещё не прошло' : ''}">
        <div class="hd-date">${dd}.${m}</div>
        ${l.is_continuation ? '<div class="hd-cont">прод.</div>' : ''}
    </th>`;
}

function attState(glid, pid) {
    const row = state.data.attendance[glid];
    if (!row || !(pid in row)) return 'none';
    return row[pid] ? 'present' : 'absent';
}

/* D11: занятия с датой > сегодня недоступны для отметки посещаемости. */
function isFutureDate(date) { return !!date && date > todayIso(); }
function isFutureLesson(glid) {
    const l = state.data.lessons.find(x => x.group_lesson_id === glid);
    return !!(l && isFutureDate(l.date));
}

/** Результаты работ ячейки (ученик×занятие), отфильтрованные по активным типам. */
function worksFor(glid, pid) {
    const byLesson = state.data.cell_works && state.data.cell_works[glid];
    const all = (byLesson && byLesson[pid]) || [];
    return all.filter(w => state.filters.has(w.badge));
}

function attCell(pid, l, r, c) {
    const glid = l.group_lesson_id;
    const open = !!state.data.open;
    // Эпик 15 (продолжение): открытая группа — учитель посещаемость не отмечает
    // (класс att не ставится, клики-попапы не работают), но «+» показывается
    // read-only, когда ученик прошёл ВСЕ шаги урока (сервер: JournalService
    // синтезирует это из LessonProgressService::isLessonCompleted). Отсутствия
    // («Н») для открытой группы не существует — есть только «пройдено»/«ещё нет».
    const cls = open ? ['gc'] : ['gc', 'att'];
    const st = attState(glid, pid);
    let att = '';
    if (open) {
        if (st === 'present') { cls.push('present'); att = '<span class="g-val att-y">+</span>'; }
    } else {
        if (isFutureDate(l.date)) cls.push('future');
        if (st === 'present') { cls.push('present'); att = '<span class="g-val att-y">+</span>'; }
        else if (st === 'absent') { cls.push('absent'); att = '<span class="g-val att-n">Н</span>'; }
    }

    // Результаты работ — только маркер: сами баллы во всплывашке (worksTipHtml).
    const works = worksFor(glid, pid);
    const needsAttention = works.some(w => w.display === 'pending' || w.overdue);
    const dot = works.length ? `<span class="cw-dot${needsAttention ? ' cw-dot--warn' : ''}"></span>` : '';

    return `<td class="${cls.join(' ')}" data-glid="${glid}" data-pid="${pid}" data-r="${r}" data-c="${c}" tabindex="-1">${att}${dot}</td>`;
}

/* ── Всплывашка результатов работ ─────────────────────────────────────── */
function onCellHover(e) {
    const td = e.target.closest('td.gc[data-glid]');
    if (!td) { hideWorksTip(); return; }
    const works = worksFor(+td.dataset.glid, +td.dataset.pid);
    if (!works.length) { hideWorksTip(); return; }

    const tip = document.getElementById('jWorksTip');
    if (!tip || tip.dataset.cell === `${td.dataset.glid}:${td.dataset.pid}` && !tip.hidden) return;
    tip.dataset.cell = `${td.dataset.glid}:${td.dataset.pid}`;
    tip.innerHTML = worksTipHtml(+td.dataset.glid, +td.dataset.pid, works);
    tip.hidden = false;

    // Позиция — от ячейки (как у openGradePopPositioned): под ней, у нижнего края — над ней.
    const r = td.getBoundingClientRect();
    let left = r.left + r.width / 2 - tip.offsetWidth / 2;
    left = Math.max(10, Math.min(left, window.innerWidth - tip.offsetWidth - 10));
    let top = r.bottom + 6;
    if (top + tip.offsetHeight > window.innerHeight - 10) top = r.top - tip.offsetHeight - 6;
    tip.style.left = left + 'px';
    tip.style.top = top + 'px';
}

function hideWorksTip() {
    const tip = document.getElementById('jWorksTip');
    if (tip) { tip.hidden = true; tip.dataset.cell = ''; }
}

function worksTipHtml(glid, pid, works) {
    const student = state.data.students.find(s => s.person_id === pid);
    const lesson = state.data.lessons.find(l => l.group_lesson_id === glid);
    const date = lesson && lesson.date ? lesson.date.split('-').reverse().slice(0, 2).join('.') : '';
    const head = [student ? student.name : '', date].filter(Boolean).join(' · ');

    return `<div class="jwt-head">${esc(head)}</div>
        ${works.map(w => `<div class="jwt-row">
            <span class="jwt-badge">${esc(w.badge)}</span>
            <span class="jwt-name">${esc(w.title || '')}</span>
            <span class="jwt-val${w.display === 'pending' ? ' pending' : ''}">${esc(w.value)}</span>
            ${w.overdue ? '<span class="jwt-late">просрочено</span>' : ''}
        </div>`).join('')}`;
}

/* ── Interactions ─────────────────────────────────────────────────────── */
function onGridClick(e) {
    if (state.data.open) return; // открытая группа: посещаемость не ведётся
    const td = e.target.closest('td.gc.att');
    if (td) {
        if (isFutureLesson(+td.dataset.glid)) { toast('Занятие ещё не прошло', 'error'); return; }
        openAttPopover(+td.dataset.glid, +td.dataset.pid, td);
        return;
    }
    const th = e.target.closest('th.hd-col[data-glid]');
    if (th) {
        if (isFutureLesson(+th.dataset.glid)) { toast('Занятие ещё не прошло', 'error'); return; }
        openColumnMenu(+th.dataset.glid, th);
    }
}

function openAttPopover(glid, pid, td) {
    const pop = document.getElementById('profGradePop');
    if (!pop) return;
    hideWorksTip();
    const st = attState(glid, pid);
    const student = state.data.students.find(s => s.person_id === pid);
    const first = student ? student.name.split(' ').slice(-1)[0] : '';

    pop.innerHTML = `
        <div class="gp-title">${esc(first)}</div>
        <div class="gp-row">
            <button class="prof-btn prof-btn-sm ${st === 'present' ? 'prof-btn-primary' : ''}" data-att="1">Был</button>
            <button class="prof-btn prof-btn-sm ${st === 'absent' ? 'prof-btn-danger' : ''}" data-att="0">Н</button>
            ${st !== 'none' ? '<button class="prof-btn prof-btn-sm" data-att="" title="Снять отметку">—</button>' : ''}
        </div>`;

    pop.querySelectorAll('[data-att]').forEach(b => b.addEventListener('click', () => {
        closeGradePop();
        markAttendance(glid, pid, '' === b.dataset.att ? null : b.dataset.att === '1');
    }));

    openGradePopPositioned(pop, td);
}

/**
 * Отметка посещаемости: ячейка обновляется сразу, запрос — следом; сервер
 * отказал — отметка откатывается. Так клавиатурная отметка подряд не ждёт сеть.
 * `present === null` — снять отметку.
 */
async function markAttendance(glid, pid, present) {
    const row = state.data.attendance[glid] = state.data.attendance[glid] || {};
    const had = pid in row;
    const before = row[pid];
    if (null === present && !had) return;
    if (null === present) { delete row[pid]; } else { row[pid] = present; }
    refreshAttCell(glid, pid);
    try {
        await (null === present
            ? api('clearAttendance', { group_lesson_id: glid, student_person_id: pid })
            : api('saveAttendance', { group_lesson_id: glid, student_person_id: pid, is_present: present ? '1' : '0' }));
    } catch (err) {
        if (had) { row[pid] = before; } else { delete row[pid]; }
        refreshAttCell(glid, pid);
        toast(err.message, 'error');
    }
}

/* ── Клавиатура (как в мокапе журнала) ────────────────────────────────── */
const MOVES = { ArrowUp: [-1, 0], ArrowDown: [1, 0], ArrowLeft: [0, -1], ArrowRight: [0, 1] };

function onGridKey(e) {
    const td = e.target.closest('td.gc[data-r]');
    if (!td || e.ctrlKey || e.metaKey || e.altKey) return;
    const r = +td.dataset.r, c = +td.dataset.c;

    if (MOVES[e.key]) {
        e.preventDefault();
        focusCell(r + MOVES[e.key][0], c + MOVES[e.key][1]);
        return;
    }
    if (state.data.open) return; // открытая группа: посещаемость не ведётся

    const glid = +td.dataset.glid, pid = +td.dataset.pid;

    // Del/Backspace — снять отметку; фокус остаётся на ячейке.
    if ('Delete' === e.key || 'Backspace' === e.key) {
        e.preventDefault();
        if (isFutureLesson(glid)) return;
        markAttendance(glid, pid, null);
        return;
    }

    // Н — по физической клавише (KeyY), чтобы работало и в латинской раскладке.
    const present = ' ' === e.key ? true : ('KeyY' === e.code || 'н' === e.key.toLowerCase() ? false : null);
    if (null === present) return;
    e.preventDefault();

    if (isFutureLesson(glid)) { toast('Занятие ещё не прошло', 'error'); return; }
    markAttendance(glid, pid, present);
    if (!focusCell(r + 1, c)) focusCell(r, c);
}

/** Фокус на ячейку (строка, столбец текущего месяца); false — такой нет. */
function focusCell(r, c) {
    const td = root.querySelector(`td.gc[data-r="${r}"][data-c="${c}"]`);
    if (!td) return false;
    td.focus();
    td.scrollIntoView({ block: 'nearest', inline: 'nearest' });
    return true;
}

function openColumnMenu(glid, th) {
    const html = `
        <div class="ctx-item" data-bulk="1">
            ${icoCheck(16)}
            Все присутствуют
        </div>
        <div class="ctx-item danger" data-bulk="0">
            ${icoCross(16)}
            Все отсутствуют
        </div>`;
    openCtxMenuRaw(html, th);
    const menu = document.getElementById('profCtxMenu');
    if (!menu) return;
    menu.querySelectorAll('.ctx-item').forEach(it => it.addEventListener('click', async () => {
        const present = it.dataset.bulk === '1';
        closeCtxMenu();
        try {
            await api('bulkAttendance', { group_lesson_id: glid, is_present: present ? '1' : '0' });
            const row = state.data.attendance[glid] = state.data.attendance[glid] || {};
            state.data.students.forEach(s => { row[s.person_id] = present; });
            state.data.students.forEach(s => refreshAttCell(glid, s.person_id));
            toast(present ? 'Все отмечены присутствующими' : 'Все отмечены отсутствующими');
        } catch (err) { toast(err.message, 'error'); }
    }));
}

function refreshAttCell(glid, pid) {
    const td = root.querySelector(`td.gc.att[data-glid="${glid}"][data-pid="${pid}"]`);
    if (!td) return;
    const l = state.data.lessons.find(x => x.group_lesson_id === glid);
    if (!l) return;
    // Ячейка перерисовывается на месте — фокус (клавиатурная навигация) и
    // tabindex входа сохраняются.
    const focused = document.activeElement === td;
    const tabIndex = td.tabIndex;
    td.outerHTML = attCell(pid, l, +td.dataset.r, +td.dataset.c);
    const fresh = root.querySelector(`td.gc.att[data-glid="${glid}"][data-pid="${pid}"]`);
    if (!fresh) return;
    fresh.tabIndex = tabIndex;
    if (focused) fresh.focus();
}

/* ── Helpers ──────────────────────────────────────────────────────────── */
function emptyHtml(title, text) {
    return emptyState('prof-journal', icoJournal(34), title, text);
}
function emptyInline(text) {
    return `<div class="j-empty">${esc(text)}</div>`;
}
