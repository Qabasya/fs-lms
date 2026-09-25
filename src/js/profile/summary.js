/* ══════════════════════════════════════════════════════════════════════
   «Сводка по ученику» (Эпик 10 T10.8, D8; доработка — .docs/Tasks.md).
   Источник: window.fsProfile.{summary:{nonce,actions}, ajax.url}.
   Выбор — каскадом «направление → группа → ученик» (Tasks.md п. 2) плюс поиск
   по ФИО справа: найденный ученик сам выставляет направление и группу. Сюда же
   ведут карточка ученика в «Группах» и имя в журнале (openSummaryFor, п. 3).
   Две вкладки: «Занятия и прогресс по урокам» (карточки занятий: дата, тема,
   посещаемость, компактный прогресс по шагам урока, работы) и «Работы»
   (карточки работ, сгруппированные по занятиям).
   Оценивание — в детали работы (T10.9), переход через openWorkReview.
   ══════════════════════════════════════════════════════════════════════ */

import { esc, emptyState, fmtDate } from './utils.js';
import { icoDocCheck } from '../common/icons.js';
import { createApi } from './api.js';
import {
    subjectPickerBtnHtml, groupPickerBtnHtml, studentPickerBtnHtml,
    openSubjectPicker, openGroupPicker, openStudentPicker,
} from './picker.js';
import { studentSearchHtml, wireStudentSearch } from './student-search.js';
import { workCardHtml, workChipHtml, workStatusText } from './work-card.js';

const KIND_LABEL = { group: 'Групповое', individual: 'Индивидуальное' };
const ATT_LABEL  = { present: 'Присутствовал', absent: 'Отсутствовал', none: 'Не отмечено' };

const TABS = [
    { key: 'lessons', label: 'Занятия и прогресс по урокам' },
    { key: 'works', label: 'Работы' },
];

let root = null;
let state = null;
let api = null;
let openWorkReviewCb = null;
/** Промис загрузки справочника: переход «к ученику» может прийти раньше, чем он загрузился. */
let directoryReady = null;

/** @param {{ openWorkReview?: (sourceType: string, sourceId: number) => void }} [opts] */
export function renderSummary(r, opts = {}) {
    root = r;
    openWorkReviewCb = typeof opts.openWorkReview === 'function' ? opts.openWorkReview : null;
    const p = window.fsProfile || {};
    state = {
        cfg:        p.summary || null,
        groups:     [],
        students:   [],
        subjectKey: null,
        groupId:    null,
        personId:   null,
        data:       null,
        tab:        'lessons',
    };
    api = createApi(state.cfg);
    if (!state.cfg) {
        directoryReady = Promise.resolve(false);
        root.innerHTML = empty('Сводка недоступна', 'Экран «Сводка по ученику» не настроен.');
        return;
    }
    directoryReady = loadDirectory();
    directoryReady.then(ok => { if (ok && null === state.personId) { selectSubject(subjects()[0]?.key ?? null); } });
}

/**
 * Открыть сводку конкретного ученика в конкретной группе (Tasks.md п. 3:
 * карточка ученика в «Группах», имя в журнале).
 */
export async function openSummaryFor(groupId, personId) {
    if (!directoryReady || !(await directoryReady)) { return; }
    const g = state.groups.find(x => x.id === +groupId);
    if (!g) { return; }
    state.subjectKey = g.subject_key;
    state.groupId = g.id;
    state.personId = groupStudents().some(s => s.person_id === +personId) ? +personId : (groupStudents()[0]?.person_id ?? null);
    state.tab = 'lessons';
    loadSummary();
}

/* ── Data ─────────────────────────────────────────────────────────────── */
async function loadDirectory() {
    try {
        const d = await api('getStudents', {});
        state.groups = d.groups || [];
        state.students = (d.students || []).slice().sort((a, b) => a.name.localeCompare(b.name, 'ru'));
    } catch (e) {
        root.innerHTML = empty('Не удалось загрузить учеников', e.message);
        return false;
    }
    if (!state.groups.length) {
        root.innerHTML = empty('Учеников нет', 'В ваших группах пока нет активных учеников.');
        return false;
    }
    return true;
}

async function loadSummary() {
    if (!state.groupId || !state.personId) { state.data = { lessons: [] }; render(); return; }
    const key = `${state.groupId}:${state.personId}`;
    try {
        const data = await api('getSummary', { group_id: state.groupId, student_person_id: state.personId });
        // Пока грузилось, выбор могли сменить — старый ответ не рисуем.
        if (key !== `${state.groupId}:${state.personId}`) { return; }
        state.data = data;
    } catch (e) {
        root.innerHTML = empty('Не удалось загрузить сводку', e.message);
        return;
    }
    render();
}

/* ── Cascade: направление → группа → ученик ───────────────────────────── */

/** Направления (предметы) из доступных групп, по алфавиту. */
function subjects() {
    const map = new Map();
    state.groups.forEach(g => { if (!map.has(g.subject_key)) { map.set(g.subject_key, { key: g.subject_key, name: g.subject }); } });
    return [...map.values()].sort((a, b) => a.name.localeCompare(b.name, 'ru'));
}

function subjectGroups() {
    return state.groups.filter(g => g.subject_key === state.subjectKey);
}

function groupStudents() {
    return state.students.filter(s => s.groups.includes(state.groupId));
}

function selectSubject(key) {
    state.subjectKey = key;
    selectGroup(subjectGroups()[0]?.id ?? null);
}

function selectGroup(id) {
    state.groupId = id;
    state.personId = groupStudents()[0]?.person_id ?? null;
    state.tab = 'lessons';
    loadSummary();
}

/** Ученик из поиска: остаёмся в текущей группе, если он в ней учится, иначе — его первая. */
function selectFoundStudent(personId) {
    const s = state.students.find(x => x.person_id === personId);
    if (!s) { return; }
    const gid = s.groups.includes(state.groupId)
        ? state.groupId
        : (state.groups.find(g => s.groups.includes(g.id)) || {}).id;
    openSummaryFor(gid, personId);
}

/* ── Render ───────────────────────────────────────────────────────────── */
function render() {
    const subject = subjects().find(x => x.key === state.subjectKey);
    const group = state.groups.find(g => g.id === state.groupId);
    const roster = groupStudents();
    const student = roster.find(s => s.person_id === state.personId);

    root.innerHTML = `
    <div class="prof-summary">
        <div class="sum-head">
            <div class="sum-pickers">
                ${subject ? `<div class="prof-ktp-pick">
                    <span class="kp-label">Направление</span>
                    ${subjectPickerBtnHtml(subject, 'sumSubjectBtn')}
                </div>` : ''}
                ${group ? `<div class="prof-ktp-pick">
                    <span class="kp-label">Группа</span>
                    ${groupPickerBtnHtml(group, 'sumGroupBtn')}
                </div>` : ''}
                <div class="prof-ktp-pick">
                    <span class="kp-label">Ученик</span>
                    ${studentPickerBtnHtml(student, roster, 'sumStudentBtn')}
                </div>
            </div>
            ${studentSearchHtml('sumSearch')}
        </div>
        ${tabsHtml()}
        <div class="sum-body prof-swap">${'works' === state.tab ? worksHtml() : lessonsHtml()}</div>
    </div>`;

    wireHead();
    wireTabs();
    wireRows();
}

function tabsHtml() {
    return `<div class="sum-tabs">${TABS.map(t => `
        <button type="button" class="sum-tab${t.key === state.tab ? ' active' : ''}" data-tab="${t.key}">${esc(t.label)}</button>`).join('')}</div>`;
}

function lessonsHtml() {
    const lessons = (state.data && state.data.lessons) || [];
    return lessons.length
        ? `<div class="sum-cards">${lessons.map(lessonCard).join('')}</div>`
        : `<div class="j-empty">${state.data && state.data.open ? 'В программе нет занятий.' : 'У ученика пока нет датированных занятий.'}</div>`;
}

/* Tasks.md, п. 7: работы — карточками (как в очереди проверки), сгруппированными
   по занятиям; плоская таблица не показывала ни разбор по заданиям, ни того,
   к какому уроку работа относится. */
function worksHtml() {
    const groups = (((state.data && state.data.lessons) || []).map(l => ({
        title: l.topic || '—',
        date:  l.date,
        works: l.works || [],
    }))).filter(g => g.works.length);

    if (!groups.length) { return '<div class="j-empty">Работ нет.</div>'; }

    return `<div class="sum-work-groups">${groups.map(g => `
        <div class="sum-work-group">
            <div class="swg-head">
                <span class="swg-topic">${esc(g.title)}</span>
                ${g.date ? `<span class="swg-date">${esc(fmtDate(g.date))}</span>` : ''}
            </div>
            <div class="wk-sub-list">${g.works.map(workCard).join('')}</div>
        </div>`).join('')}</div>`;
}

function workCard(w) {
    return workCardHtml({
        title:      w.title,
        badge:      w.badge,
        marks:      w.marks,
        subtitle:   workStatusText(w),
        date:       w.submitted_at,
        duration:   w.duration_sec,
        sourceType: w.source_type,
        sourceId:   w.source_id,
        rowClass:   'sum-work-card',
    });
}

/* T12.8: дропдауны — общий пикер (picker.js); поиск — student-search.js. */
function wireHead() {
    const subBtn = root.querySelector('#sumSubjectBtn');
    if (subBtn) {
        subBtn.addEventListener('click', () =>
            openSubjectPicker(subBtn, subjects(), state.subjectKey, selectSubject));
    }

    const gBtn = root.querySelector('#sumGroupBtn');
    if (gBtn) {
        gBtn.addEventListener('click', () =>
            openGroupPicker(gBtn, subjectGroups(), state.groupId, selectGroup));
    }

    const sBtn = root.querySelector('#sumStudentBtn');
    if (sBtn && groupStudents().length) {
        sBtn.addEventListener('click', () =>
            openStudentPicker(sBtn, groupStudents(), state.personId, id => {
                state.personId = id;
                state.tab = 'lessons';
                loadSummary();
            }));
    }

    const search = root.querySelector('#sumSearch');
    if (search) {
        wireStudentSearch(search, state.students, gid => (state.groups.find(g => g.id === gid) || {}).name || '', selectFoundStudent);
    }
}

function wireTabs() {
    root.querySelectorAll('.sum-tab[data-tab]').forEach(btn =>
        btn.addEventListener('click', () => {
            if (btn.dataset.tab !== state.tab) { state.tab = btn.dataset.tab; render(); }
        }));
}

function wireRows() {
    root.querySelectorAll('.sum-work[data-src-id], .wcard[data-src-id]').forEach(el =>
        el.addEventListener('click', () => {
            if (openWorkReviewCb) { openWorkReviewCb(el.dataset.srcType, +el.dataset.srcId); }
        }));
}

function strip(l) {
    if ('individual' === l.kind) { return 'individual'; }
    return l.attendance; // present | absent | none
}

function progressBadge(p) {
    if (!p) { return ''; }
    return `<span class="sum-progress${p.failed ? ' has-fail' : ''}" title="Прогресс по шагам урока: ${p.done}/${p.total}${p.failed ? ', есть проваленные шаги' : ''}">${p.done}/${p.total}</span>`;
}

function lessonCard(l) {
    const open = !!(state.data && state.data.open);
    const st = strip(l);
    const works = l.works.length
        // Несданное ДЗ открывать нечего — у его чипа нет действия.
        ? `<div class="sum-works">${l.works.map(w => workChipHtml(w, w.source_id
            ? `role="button" tabindex="0" data-src-type="${esc(w.source_type)}" data-src-id="${w.source_id}"`
            : '')).join('')}</div>`
        : '<div class="sum-works sum-works-empty">Работ нет</div>';

    // Посещаемость — только цветом полоски слева (подпись — во всплывающей подсказке).
    const stripTitle = 'individual' === st ? KIND_LABEL.individual : (open ? '' : ATT_LABEL[st]);

    return `
    <div class="sum-card">
        <span class="sum-strip sum-strip-${esc(st)}" title="${esc(stripTitle || '')}"></span>
        <div class="sum-card-body">
            <span class="sum-date">${l.date ? esc(fmtDate(l.date)) : ''}</span>
            <span class="sum-kind sum-kind-${esc(l.kind)}">${esc(KIND_LABEL[l.kind] || l.kind)}</span>
            <span class="sum-prog-cell">${progressBadge(l.progress)}</span>
            <div class="sum-topic" title="${esc(l.topic || '')}">${esc(l.topic || '—')}</div>
            ${works}
        </div>
    </div>`;
}

/* ── Helpers ──────────────────────────────────────────────────────────── */

function empty(title, text) {
    return emptyState('prof-summary', icoDocCheck(34), title, text);
}
