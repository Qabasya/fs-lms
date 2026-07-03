/* ══════════════════════════════════════════════════════════════════════
   Экраны учащегося/родителя — реальные данные через AJAX (Эпик 7).
   Источник: window.fsProfile.learner:{nonce,actions}. Один endpoint getProfile
   отдаёт всё; родитель переключает ребёнка (fsProfile.children). Read-only.
   ══════════════════════════════════════════════════════════════════════ */

import { esc, fmtDayMonth, emptyState } from './utils.js';
import { createApi } from './api.js';

const RENDERERS = {
    'learner-home': renderHome,
    'learner-lessons': renderLessons,
    'learner-grades': renderGrades,
    'learner-attendance': renderAttendance,
};

let api = null;
let dataPromise = null;
let childId = null;

function cfg() { return window.fsProfile?.learner || null; }
function isParent() { return !!window.fsProfile?.readOnly; }

function load(force) {
    if (!api) { const c = cfg(); if (!c) return Promise.reject(new Error('Профиль недоступен')); api = createApi(c); }
    if (!dataPromise || force) {
        dataPromise = api('getProfile', childId ? { student_person_id: childId } : {});
    }
    return dataPromise;
}

async function screen(root, renderer, title) {
    if (!cfg()) { root.innerHTML = emptyHtml(title, 'Данные профиля недоступны.'); return; }
    root.innerHTML = `<div class="prof-dash"><div class="rev-loading">Загрузка…</div></div>`;
    try {
        renderer(root, await load());
    } catch (e) {
        root.innerHTML = emptyHtml(title, e.message);
    }
}

function rerenderAll() {
    document.querySelectorAll('.prof-screen[data-screen^="learner-"]').forEach(sec => {
        const r = RENDERERS[sec.dataset.screen];
        if (r) screen(sec, r, sec.dataset.screen);
    });
}

export function renderLearnerHome(root)       { screen(root, renderHome, 'Главная'); }
export function renderLearnerLessons(root)    { screen(root, renderLessons, 'Мои курсы'); }
export function renderLearnerGrades(root)     { screen(root, renderGrades, 'Мои оценки'); }
export function renderLearnerAttendance(root) { screen(root, renderAttendance, 'Посещаемость'); }

/* ── Child switcher (parent) ──────────────────────────────────────────── */
function childBar() {
    const children = window.fsProfile?.children || [];
    if (!isParent() || children.length < 1) { return ''; }
    const cur = childId || (children[0] && children[0].personId);
    return `<div class="prof-child-bar">
        <span class="prof-chip">Только просмотр</span>
        <label class="prof-child-pick">Ученик:
            <select id="learnerChild">
                ${children.map(c => `<option value="${c.personId}" ${String(c.personId) === String(cur) ? 'selected' : ''}>${esc(c.name)}</option>`).join('')}
            </select>
        </label>
    </div>`;
}

function wireChild(root) {
    const sel = root.querySelector('#learnerChild');
    if (!sel) return;
    sel.addEventListener('change', () => { childId = sel.value; load(true); rerenderAll(); });
}

/* ── Home ─────────────────────────────────────────────────────────────── */
function renderHome(root, d) {
    const name = isParent() ? (childName() || 'ученик') : (window.fsProfile?.user?.name || 'ученик');
    const att = d.attendance;
    root.innerHTML = `
    <div class="prof-dash">
        ${childBar()}
        <div class="prof-dash-hello">
            <h1>Здравствуйте, ${esc(name)} 👋</h1>
            <p>${d.groups.map(g => esc(g.name) + ' · ' + esc(g.subject)).join(' · ') || 'Нет активных групп'}</p>
        </div>
        <div class="prof-stat-tiles">
            ${homeTile('Ближайших занятий', String(d.upcoming.length), '#3b5bdb', 'cal')}
            ${homeTile('Дедлайнов', String(d.deadlines.length), '#f08c00', 'alert')}
            ${homeTile('Посещаемость', att.percent === null ? '—' : att.percent + '%', '#2f9e44', 'check')}
        </div>
        <div class="prof-dash-grid2">
            <div class="prof-card">
                <div class="prof-card-head"><h3>Расписание</h3></div>
                <div>${d.upcoming.length ? d.upcoming.map(schedRow).join('') : empty('Ближайших занятий нет.')}</div>
            </div>
            <div class="prof-card">
                <div class="prof-card-head"><h3>Дедлайны и оценки</h3></div>
                <div>
                    ${d.deadlines.map(dlRow).join('')}
                    ${d.recent.map(gradeRow).join('')}
                    ${(d.deadlines.length + d.recent.length) ? '' : empty('Пока пусто.')}
                </div>
            </div>
        </div>
    </div>`;
    wireChild(root);
}

/* ── Lessons (курсы = программа групп) ────────────────────────────────── */
function renderLessons(root, d) {
    root.innerHTML = `
    <div class="prof-dash">
        ${childBar()}
        <div class="prof-dash-hello"><h1>Мои курсы</h1></div>
        ${d.groups.map(g => `
            <div class="prof-card">
                <div class="prof-card-head"><h3>${esc(g.name)} · ${esc(g.subject)}</h3></div>
                <div>${d.lessons.filter(l => l.group_id === g.id).map(lessonRow).join('') || empty('Занятий пока нет.')}</div>
            </div>`).join('') || emptyCard('Нет активных групп.')}
    </div>`;
    wireChild(root);
}

/* ── Grades (дневник, сырые баллы) ────────────────────────────────────── */
function renderGrades(root, d) {
    root.innerHTML = `
    <div class="prof-dash">
        ${childBar()}
        <div class="prof-dash-hello"><h1>Мои оценки</h1><p>Сырые результаты: решённые задачи и баллы. Без 5-балльных отметок.</p></div>
        <div class="prof-card">
            <div class="prof-card-head"><h3>Работы и контрольные</h3><span class="ch-sub">${d.grades.length}</span></div>
            <div>${d.grades.length ? d.grades.map(gradeFullRow).join('') : empty('Оценок пока нет.')}</div>
        </div>
    </div>`;
    wireChild(root);
}

/* ── Attendance ───────────────────────────────────────────────────────── */
function renderAttendance(root, d) {
    const a = d.attendance;
    root.innerHTML = `
    <div class="prof-dash">
        ${childBar()}
        <div class="prof-dash-hello"><h1>Посещаемость</h1></div>
        <div class="prof-stat-tiles">
            ${homeTile('Посещаемость', a.percent === null ? '—' : a.percent + '%', '#2f9e44', 'check')}
            ${homeTile('Присутствовал', String(a.present), '#3b5bdb', 'cal')}
            ${homeTile('Пропущено', String(a.total - a.present), '#e03131', 'alert')}
        </div>
        <div class="prof-card">
            <div class="prof-card-head"><h3>По занятиям</h3></div>
            <div>${a.rows.length ? a.rows.map(attRow).join('') : empty('Отметок пока нет.')}</div>
        </div>
    </div>`;
    wireChild(root);
}

/* ── Rows ─────────────────────────────────────────────────────────────── */
function schedRow(l) {
    return `<div class="prof-lesson-row">
        <div class="prof-lesson-time"><div class="lt-start">${esc(l.start || '')}</div><div class="lt-end">${fmtDayMonth(l.date)}</div></div>
        <div class="prof-lesson-bar"></div>
        <div class="prof-lesson-body">
            <div class="prof-lesson-grp">${esc(l.group_name)}${l.kind === 'individual' ? ' <span class="prof-sub-tag indi">инд.</span>' : ''}</div>
            <div class="prof-lesson-topic">${esc(l.topic || '—')}</div>
        </div>
    </div>`;
}

function dlRow(d) {
    // T12.2 (D13): прошедший дедлайн не скрываем — решать можно, помечаем «Просрочено».
    const sub = d.overdue
        ? `${esc(d.group_name)} · <span class="prof-dl-overdue">Просрочено</span> ${fmtDateTime(d.due_at)}`
        : `${esc(d.group_name)} · до ${fmtDateTime(d.due_at)}`;
    return `<div class="prof-work-item${d.overdue ? ' overdue' : ''}">
        <div class="prof-work-ico att"><svg width="18" height="18" viewBox="0 0 20 20" fill="none"><path d="M10 5v5l3 2" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><circle cx="10" cy="10" r="7" stroke="currentColor" stroke-width="1.4"/></svg></div>
        <div class="prof-work-main"><div class="prof-work-title">${esc(d.topic || 'Домашнее задание')}</div><div class="prof-work-sub">${sub}</div></div>
    </div>`;
}

function gradeRow(g) {
    return `<div class="prof-work-item">
        <div class="prof-work-ico grade"><svg width="18" height="18" viewBox="0 0 20 20" fill="none"><path d="M10 3l2 4 4.5.6-3.3 3.2.8 4.5L10 13.2 6 15.5l.8-4.5L3.5 7.6 8 7z" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round"/></svg></div>
        <div class="prof-work-main"><div class="prof-work-title">${esc(g.title)}</div><div class="prof-work-sub">${esc(g.group_name)} · ${fmtDayMonth(g.graded_at)}</div></div>
        <span class="prof-work-count">${esc(g.value)}</span>
    </div>`;
}

function gradeFullRow(g) {
    const pending = g.display === 'pending';
    return `<div class="prof-work-item">
        <div class="prof-work-main"><div class="prof-work-title">${esc(g.title)}</div><div class="prof-work-sub">${esc(g.group_name)}${g.graded_at ? ' · ' + fmtDayMonth(g.graded_at) : ''}</div></div>
        <span class="prof-work-count${pending ? ' prof-work-count--pending' : ''}">${esc(g.value)}</span>
    </div>`;
}

function lessonRow(l) {
    const open = l.visibility === 'open';
    return `<div class="prof-work-item">
        <div class="prof-work-main"><div class="prof-work-title">${esc(l.topic || '—')}</div><div class="prof-work-sub">${l.date ? fmtDayMonth(l.date) : 'без даты'}</div></div>
        <span class="prof-chip ${open ? 'ok' : ''}">${open ? 'открыт' : 'скоро'}</span>
    </div>`;
}

function attRow(r) {
    return `<div class="prof-work-item">
        <div class="prof-work-main"><div class="prof-work-title">${esc(r.topic || '—')}</div><div class="prof-work-sub">${fmtDayMonth(r.date)}</div></div>
        <span class="prof-att-mark ${r.present ? 'p' : 'a'}">${r.present ? 'Был' : 'Н'}</span>
    </div>`;
}

function homeTile(label, val, color, ico) {
    const icons = {
        cal:   '<path d="M4 6h12v10H4zM4 9h12M7 4v3M13 4v3" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>',
        check: '<path d="M4 10.5 8 14l8-8.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>',
        alert: '<path d="M10 4v7M10 14.5v.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>',
    };
    return `<div class="prof-stat-tile">
        <div class="st-top"><span class="st-ico" style="background:${color}1a;color:${color}"><svg width="16" height="16" viewBox="0 0 20 20" fill="none">${icons[ico]}</svg></span>${esc(label)}</div>
        <div class="st-val">${esc(val)}</div>
    </div>`;
}

/* ── Helpers ──────────────────────────────────────────────────────────── */
function childName() {
    const children = window.fsProfile?.children || [];
    const cur = childId || (children[0] && children[0].personId);
    const c = children.find(x => String(x.personId) === String(cur));
    return c ? c.name : '';
}
function fmtDateTime(s) { if (!s) return ''; return fmtDayMonth(s) + ' ' + String(s).slice(11, 16); }
function empty(t) { return `<div class="rev-empty">${esc(t)}</div>`; }
function emptyCard(t) { return `<div class="prof-card"><div class="prof-card-empty">${esc(t)}</div></div>`; }

const EMPTY_ICON = '<svg width="34" height="34" viewBox="0 0 24 24" fill="none"><path d="M3 9.5 12 3l9 6.5M6 8.5V20h12V8.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>';

function emptyHtml(title, text) {
    return emptyState('prof-dash', EMPTY_ICON, title, text);
}
