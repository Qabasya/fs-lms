/* ══════════════════════════════════════════════════════════════════════
   «Сводка по ученику» (Эпик 10 T10.8, D8) — заменяет очередь «Проверка работ».
   Источник: window.fsProfile.{groups, summary:{nonce,actions}, ajax.url}.
   Выбор группы + ученика → карточки его занятий: дата, тема, цветная полоса
   (🟢 посещён · 🟣 индивидуальное · 🔴 пропуск · серый — не отмечено) и результаты
   работ по типам (badge + сырой балл). Оценивание — в детали работы (T10.9).
   ══════════════════════════════════════════════════════════════════════ */

import { esc } from './utils.js';
import { createApi } from './api.js';

const KIND_LABEL = { group: 'Групповое', individual: 'Индивидуальное' };
const ATT_LABEL  = { present: 'Присутствовал', absent: 'Отсутствовал', none: 'Не отмечено' };
const DOW = ['Вс', 'Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб'];

let root = null;
let state = null;
let api = null;

export function renderSummary(r) {
    root = r;
    const p = window.fsProfile || {};
    state = {
        groups:   Array.isArray(p.groups) ? p.groups : [],
        cfg:      p.summary || null,
        groupId:  (p.groups && p.groups[0]) ? p.groups[0].id : null,
        personId: null,
        roster:   [],
        data:     null,
    };
    api = createApi(state.cfg);
    if (!state.groups.length || !state.cfg) { root.innerHTML = empty('Нет групп', 'За вами не закреплены группы.'); return; }
    loadRoster();
}

async function loadRoster() {
    try {
        const d = await api('getRoster', { group_id: state.groupId });
        state.roster = Array.isArray(d.students) ? d.students : [];
    } catch (e) {
        root.innerHTML = empty('Не удалось загрузить ростер', e.message);
        return;
    }
    state.personId = state.roster.length ? state.roster[0].person_id : null;
    if (!state.personId) { state.data = { lessons: [] }; render(); return; }
    loadSummary();
}

async function loadSummary() {
    try {
        state.data = await api('getSummary', { group_id: state.groupId, student_person_id: state.personId });
    } catch (e) {
        root.innerHTML = empty('Не удалось загрузить сводку', e.message);
        return;
    }
    render();
}

/* ── Render ───────────────────────────────────────────────────────────── */
function render() {
    const lessons = (state.data && state.data.lessons) || [];
    const cards = lessons.length
        ? lessons.map(lessonCard).join('')
        : '<div class="j-empty">У ученика пока нет датированных занятий.</div>';

    root.innerHTML = `
    <div class="prof-summary">
        <div class="sum-head">
            <label class="sum-pick">
                <span>Группа</span>
                <select class="sum-select" data-pick="group">
                    ${state.groups.map(g => `<option value="${g.id}" ${g.id === state.groupId ? 'selected' : ''}>${esc(g.name)} · ${esc(g.subject)}</option>`).join('')}
                </select>
            </label>
            <label class="sum-pick">
                <span>Ученик</span>
                <select class="sum-select" data-pick="student" ${state.roster.length ? '' : 'disabled'}>
                    ${state.roster.length
                        ? state.roster.map(s => `<option value="${s.person_id}" ${s.person_id === state.personId ? 'selected' : ''}>${esc(s.name)}</option>`).join('')
                        : '<option>— нет активных учеников —</option>'}
                </select>
            </label>
        </div>
        <div class="sum-cards">${cards}</div>
    </div>`;

    const gsel = root.querySelector('[data-pick="group"]');
    if (gsel) gsel.addEventListener('change', () => { state.groupId = +gsel.value; loadRoster(); });
    const ssel = root.querySelector('[data-pick="student"]');
    if (ssel) ssel.addEventListener('change', () => { state.personId = +ssel.value; loadSummary(); });
}

function strip(l) {
    if (l.kind === 'individual') return 'individual';
    return l.attendance; // present | absent | none
}

function lessonCard(l) {
    const st = strip(l);
    const works = l.works.length
        ? `<div class="sum-works">${l.works.map(w => `
            <span class="sum-work${w.display === 'pending' ? ' pending' : ''}" title="${esc(w.title)}">
                ${w.badge ? `<b>${esc(w.badge)}</b> ` : ''}${w.display === 'pending' ? 'на проверке' : esc(w.value)}
            </span>`).join('')}</div>`
        : '<div class="sum-works sum-works-empty">Работ нет</div>';

    return `
    <div class="sum-card">
        <span class="sum-strip sum-strip-${esc(st)}" title="${esc(ATT_LABEL[st] || KIND_LABEL[l.kind] || '')}"></span>
        <div class="sum-card-body">
            <div class="sum-card-top">
                <span class="sum-date">${esc(fmtDate(l.date))}</span>
                <span class="sum-kind sum-kind-${esc(l.kind)}">${esc(KIND_LABEL[l.kind] || l.kind)}</span>
                ${l.kind !== 'individual' ? `<span class="sum-att sum-att-${esc(l.attendance)}">${esc(ATT_LABEL[l.attendance])}</span>` : ''}
            </div>
            <div class="sum-topic">${esc(l.topic || '—')}</div>
            ${works}
        </div>
    </div>`;
}

/* ── Helpers ──────────────────────────────────────────────────────────── */
function fmtDate(iso) {
    const parts = String(iso).split('-');
    if (parts.length !== 3) return iso;
    const [y, m, d] = parts;
    const dow = DOW[new Date(`${y}-${m}-${d}T00:00:00`).getDay()];
    return `${d}.${m} · ${dow}`;
}

function empty(title, text) {
    return `<div class="prof-summary"><div class="prof-ktp-empty">
        <div class="ke-ico"><svg width="34" height="34" viewBox="0 0 24 24" fill="none"><path d="M5 3h9l5 5v13H5z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><path d="M14 3v5h5M8 13l2 2 4-4.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg></div>
        <h3>${esc(title)}</h3><p>${esc(text || '')}</p>
    </div></div>`;
}
