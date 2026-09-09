/* ══════════════════════════════════════════════════════════════════════
   «Сводка по ученику» (Эпик 10 T10.8, D8; доработка — .docs/Tasks.md).
   Источник: window.fsProfile.{summary:{nonce,actions}, ajax.url}.
   Выбор идёт от ученика (все ученики, доступные пользователю) → его курс
   (группа), если их больше одного → две вкладки: «Занятия и прогресс по
   урокам» (карточки занятий: дата, тема, посещаемость, компактный прогресс
   по шагам урока, работы) и «Работы» (карточки работ, сгруппированные по
   занятиям — та же вёрстка, что в очереди проверки, Tasks.md п. 7).
   Оценивание — в детали работы (T10.9), переход через openWorkReview.
   ══════════════════════════════════════════════════════════════════════ */

import { esc, emptyState, fmtDate } from './utils.js';
import { icoDocCheck } from '../common/icons.js';
import { createApi } from './api.js';
import { groupPickerBtnHtml, studentPickerBtnHtml, openGroupPicker, openStudentPicker } from './picker.js';
import { workCardHtml } from './work-card.js';

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

/** @param {{ openWorkReview?: (sourceType: string, sourceId: number) => void }} [opts] */
export function renderSummary(r, opts = {}) {
    root = r;
    openWorkReviewCb = typeof opts.openWorkReview === 'function' ? opts.openWorkReview : null;
    const p = window.fsProfile || {};
    state = {
        cfg:      p.summary || null,
        students: [],
        personId: null,
        courses:  [],
        groupId:  null,
        data:     null,
        tab:      'lessons',
    };
    api = createApi(state.cfg);
    if (!state.cfg) { root.innerHTML = empty('Сводка недоступна', 'Экран «Сводка по ученику» не настроен.'); return; }
    loadStudents();
}

/* ── Data ─────────────────────────────────────────────────────────────── */
async function loadStudents() {
    try {
        state.students = await api('getStudents', {});
    } catch (e) {
        root.innerHTML = empty('Не удалось загрузить учеников', e.message);
        return;
    }
    state.personId = state.students.length ? state.students[0].person_id : null;
    if (!state.personId) { state.data = { lessons: [] }; render(); return; }
    loadCourses();
}

async function loadCourses() {
    try {
        state.courses = await api('getCourses', { student_person_id: state.personId });
    } catch (e) {
        root.innerHTML = empty('Не удалось загрузить курсы ученика', e.message);
        return;
    }
    state.groupId = state.courses.length ? state.courses[0].group_id : null;
    if (!state.groupId) { state.data = { lessons: [] }; render(); return; }
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
    const student = state.students.find(s => s.person_id === state.personId);

    root.innerHTML = `
    <div class="prof-summary">
        <div class="sum-head">
            <div class="prof-ktp-pick">
                <span class="kp-label">Ученик</span>
                ${studentPickerBtnHtml(student, state.students, 'sumStudentBtn')}
            </div>
            ${courseBlockHtml()}
        </div>
        ${tabsHtml()}
        <div class="sum-body">${'works' === state.tab ? worksHtml() : lessonsHtml()}</div>
    </div>`;

    wireHead();
    wireTabs();
    wireRows();
}

function courseBlockHtml() {
    if (state.courses.length > 1) {
        const c = state.courses.find(x => x.group_id === state.groupId) || state.courses[0];
        const g = { id: c.group_id, name: c.name, subject: c.subject };
        return `<div class="prof-ktp-pick">
            <span class="kp-label">Курс</span>
            ${groupPickerBtnHtml(g, 'sumCourseBtn')}
        </div>`;
    }
    if (1 === state.courses.length) {
        const c = state.courses[0];
        return `<div class="prof-ktp-pick">
            <span class="kp-label">Курс</span>
            <span class="sum-course-label">${esc(c.name)} · ${esc(c.subject)}</span>
        </div>`;
    }
    return '';
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
    const status = 'pending' === w.display ? 'На проверке' : (w.overdue ? `Просрочено · ${w.value}` : `Оценено · ${w.value}`);
    return workCardHtml({
        title:      w.title,
        badge:      w.badge,
        marks:      w.marks,
        subtitle:   status,
        date:       w.submitted_at,
        sourceType: w.source_type,
        sourceId:   w.source_id,
        rowClass:   'sum-work-card',
    });
}

/* T12.8: дропдауны ученика/курса — общий пикер (picker.js). */
function wireHead() {
    const sBtn = root.querySelector('#sumStudentBtn');
    if (sBtn && state.students.length) { sBtn.addEventListener('click', openStudentMenu); }

    const cBtn = root.querySelector('#sumCourseBtn');
    if (cBtn) { cBtn.addEventListener('click', openCourseMenu); }
}

function openStudentMenu() {
    openStudentPicker(document.getElementById('sumStudentBtn'), state.students, state.personId, id => {
        state.personId = id;
        state.tab = 'lessons';
        loadCourses();
    });
}

function openCourseMenu() {
    const groups = state.courses.map(c => ({ id: c.group_id, name: c.name, subject: c.subject }));
    openGroupPicker(document.getElementById('sumCourseBtn'), groups, state.groupId, id => {
        state.groupId = id;
        loadSummary();
    });
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
        ? `<div class="sum-works">${l.works.map(w => `
            <span class="sum-work${'pending' === w.display ? ' pending' : ''}${w.overdue ? ' overdue' : ''}" role="button" tabindex="0"
                data-src-type="${esc(w.source_type)}" data-src-id="${w.source_id}" title="${esc(w.title)}${w.overdue ? ' — сдано после дедлайна' : ''} — открыть">
                ${w.badge ? `<b>${esc(w.badge)}</b> ` : ''}${'pending' === w.display ? 'на проверке' : esc(w.value)}${w.overdue ? ' <span class="sum-work-late">просрочено</span>' : ''}
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
                ${progressBadge(l.progress)}
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
