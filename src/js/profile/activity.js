/* ══════════════════════════════════════════════════════════════════════
   Активность группы — две вкладки на одном экране:

   • «Лента событий» — журнал обучения (fs_lms_learning_events): кто начал/сдал
     работу, кому поставили оценку, как менялось расписание. Постранично.
   • «Решения задач» — история попыток занятия тремя блоками: задания урока,
     задачи работ и контрольные. Задания и работы приходят из task_attempts
     (у работ ключ `work:{id}` — строка submissions при пересдаче
     перезаписывается, поэтому историю копит именно она), контрольные — из
     assessment_attempts, где каждая попытка изначально отдельная запись.

   Источник: window.fsProfile.activity:{events,program,attempts} + groups.
   ══════════════════════════════════════════════════════════════════════ */

import { esc, emptyState, fmtDateTime, fmtDate, fmtNum, plural } from './utils.js';
import { icoCaret, icoCheck, icoCross, icoJournal } from '../common/icons.js';
import { createApi } from './api.js';
import { openGroupPicker } from './picker.js';

/** Статус попытки контрольной, когда балла ещё нет. */
const EXAM_STATUS = {
    in_progress: 'идёт',
    submitted:   'на проверке',
    expired:     'истекла',
    graded:      'оценена',
};

/** Человекочитаемые названия событий журнала обучения. */
const EVENT_LABELS = {
    'learning.attempt_started':   'Начата попытка',
    'learning.attempt_submitted': 'Отправлена попытка',
    'learning.attempt_graded':    'Оценена попытка',
    'learning.submission_made':   'Сдана работа',
    'learning.submission_graded': 'Оценена работа',
    'learning.schedule_changed':  'Изменено расписание',
    'learning.course_assigned':   'Назначен курс',
    'learning.lesson_added':      'Добавлена тема',
    'learning.lesson_published':  'Тема открыта',
    'learning.lesson_hidden':     'Тема скрыта',
};

let root = null;
let state = null;
let apiEvents = null;
let apiProgram = null;
let apiAttempts = null;

export function renderActivity(r) {
    root = r;
    const p = window.fsProfile || {};
    const cfg = p.activity || null;

    state = {
        groups:  Array.isArray(p.groups) ? p.groups : [],
        cfg,
        groupId: (p.groups && p.groups[0]) ? p.groups[0].id : null,
        tab:     'events',
        events:  null,   // { events, total, page }
        lessons: null,   // строки программы для селекта занятий
        lessonId: null,
        attempts: null,  // { steps }
        loading: false,
    };

    if (!state.groups.length || !cfg) {
        root.innerHTML = emptyState('prof-activity', icoJournal(34), 'Нет групп', 'За вами пока не закреплены группы.');
        return;
    }

    apiEvents   = createApi(cfg.events);
    apiProgram  = createApi(cfg.program);
    apiAttempts = createApi(cfg.attempts);

    loadTab();
}

/* ── Загрузка ─────────────────────────────────────────────────────────── */

async function loadTab() {
    render(`<div class="rev-loading">Загрузка…</div>`);

    try {
        if ('events' === state.tab) {
            state.events = await apiEvents('getEvents', { group_id: state.groupId, page: 1 });
        } else {
            await loadLessons();
        }
    } catch (e) {
        render(`<div class="rev-empty">${esc(e.message)}</div>`);
        return;
    }

    render();
}

/** Список занятий группы для селекта; выбранным становится последнее прошедшее. */
async function loadLessons() {
    const program = await apiProgram('getProgram', { group_id: state.groupId });

    state.lessons = (program || []).map(entry => ({
        id:    entry.row?.id,
        date:  entry.row?.scheduledAt || '',
        topic: entry.topic || 'Без темы',
    })).filter(l => l.id);

    state.lessonId = pickDefaultLesson();
    state.attempts = state.lessonId ? await apiAttempts('getAttempts', { group_lesson_id: state.lessonId }) : null;
}

/**
 * По умолчанию открываем последнее занятие с датой в прошлом — по нему
 * решения уже есть; если таких нет, берём первое из программы.
 */
function pickDefaultLesson() {
    if (!state.lessons.length) { return null; }

    const now  = new Date().toISOString().slice(0, 19).replace('T', ' ');
    const past = state.lessons.filter(l => l.date && l.date <= now);

    return past.length ? past[past.length - 1].id : state.lessons[0].id;
}

async function loadMoreEvents() {
    if (state.loading || !state.events) { return; }
    state.loading = true;

    try {
        const next = await apiEvents('getEvents', { group_id: state.groupId, page: state.events.page + 1 });
        state.events = {
            events: state.events.events.concat(next.events || []),
            total:  next.total,
            page:   next.page,
        };
    } finally {
        state.loading = false;
    }

    render();
}

async function loadAttempts(lessonId) {
    state.lessonId = lessonId;
    render(`<div class="rev-loading">Загрузка…</div>`);

    try {
        state.attempts = await apiAttempts('getAttempts', { group_lesson_id: lessonId });
    } catch (e) {
        render(`<div class="rev-empty">${esc(e.message)}</div>`);
        return;
    }

    render();
}

/* ── Render ───────────────────────────────────────────────────────────── */

function group() { return state.groups.find(g => g.id === state.groupId) || state.groups[0]; }

function render(inner) {
    const g = group();
    const body = inner ?? ('events' === state.tab ? eventsBody() : attemptsBody());

    root.innerHTML = `
    <div class="prof-activity">
        <div class="act-head">
            <button class="prof-btn prof-btn-sm act-group-btn" id="actGroupBtn">
                ${esc(g ? g.name + ' · ' + g.subject : 'Группа')}
                ${icoCaret(12)}
            </button>
            <div class="act-tabs">
                <button class="act-tab${'events' === state.tab ? ' is-active' : ''}" data-tab="events">Лента событий</button>
                <button class="act-tab${'attempts' === state.tab ? ' is-active' : ''}" data-tab="attempts">Решения задач</button>
            </div>
        </div>
        ${body}
    </div>`;

    wire();
}

function eventsBody() {
    const data = state.events;
    if (!data || !data.events.length) {
        return `<div class="prof-card"><div class="rev-empty">По этой группе событий пока нет.</div></div>`;
    }

    const shown = data.events.length;
    const more  = shown < data.total
        ? `<button class="prof-btn prof-btn-sm act-more" data-act="more">Показать ещё (${data.total - shown})</button>`
        : '';

    return `
    <div class="prof-card">
        <div class="prof-card-head">
            <h3>Лента событий</h3>
            <span class="ch-sub">${shown} из ${data.total}</span>
        </div>
        <div class="act-feed">${data.events.map(eventRow).join('')}</div>
        ${more}
    </div>`;
}

function eventRow(e) {
    const label = EVENT_LABELS[e.action] || e.action;
    const actor = e.actor ? esc(e.actor) : 'система';

    return `
    <div class="act-event">
        <div class="act-event-main">
            <span class="act-event-label">${esc(label)}</span>
            <span class="act-event-actor">${actor}</span>
        </div>
        <time class="act-event-time">${esc(fmtDateTime(e.created_at))}</time>
    </div>`;
}

function attemptsBody() {
    if (!state.lessons || !state.lessons.length) {
        return `<div class="prof-card"><div class="rev-empty">В программе группы нет занятий.</div></div>`;
    }

    const options = state.lessons.map(l => `
        <option value="${l.id}"${l.id === state.lessonId ? ' selected' : ''}>
            ${esc((l.date ? fmtDate(l.date) + ' · ' : '') + l.topic)}
        </option>`).join('');

    const report = state.attempts ?? { steps: [], works: [], exams: [] };
    const blocks = [
        section('Задания урока', report.steps, stepCard),
        section('Работы', report.works, workCard),
        section('Контрольные', report.exams, examCard),
    ].filter(Boolean).join('');

    return `
    <div class="prof-card">
        <div class="prof-card-head">
            <h3>Решения задач</h3>
            <select class="act-lesson-select" id="actLesson">${options}</select>
        </div>
        <div class="act-steps">${blocks || '<div class="rev-empty">По этому занятию решений пока нет.</div>'}</div>
    </div>`;
}

/** Блок отчёта; пустые блоки не показываем — занятие редко содержит всё сразу. */
function section(title, rows, cardFn) {
    if (!rows || !rows.length) { return ''; }
    return `<div class="act-section"><div class="act-section-title">${esc(title)}</div>${rows.map(cardFn).join('')}</div>`;
}

function stepCard(step) {
    return taskCard(step, esc(step.task_title));
}

/** Задача работы: в заголовке — работа, чтобы одинаковые задачи не сливались. */
function workCard(work) {
    return taskCard(work, `${esc(work.work_title)} · ${esc(work.task_title)}`);
}

function taskCard(row, titleHtml) {
    return `
    <div class="act-step">
        <div class="act-step-head">
            <span class="act-step-title">${titleHtml}</span>
            <span class="act-step-stat">решили ${row.solved} из ${row.total}</span>
        </div>
        <div class="act-step-rows">${row.students.map(studentRow).join('')}</div>
    </div>`;
}

/** Контрольная: вместо «решили» — сколько учеников пересдавало. */
function examCard(exam) {
    const retakes = exam.retakes
        ? `${exam.retakes} ${plural(exam.retakes, 'пересдача', 'пересдачи', 'пересдач')}`
        : 'без пересдач';

    return `
    <div class="act-step">
        <div class="act-step-head">
            <span class="act-step-title">${esc(exam.title)}</span>
            <span class="act-step-stat">${exam.total} уч. · ${esc(retakes)}</span>
        </div>
        <div class="act-step-rows">${exam.students.map(examStudentRow).join('')}</div>
    </div>`;
}

function examStudentRow(student) {
    const best = null !== student.best_score && undefined !== student.best_score
        ? `${fmtNum(student.best_score)}${null != student.max_score ? '/' + fmtNum(student.max_score) : ''}`
        : '—';

    return `
    <div class="act-student">
        <div class="act-student-name">${esc(student.name)}</div>
        <div class="act-student-tries">${student.tries} ${plural(student.tries, 'попытка', 'попытки', 'попыток')}</div>
        <div class="act-attempts">
            <span class="act-best">лучший ${esc(best)}</span>
            ${student.attempts.map(examAttemptChip).join('')}
        </div>
    </div>`;
}

/** Чип попытки контрольной: балл + статус (оценена / на проверке / истекла). */
function examAttemptChip(attempt) {
    const score = null !== attempt.score && undefined !== attempt.score
        ? `${fmtNum(attempt.score)}/${fmtNum(attempt.max_score)}`
        : EXAM_STATUS[attempt.status] || attempt.status;

    return `
    <span class="act-attempt is-neutral" title="${esc(fmtDateTime(attempt.created_at))}">
        <span class="act-attempt-n">#${attempt.number} ${esc(score)}</span>
    </span>`;
}

function studentRow(student) {
    const chips = student.attempts.map(attemptChip).join('');

    return `
    <div class="act-student">
        <div class="act-student-name">${esc(student.name)}</div>
        <div class="act-student-tries">${student.tries} ${plural(student.tries, 'попытка', 'попытки', 'попыток')}</div>
        <div class="act-attempts">${chips}</div>
    </div>`;
}

function attemptChip(attempt) {
    const correct = true === attempt.correct;
    const mark    = correct ? icoCheck(12) : icoCross(10);
    const score   = null !== attempt.score && undefined !== attempt.score
        ? ` ${fmtNum(attempt.score)}/${fmtNum(attempt.max_score)}`
        : '';

    return `
    <span class="act-attempt${correct ? ' is-ok' : ' is-err'}" title="${esc(fmtDateTime(attempt.created_at))}">
        ${mark}<span class="act-attempt-n">#${attempt.number}${score}</span>
    </span>`;
}

/* ── Interactions ─────────────────────────────────────────────────────── */

function wire() {
    const btn = root.querySelector('#actGroupBtn');
    if (btn) {
        btn.addEventListener('click', () => openGroupPicker(btn, state.groups, state.groupId, id => {
            state.groupId = id;
            state.events  = null;
            state.lessons = null;
            loadTab();
        }));
    }

    root.querySelectorAll('[data-tab]').forEach(tab => tab.addEventListener('click', () => {
        if (tab.dataset.tab === state.tab) { return; }
        state.tab = tab.dataset.tab;
        // Данные вкладки могли не грузиться ни разу — тогда тянем, иначе рисуем из состояния.
        const loaded = 'events' === state.tab ? state.events : state.lessons;
        if (loaded) { render(); } else { loadTab(); }
    }));

    const more = root.querySelector('[data-act="more"]');
    if (more) { more.addEventListener('click', loadMoreEvents); }

    const select = root.querySelector('#actLesson');
    if (select) { select.addEventListener('change', () => loadAttempts(+select.value)); }
}
