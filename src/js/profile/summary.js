/* ══════════════════════════════════════════════════════════════════════
   «Сводка по ученику» (Эпик 10 T10.8, D8) — заменяет очередь «Проверка работ».
   Источник: window.fsProfile.{groups, summary:{nonce,actions}, ajax.url}.
   Выбор группы + ученика → карточки его занятий: дата, тема, цветная полоса
   (🟢 посещён · 🟣 индивидуальное · 🔴 пропуск · серый — не отмечено) и результаты
   работ по типам (badge + сырой балл). Оценивание — в детали работы (T10.9).
   ══════════════════════════════════════════════════════════════════════ */

import { esc, emptyState, fmtDate } from './utils.js';
import { icoDocCheck } from '../common/icons.js';
import { createApi } from './api.js';
import { DOW_JS } from './constants.js';
import { groupPickerBtnHtml, studentPickerBtnHtml, openGroupPicker, openStudentPicker } from './picker.js';

const KIND_LABEL = { group: 'Групповое', individual: 'Индивидуальное' };
const ATT_LABEL  = { present: 'Присутствовал', absent: 'Отсутствовал', none: 'Не отмечено' };

let root = null;
let state = null;
let api = null;
let openWorkReviewCb = null;

/** @param {{ openWorkReview?: (sourceType: string, sourceId: number) => void }} [opts] */
export function renderSummary(r, opts = {}) {
    root = r;
    openWorkReviewCb = typeof opts.openWorkReview === 'function' ? opts.openWorkReview : null;
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
        : `<div class="j-empty">${state.data && state.data.open ? 'В программе нет занятий.' : 'У ученика пока нет датированных занятий.'}</div>`;

    const g = state.groups.find(x => x.id === state.groupId) || state.groups[0];
    const student = state.roster.find(s => s.person_id === state.personId);

    root.innerHTML = `
    <div class="prof-summary">
        <div class="sum-head">
            <div class="prof-ktp-pick">
                <span class="kp-label">Группа</span>
                ${groupPickerBtnHtml(g, 'sumGroupBtn')}
            </div>
            <div class="prof-ktp-pick">
                <span class="kp-label">Ученик</span>
                ${studentPickerBtnHtml(student, state.roster, 'sumStudentBtn')}
            </div>
        </div>
        <div class="sum-cards">${cards}</div>
    </div>`;

    const gBtn = root.querySelector('#sumGroupBtn');
    if (gBtn) gBtn.addEventListener('click', openGroupMenu);
    const sBtn = root.querySelector('#sumStudentBtn');
    if (sBtn && state.roster.length) sBtn.addEventListener('click', openStudentMenu);

    root.querySelectorAll('.sum-work[data-src-id]').forEach(el =>
        el.addEventListener('click', () => {
            if (openWorkReviewCb) { openWorkReviewCb(el.dataset.srcType, +el.dataset.srcId); }
        }));
}

/* T12.8: дропдауны группы/ученика — общий пикер (picker.js). */
function openGroupMenu() {
    openGroupPicker(document.getElementById('sumGroupBtn'), state.groups, state.groupId, id => {
        state.groupId = id;
        loadRoster();
    });
}

function openStudentMenu() {
    openStudentPicker(document.getElementById('sumStudentBtn'), state.roster, state.personId, id => {
        state.personId = id;
        loadSummary();
    });
}

function strip(l) {
    if (l.kind === 'individual') return 'individual';
    return l.attendance; // present | absent | none
}

function lessonCard(l) {
    const open = !!(state.data && state.data.open);
    const st = strip(l);
    const works = l.works.length
        ? `<div class="sum-works">${l.works.map(w => `
            <span class="sum-work${w.display === 'pending' ? ' pending' : ''}${w.overdue ? ' overdue' : ''}" role="button" tabindex="0"
                data-src-type="${esc(w.source_type)}" data-src-id="${w.source_id}" title="${esc(w.title)}${w.overdue ? ' — сдано после дедлайна' : ''} — открыть">
                ${w.badge ? `<b>${esc(w.badge)}</b> ` : ''}${w.display === 'pending' ? 'на проверке' : esc(w.value)}${w.overdue ? ' <span class="sum-work-late">просрочено</span>' : ''}
            </span>`).join('')}</div>`
        : '<div class="sum-works sum-works-empty">Работ нет</div>';

    return `
    <div class="sum-card">
        <span class="sum-strip sum-strip-${esc(st)}" title="${esc(ATT_LABEL[st] || KIND_LABEL[l.kind] || '')}"></span>
        <div class="sum-card-body">
            <div class="sum-card-top">
                ${l.date ? `<span class="sum-date">${esc(fmtDate(l.date))}</span>` : ''}
                <span class="sum-kind sum-kind-${esc(l.kind)}">${esc(KIND_LABEL[l.kind] || l.kind)}</span>
                ${l.kind !== 'individual' && !open ? `<span class="sum-att sum-att-${esc(l.attendance)}">${esc(ATT_LABEL[l.attendance])}</span>` : ''}
            </div>
            <div class="sum-topic">${esc(l.topic || '—')}</div>
            ${works}
        </div>
    </div>`;
}

/* ── Helpers ──────────────────────────────────────────────────────────── */

function empty(title, text) {
    return emptyState('prof-summary', icoDocCheck(34), title, text);
}
