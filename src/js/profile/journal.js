/* ══════════════════════════════════════════════════════════════════════
   Журнал группы — реальные данные через AJAX (Эпик 2).
   Источник: window.fsProfile.{groups, journal:{nonce,actions}, ajax.url}.
   Модель D4: ячейки занятий — посещаемость (+/−), столбцы работ — СЫРЫЕ баллы.
   НЕТ 5-балльных оценок и среднего балла.
   ══════════════════════════════════════════════════════════════════════ */

import { esc, toast, openCtxMenuRaw, closeCtxMenu, openGradePopPositioned, closeGradePop } from './utils.js';

const DOW_JS = ['Вс', 'Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб'];
const AVA_COLORS = ['#5c7cfa','#7048e8','#1c7ed6','#0ca678','#f08c00','#e8590c','#e64980','#9c36b5','#2f9e44','#4263eb'];

let root = null;
let state = null;

export function renderJournal(r) {
    root = r;
    const p = window.fsProfile || {};
    state = {
        groups:  Array.isArray(p.groups) ? p.groups : [],
        cfg:     p.journal || null,
        ajaxUrl: p.ajax?.url || (typeof window.ajaxurl === 'string' ? window.ajaxurl : '/wp-admin/admin-ajax.php'),
        groupId: (p.groups && p.groups[0]) ? p.groups[0].id : null,
        data:    null,
    };
    if (!state.groups.length || !state.cfg) { root.innerHTML = emptyHtml('Нет групп', 'За вами не закреплены группы.'); return; }
    load();
}

/** Вызывается из сайдбара (app.js) при выборе группы. */
export function setJournalGroup(gid) {
    if (!state) return;
    state.groupId = gid;
    load();
}

async function api(actionKey, params) {
    const action = state.cfg.actions[actionKey];
    const body = new URLSearchParams(Object.assign({ action, security: state.cfg.nonce }, params || {}));
    const res = await fetch(state.ajaxUrl, {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body,
    });
    const json = await res.json().catch(() => ({ success: false }));
    if (!json || !json.success) throw new Error(json?.data?.message || 'Ошибка запроса');
    return json.data;
}

async function load() {
    if (!state.groupId) { root.innerHTML = emptyHtml('Нет группы', ''); return; }
    try {
        state.data = await api('getJournal', { group_id: state.groupId });
    } catch (e) {
        root.innerHTML = emptyHtml('Не удалось загрузить журнал', e.message);
        return;
    }
    render();
}

function group() { return state.groups.find(g => g.id === state.groupId) || state.groups[0]; }

/* ── Render ───────────────────────────────────────────────────────────── */
function render() {
    const d = state.data;
    const g = group();

    if (!d.students.length) {
        root.innerHTML = wrap(g, emptyInline('В группе нет активных учеников.'));
        return;
    }

    const head = `
        <thead>
            <tr>
                <th class="col-idx"><div class="hd-idx">#</div></th>
                <th class="col-name"><div class="hd-name">Ученик</div></th>
                ${d.lessons.map(lessonHead).join('')}
                ${d.works.map(workHead).join('')}
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
            ${d.lessons.map(l => attCell(s.person_id, l)).join('')}
            ${d.works.map(w => workCell(s.person_id, w)).join('')}
        </tr>`).join('')}</tbody>`;

    root.innerHTML = wrap(g, `<div class="j-scroll" id="jScroll"><table class="jgrid">${head}${body}</table></div>`);
    root.querySelector('.jgrid').addEventListener('click', onGridClick);
}

function wrap(g, inner) {
    const d = state.data || { students: [], lessons: [] };
    return `
    <div class="prof-journal">
        <div class="j-monthnav">
            <div class="jm-label">${esc(g.name)} · ${esc(g.subject)}</div>
            <span class="jm-count">${d.students.length} уч. · ${d.lessons.length} занятий</span>
        </div>
        <div class="prof-journal-wrap var-a">${inner}</div>
        <div class="j-legend-bottom">
            <span class="jlb-label">Посещаемость:</span>
            <span class="jl"><span class="jl-sw" style="background:var(--g-good)"></span>Присутствовал</span>
            <span class="jl"><span class="jl-sw" style="background:var(--absent)"></span>Отсутствовал</span>
            <span class="jlb-label" style="margin-left:8px">Работы:</span>
            <span class="jl">количество решённых задач / баллы экзамена</span>
        </div>
    </div>`;
}

function lessonHead(l) {
    const [y, m, dd] = l.date.split('-');
    const dow = DOW_JS[new Date(l.date).getDay()];
    return `<th class="hd-col" data-glid="${l.group_lesson_id}" title="${esc(l.topic)} · ${l.date}">
        <div class="hd-date">${dd}.${m}</div>
        <div class="hd-dow">${dow}</div>
    </th>`;
}

function workHead(w) {
    return `<th class="hd-col work" style="--wt:var(--accent)" title="${esc(w.label)}">
        <div class="hd-work">${esc(truncate(w.label, 22))}</div>
    </th>`;
}

function attState(glid, pid) {
    const row = state.data.attendance[glid];
    if (!row || !(pid in row)) return 'none';
    return row[pid] ? 'present' : 'absent';
}

function attCell(pid, l) {
    const st = attState(l.group_lesson_id, pid);
    const cls = ['gc', 'att'];
    let inner = '';
    if (st === 'present') { cls.push('present'); inner = '<span class="g-val" style="color:var(--g-good);font-weight:700">+</span>'; }
    else if (st === 'absent') { cls.push('absent'); inner = '<span class="g-val att-n">Н</span>'; }
    return `<td class="${cls.join(' ')}" data-glid="${l.group_lesson_id}" data-pid="${pid}">${inner}</td>`;
}

function workCell(pid, w) {
    const cell = state.data.grades[w.key]?.[pid];
    const val = cell ? cell.value : '—';
    const pending = cell && cell.display === 'pending';
    return `<td class="gc work" style="--wt:var(--accent)">
        <span class="g-val" ${pending ? 'style="font-size:10px;color:var(--muted)"' : ''}>${esc(val)}</span>
    </td>`;
}

/* ── Interactions ─────────────────────────────────────────────────────── */
function onGridClick(e) {
    const td = e.target.closest('td.gc.att');
    if (td) { openAttPopover(+td.dataset.glid, +td.dataset.pid, td); return; }
    const th = e.target.closest('th.hd-col[data-glid]');
    if (th) { openColumnMenu(+th.dataset.glid, th); }
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
function truncate(s, n) { return s.length > n ? s.slice(0, n - 1) + '…' : s; }

function emptyHtml(title, text) {
    return `<div class="prof-journal"><div class="prof-ktp-empty">
        <div class="ke-ico"><svg width="34" height="34" viewBox="0 0 24 24" fill="none"><rect x="3" y="3.5" width="18" height="17" rx="2" stroke="currentColor" stroke-width="1.6"/><path d="M3 8h18M9 8v12" stroke="currentColor" stroke-width="1.6"/></svg></div>
        <h3>${esc(title)}</h3><p>${esc(text || '')}</p>
    </div></div>`;
}
function emptyInline(text) {
    return `<div class="j-empty">${esc(text)}</div>`;
}
