/* ══════════════════════════════════════════════════════════════════════
   Журнал группы — реальные данные через AJAX (Эпик 2 + Эпик 10 T10.5).
   Ячейка (ученик×занятие) = посещаемость (+/Н) + результаты работ ЭТОГО занятия
   по типам (СР/ПР/ДЗ/КР/ЭКЗ, `GradeBadge`), с фильтрами. Отдельных столбцов-работ нет.
   D11: занятия с датой > сегодня недоступны для отметки. Создание индивидуальных
   занятий перенесено в экран «Группы» (T10.7).
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
            ${lessons.map(l => attCell(s.person_id, l)).join('')}
        </tr>`).join('')}</tbody>`;

    root.innerHTML = wrap(g, `<div class="j-scroll" id="jScroll"><table class="jgrid">${head}${body}</table></div>`);
    root.querySelector('.jgrid').addEventListener('click', onGridClick);
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
            <span class="jl">СР/ПР/ДЗ/КР/ЭКЗ — сырые баллы за занятие</span>
        </div>
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

function attCell(pid, l) {
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

    const works = worksFor(glid, pid);
    if (works.length) cls.push('has-works');
    const worksHtml = works.length
        ? `<div class="cell-works">${works.map(w =>
            `<span class="cw${w.display === 'pending' ? ' pending' : ''}${w.overdue ? ' overdue' : ''}"${w.overdue ? ' title="Сдано после дедлайна"' : ''}><b>${esc(w.badge)}</b>${w.display === 'pending' ? '' : ' ' + esc(w.value)}</span>`).join('')}</div>`
        : '';

    return `<td class="${cls.join(' ')}" data-glid="${glid}" data-pid="${pid}">${att ? `<div class="cell-att">${att}</div>` : ''}${worksHtml}</td>`;
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
    const st = attState(glid, pid);
    const student = state.data.students.find(s => s.person_id === pid);
    const first = student ? student.name.split(' ').slice(-1)[0] : '';

    pop.innerHTML = `
        <div class="gp-title">${esc(first)}</div>
        <div class="gp-row">
            <button class="prof-btn prof-btn-sm ${st === 'present' ? 'prof-btn-primary' : ''}" data-att="1">Был</button>
            <button class="prof-btn prof-btn-sm ${st === 'absent' ? 'prof-btn-danger' : ''}" data-att="0">Н</button>
        </div>`;

    pop.querySelectorAll('[data-att]').forEach(b => b.addEventListener('click', async () => {
        const present = b.dataset.att === '1';
        closeGradePop();
        try {
            await api('saveAttendance', { group_lesson_id: glid, student_person_id: pid, is_present: present ? '1' : '0' });
            (state.data.attendance[glid] = state.data.attendance[glid] || {})[pid] = present;
            refreshAttCell(glid, pid);
        } catch (err) { toast(err.message, 'error'); }
    }));

    openGradePopPositioned(pop, td);
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
    if (l) td.outerHTML = attCell(pid, l);
}

/* ── Helpers ──────────────────────────────────────────────────────────── */
function emptyHtml(title, text) {
    return emptyState('prof-journal', icoJournal(34), title, text);
}
function emptyInline(text) {
    return `<div class="j-empty">${esc(text)}</div>`;
}
