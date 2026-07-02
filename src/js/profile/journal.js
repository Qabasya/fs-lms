/* ══════════════════════════════════════════════════════════════════════
   Журнал группы — реальные данные через AJAX (Эпик 2 + Эпик 10 T10.5).
   Ячейка (ученик×занятие) = посещаемость (+/Н) + результаты работ ЭТОГО занятия
   по типам (СР/ПР/ДЗ/КР/ЭКЗ, `GradeBadge`), с фильтрами. Отдельных столбцов-работ нет.
   D11: занятия с датой > сегодня недоступны для отметки. Создание индивидуальных
   занятий перенесено в экран «Группы» (T10.7).
   ══════════════════════════════════════════════════════════════════════ */

import { esc, toast, openCtxMenu, openCtxMenuRaw, closeCtxMenu, openGradePopPositioned, closeGradePop } from './utils.js';
import { createApi } from './api.js';

const DOW_JS = ['Вс', 'Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб'];
const MONTHS_RU = ['Январь', 'Февраль', 'Март', 'Апрель', 'Май', 'Июнь', 'Июль', 'Август', 'Сентябрь', 'Октябрь', 'Ноябрь', 'Декабрь'];
const AVA_COLORS = ['#5c7cfa','#7048e8','#1c7ed6','#0ca678','#f08c00','#e8590c','#e64980','#9c36b5','#2f9e44','#4263eb'];
// T12.8: та же палитра/пикер группы, что в КТП (ktp.js) — для визуальной согласованности.
const GROUP_COLORS = ['#3b5bdb','#0ca678','#7048e8','#f08c00','#e8590c','#1c7ed6','#e64980','#2f9e44'];

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

/* T12.8: дропдаун выбора группы в шапке — тот же паттерн, что в КТП (ktp.js). */
function groupColor(id) {
    const idx = state.groups.findIndex(g => g.id === id);
    return GROUP_COLORS[(idx < 0 ? 0 : idx) % GROUP_COLORS.length];
}
function shortName(name) {
    return String(name).replace(/[«»]/g, '').replace(/\s+/g, ' ').trim().slice(0, 4);
}
function openGroupMenu() {
    const btn = document.getElementById('jGroupBtn');
    if (!btn) return;
    openCtxMenu(
        btn,
        state.groups.map(g => ({
            v: String(g.id),
            label: `${g.name} · ${g.subject}`,
            active: g.id === state.groupId,
            swatch: groupColor(g.id),
            chip: shortName(g.name),
        })),
        v => {
            const id = parseInt(v, 10);
            if (id !== state.groupId) setJournalGroup(id);
        }
    );
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
        root.innerHTML = wrap(g, emptyInline('В этом месяце нет занятий.'));
        bindChrome();
        return;
    }

    const head = `
        <thead>
            <tr>
                <th class="col-idx"><div class="hd-idx">#</div></th>
                <th class="col-name"><div class="hd-name">Ученик</div></th>
                ${lessons.map(lessonHead).join('')}
            </tr>
        </thead>`;

    const body = `<tbody>${d.students.map((s, i) => `
        <tr data-pid="${s.person_id}">
            <td class="col-idx cell-idx">${i + 1}</td>
            <td class="col-name cell-name">
                <div class="cn-wrap">
                    <span class="cn-ava" style="background:${AVA_COLORS[i % AVA_COLORS.length]}">${initials(s.name)}</span>
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
            <button type="button" class="kp-btn" id="jGroupBtn">
                <span class="kp-chip" style="background:${groupColor(g.id)}">${esc(shortName(g.name))}</span>
                <span class="kp-txt">${esc(g.name)} · ${esc(g.subject)}</span>
                <svg class="kp-caret" width="12" height="12" viewBox="0 0 12 12"><path d="M3 4.5 6 8l3-3.5z" fill="currentColor"/></svg>
            </button>
            ${hasNav ? `
            <button type="button" class="jm-arrow" data-mnav="-1" ${atFirst ? 'disabled' : ''} aria-label="Предыдущий месяц">‹</button>
            <div class="jm-label">${esc(monthLabel())}</div>
            <button type="button" class="jm-arrow" data-mnav="1" ${atLast ? 'disabled' : ''} aria-label="Следующий месяц">›</button>` : ''}
            <span class="jm-count">${d.students.length} уч. · ${monthLessons.length} занятий</span>
            ${filterBar()}
        </div>
        <div class="prof-journal-wrap var-a">${inner}</div>
        <div class="j-legend-bottom">
            <span class="jlb-label">Посещаемость:</span>
            <span class="jl"><span class="jl-sw" style="background:var(--g-good)"></span>Присутствовал</span>
            <span class="jl"><span class="jl-sw" style="background:var(--absent)"></span>Отсутствовал</span>
            <span class="jlb-label" style="margin-left:8px">Работы:</span>
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

function lessonHead(l) {
    const [, m, dd] = l.date.split('-');
    const dow = DOW_JS[new Date(l.date).getDay()];
    const future = isFutureDate(l.date);
    const roomTip = l.room ? ` · ауд. ${l.room}` : '';
    return `<th class="hd-col${future ? ' future' : ''}" data-glid="${l.group_lesson_id}" title="${esc(l.topic)} · ${l.date}${roomTip}${future ? ' · ещё не прошло' : ''}">
        <div class="hd-date">${dd}.${m}</div>
        <div class="hd-dow">${dow}</div>
        ${l.room ? `<div class="hd-room" title="ауд. ${esc(l.room)}">${esc(l.room)}</div>` : ''}
    </th>`;
}

function attState(glid, pid) {
    const row = state.data.attendance[glid];
    if (!row || !(pid in row)) return 'none';
    return row[pid] ? 'present' : 'absent';
}

/* D11: занятия с датой > сегодня недоступны для отметки посещаемости. */
function todayIso() { return new Date().toISOString().slice(0, 10); }
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
    const st = attState(glid, pid);
    const cls = ['gc', 'att'];
    if (isFutureDate(l.date)) cls.push('future');
    let att = '';
    if (st === 'present') { cls.push('present'); att = '<span class="g-val" style="color:var(--g-good);font-weight:700">+</span>'; }
    else if (st === 'absent') { cls.push('absent'); att = '<span class="g-val att-n">Н</span>'; }

    const works = worksFor(glid, pid);
    if (works.length) cls.push('has-works');
    const worksHtml = works.length
        ? `<div class="cell-works">${works.map(w =>
            `<span class="cw${w.display === 'pending' ? ' pending' : ''}${w.overdue ? ' overdue' : ''}"${w.overdue ? ' title="Сдано после дедлайна"' : ''}><b>${esc(w.badge)}</b>${w.display === 'pending' ? '' : ' ' + esc(w.value)}</span>`).join('')}</div>`
        : '';

    return `<td class="${cls.join(' ')}" data-glid="${glid}" data-pid="${pid}"><div class="cell-att">${att}</div>${worksHtml}</td>`;
}

/* ── Interactions ─────────────────────────────────────────────────────── */
function onGridClick(e) {
    const td = e.target.closest('td.gc.att');
    if (td) {
        if (isFutureLesson(+td.dataset.glid)) { toast('Занятие ещё не прошло'); return; }
        openAttPopover(+td.dataset.glid, +td.dataset.pid, td);
        return;
    }
    const th = e.target.closest('th.hd-col[data-glid]');
    if (th) {
        if (isFutureLesson(+th.dataset.glid)) { toast('Занятие ещё не прошло'); return; }
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
            <button class="prof-btn prof-btn-sm ${st === 'absent' ? 'prof-btn-primary' : ''}" data-att="0"
                ${st === 'absent' ? 'style="background:var(--absent);border-color:var(--absent);color:#fff"' : ''}>Н</button>
        </div>`;

    pop.querySelectorAll('[data-att]').forEach(b => b.addEventListener('click', async () => {
        const present = b.dataset.att === '1';
        closeGradePop();
        try {
            await api('saveAttendance', { group_lesson_id: glid, student_person_id: pid, is_present: present ? '1' : '0' });
            (state.data.attendance[glid] = state.data.attendance[glid] || {})[pid] = present;
            refreshAttCell(glid, pid);
        } catch (err) { toast(err.message); }
    }));

    openGradePopPositioned(pop, td);
}

function openColumnMenu(glid, th) {
    const html = `
        <div class="ctx-item" data-bulk="1">
            <svg width="16" height="16" viewBox="0 0 20 20" fill="none"><path d="M4 10.5 8 14l8-8.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
            Все присутствуют
        </div>
        <div class="ctx-item danger" data-bulk="0">
            <svg width="16" height="16" viewBox="0 0 20 20" fill="none"><path d="M6 6l8 8M14 6l-8 8" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
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
        } catch (err) { toast(err.message); }
    }));
}

function refreshAttCell(glid, pid) {
    const td = root.querySelector(`td.gc.att[data-glid="${glid}"][data-pid="${pid}"]`);
    if (!td) return;
    const l = state.data.lessons.find(x => x.group_lesson_id === glid);
    if (l) td.outerHTML = attCell(pid, l);
}

/* ── Helpers ──────────────────────────────────────────────────────────── */
function initials(name) {
    return name.split(' ').filter(Boolean).map(w => w[0]).join('').slice(0, 2).toUpperCase();
}

function emptyHtml(title, text) {
    return `<div class="prof-journal"><div class="prof-ktp-empty">
        <div class="ke-ico"><svg width="34" height="34" viewBox="0 0 24 24" fill="none"><rect x="3" y="3.5" width="18" height="17" rx="2" stroke="currentColor" stroke-width="1.6"/><path d="M3 8h18M9 8v12" stroke="currentColor" stroke-width="1.6"/></svg></div>
        <h3>${esc(title)}</h3><p>${esc(text || '')}</p>
    </div></div>`;
}
function emptyInline(text) {
    return `<div class="j-empty">${esc(text)}</div>`;
}
