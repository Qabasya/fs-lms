/* ══════════════════════════════════════════════════════════════════════
   Экран «Группы» (Эпик 10 T10.7) — ростер выбранной группы.
   Источник: window.fsProfile.{groups, roster:{nonce,actions}, ajax.url}.
   Показывает активных учеников (PII-safe snapshot-имена) и их индивидуальные
   занятия; здесь же создаются индивидуальные занятия (перенос из журнала).
   Клик по группе в сайдбаре открывает этот экран (см. app.js openGroupsFor).
   ══════════════════════════════════════════════════════════════════════ */

import { esc, toast, initials, firstWord, avaColor, emptyState } from './utils.js';
import { createApi } from './api.js';
import { openIndiModal } from './indi-modal.js';

const STATUS_LABEL = { scheduled: 'запланировано', done: 'проведено', cancelled: 'отменено' };

let root = null;
let state = null;
let api = null;

export function renderGroups(r, handlers = {}) {
    root = r;
    const p = window.fsProfile || {};
    state = {
        groups:   Array.isArray(p.groups) ? p.groups : [],
        cfg:      p.roster || null,
        groupId:  (p.groups && p.groups[0]) ? p.groups[0].id : null,
        data:     null,
        handlers,
    };
    api = createApi(state.cfg);
    if (!state.groups.length || !state.cfg) { root.innerHTML = empty('Нет групп', 'За вами не закреплены группы.'); return; }
    load();
}

/** Вызывается из сайдбара (app.js) при выборе группы. */
export function setGroupsGroup(gid) {
    if (!state) return;
    state.groupId = gid;
    load();
}

async function load() {
    if (!state.groupId) { root.innerHTML = empty('Нет группы', ''); return; }
    try {
        state.data = await api('getRoster', { group_id: state.groupId });
    } catch (e) {
        root.innerHTML = empty('Не удалось загрузить ростер', e.message);
        return;
    }
    render();
}

function group() { return state.groups.find(g => g.id === state.groupId) || state.groups[0]; }

/* ── Render ───────────────────────────────────────────────────────────── */
function render() {
    const g = group();
    const d = state.data;
    const rows = d.students.length
        ? d.students.map(studentRow).join('')
        : '<div class="j-empty">В группе нет активных учеников.</div>';

    root.innerHTML = `
    <div class="prof-roster">
        <div class="pr-head">
            <div class="pr-head-main">
                <div class="pr-title">${esc(g.name)}</div>
                <div class="pr-sub">${esc(g.subject)} · ${d.students.length} уч.</div>
            </div>
            <div class="pr-head-actions">
                <button class="prof-btn prof-btn-sm" data-act="journal">Журнал</button>
            </div>
        </div>
        <div class="pr-list">${rows}</div>
    </div>`;

    const jbtn = root.querySelector('[data-act="journal"]');
    if (jbtn) jbtn.addEventListener('click', () => state.handlers.openJournal && state.handlers.openJournal(state.groupId));

    root.querySelectorAll('[data-add-indi]').forEach(b =>
        b.addEventListener('click', () => openIndiForm(+b.dataset.pid, b)));
}

function studentRow(s) {
    const indis = s.individual.length
        ? `<div class="pr-indis">${s.individual.map(x => `
            <span class="pr-indi pr-indi-${esc(x.status)}" title="${esc(STATUS_LABEL[x.status] || x.status)}">
                <span class="pr-indi-date">${x.date ? esc(fmtDate(x.date)) : '—'}</span>${x.label ? ' · ' + esc(x.label) : ''}
            </span>`).join('')}</div>`
        : '<div class="pr-indis pr-indis-empty">Индивидуальных занятий нет</div>';

    return `
    <div class="pr-row" data-pid="${s.person_id}">
        <span class="pr-ava" style="background:${avaColor(state.data.students, s.person_id)}">${initials(s.name)}</span>
        <div class="pr-info">
            <div class="pr-name">${esc(s.name)}</div>
            ${indis}
        </div>
        <button class="prof-btn prof-btn-sm prof-btn-ghost" data-add-indi data-pid="${s.person_id}">＋ Индивидуальное</button>
    </div>`;
}

/* ── Создание индивидуального занятия (перенос из журнала, T10.5→T10.7) ── */
// Инд. занятие из «Группы»: общая модалка (B2) с фиксированными группой и учеником;
// дата/время/кабинет/тема выбираются, тема = урок банка (lesson_id).
function openIndiForm(pid, anchor) {
    const s = state.data.students.find(x => x.person_id === pid);
    const groups = window.fsProfile?.groups || [];
    openIndiModal({
        api,
        anchor,
        groups,
        fixed: {
            group: groups.find(g => g.id === state.groupId) || { id: state.groupId, name: '' },
            student: { person_id: pid, name: s ? s.name : '' },
        },
        onSaved: load,
    });
}

/* ── Helpers ──────────────────────────────────────────────────────────── */
function fmtDate(iso) {
    // iso "YYYY-MM-DD HH:MM" → "DD.MM HH:MM"
    const [d, t] = String(iso).split(' ');
    if (!d) return iso;
    const [, m, dd] = d.split('-');
    return `${dd}.${m}${t ? ' ' + t : ''}`;
}

const EMPTY_ICON = '<svg width="34" height="34" viewBox="0 0 24 24" fill="none"><circle cx="9" cy="8" r="3" stroke="currentColor" stroke-width="1.6"/><path d="M3 20c0-3.3 2.7-6 6-6s6 2.7 6 6M16 5a3 3 0 0 1 0 6M21 20c0-2.5-1.5-4.6-3.6-5.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>';

function empty(title, text) {
    return emptyState('prof-roster', EMPTY_ICON, title, text);
}
