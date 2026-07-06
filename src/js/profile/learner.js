/* ══════════════════════════════════════════════════════════════════════
   Экраны учащегося/родителя — реальные данные через AJAX (Эпик 7).
   Источник: window.fsProfile.learner:{nonce,actions}. Один endpoint getProfile
   отдаёт всё; родитель переключает ребёнка (fsProfile.children). Read-only.
   ══════════════════════════════════════════════════════════════════════ */

import { esc, fmtDayMonth, fmtDate, emptyState, chipBg, chipText, chipSoft, toast } from './utils.js';
import { icoCalendar, icoCheck, icoAlert, icoSearch, icoChevronRight, icoChevronDown, icoClock, icoStar, icoHome, icoLock } from '../common/icons.js';
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
        ${examLockBanner(d)}
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

/* ── Мои курсы (дизайн Student Courses) ───────────────────────────────────
   Явное разделение курсов: вкладки курсов → карточка выбранного курса (прогресс,
   «Продолжить») → программа по модулям (раскрытие, поиск). Данные — d.courses
   (LearnerService::buildCourses). Состояние (активный курс/раскрытие/поиск) —
   scState, переживает re-render экрана. ── */
let scState = null;
let scExamLock = null; // активная запирающая контрольная (см. examLockBanner / scRenderHero)

function scPlural(n, forms) {
    const a = n % 10, b = n % 100;
    if (a === 1 && b !== 11) { return forms[0]; }
    if (a >= 2 && a <= 4 && (b < 12 || b > 14)) { return forms[1]; }
    return forms[2];
}
const SC_PILL = {
    done:      '<span class="sc-pill done">пройден</span>',
    available: '<span class="sc-pill open">доступен</span>',
    locked:    '<span class="sc-pill lock">закрыт</span>',
};

function renderLessons(root, d) {
    const courses = Array.isArray(d.courses) ? d.courses : [];
    scExamLock = d.exam_lock || null;
    root.innerHTML = `
    <div class="prof-dash sc">
        ${childBar()}
        ${examLockBanner(d)}
        <div class="prof-dash-hello">
            <h1>Мои курсы</h1>
            ${courses.length ? `<p>${courses.length} ${scPlural(courses.length, ['курс', 'курса', 'курсов'])}</p>` : ''}
        </div>
        ${courses.length ? `
            <div class="sc-tabs" id="scTabs"></div>
            <div class="prof-card sc-hero" id="scHero"></div>
            <div class="prof-card">
                <div class="prof-card-head sc-prog-head">
                    <div><h3>Программа курса</h3><span class="ch-sub" id="scProgSub"></span></div>
                    <div class="sc-search" id="scSearchWrap">
                        ${icoSearch(14)}
                        <input type="text" id="scSearch" placeholder="Поиск по урокам">
                    </div>
                    <button class="prof-btn prof-btn-sm" id="scExpand"></button>
                </div>
                <div id="scProgBody"></div>
            </div>
        ` : emptyCard('Активных курсов пока нет.')}
        ${catalogHtml(d)}
    </div>`;
    wireChild(root);
    wireCatalog(root);
    if (!courses.length) { return; }

    if (!scState || !courses.some(c => c.id === scState.active)) {
        scState = { active: courses[0].id, expand: {}, query: '' };
    }
    scRenderAll(courses);

    document.getElementById('scSearch').addEventListener('input', (e) => {
        scState.query = e.target.value;
        scRenderProgram(courses);
    });
}

/* ── Каталог открытых курсов (Эпик 15, П10): самозапись учеником ───────── */
function catalogHtml(d) {
    const items = Array.isArray(d.catalog) ? d.catalog : [];
    if (!items.length) { return ''; }
    // Родитель — read-only: каталог виден, но записываться может только сам ученик.
    const canEnroll = !isParent();
    return `
    <div class="prof-card sc-catalog">
        <div class="prof-card-head">
            <div><h3>Доступные курсы</h3><span class="ch-sub">свободное прохождение — весь курс открыт сразу</span></div>
        </div>
        ${items.map(c => `
        <div class="sc-cat-row">
            <span class="sc-chip ${chipBg(c.subject_key)}">${esc(c.abbr)}</span>
            <span class="sc-lb">
                <span class="sc-ltitle">${esc(c.title)}</span>
                <span class="sc-lsub">${[c.subject, c.teacher, c.lessons_total ? c.lessons_total + ' ' + scPlural(c.lessons_total, ['урок', 'урока', 'уроков']) : ''].filter(Boolean).map(esc).join(' · ')}</span>
            </span>
            ${canEnroll ? `<button class="prof-btn prof-btn-sm prof-btn-primary js-cat-enroll" data-gid="${c.group_id}">Записаться</button>` : ''}
        </div>`).join('')}
    </div>`;
}

function wireCatalog(root) {
    root.querySelectorAll('.js-cat-enroll').forEach(btn => btn.addEventListener('click', async () => {
        btn.disabled = true;
        btn.textContent = 'Записываем…';
        try {
            await api('selfEnroll', { group_id: btn.dataset.gid });
            toast('Вы записаны на курс');
            load(true);
            rerenderAll();
        } catch (e) {
            toast(e.message);
            btn.disabled = false;
            btn.textContent = 'Записаться';
        }
    }));
}

const scCourse = (courses) => courses.find(c => c.id === scState.active) || courses[0];

function scRenderAll(courses) { scRenderTabs(courses); scRenderHero(courses); scRenderProgram(courses); }

function scRenderTabs(courses) {
    const wrap = document.getElementById('scTabs');
    wrap.innerHTML = courses.map(c => {
        const pct = c.total ? Math.round(c.passed / c.total * 100) : 0;
        const sub = c.not_started
            ? (c.open ? 'свободное прохождение' : (c.start ? 'старт ' + fmtDayMonth(c.start) : 'скоро'))
            : `${esc(c.code)} · ${c.passed} из ${c.total} ${scPlural(c.total, ['урок', 'урока', 'уроков'])}`;
        return `<button class="sc-tab${c.id === scState.active ? ' on' : ''}" data-id="${c.id}">
            <span class="sc-chip ${chipBg(c.subject_key)}">${esc(c.abbr)}</span>
            <span class="sc-tb"><span class="sc-tname">${esc(c.title)}</span><span class="sc-tsub">${esc(sub)}</span></span>
            <span class="sc-tbar"><span class="${chipBg(c.subject_key)}" style="width:${pct}%"></span></span>
        </button>`;
    }).join('');
    wrap.querySelectorAll('.sc-tab').forEach(b => b.addEventListener('click', () => {
        scState.active = +b.dataset.id;
        scState.query = '';
        scRenderAll(courses);
    }));
}

function scRenderHero(courses) {
    const c = scCourse(courses);
    const pct = c.total ? Math.round(c.passed / c.total * 100) : 0;

    let actions;
    if (scExamLock) {
        // Контент недоступен не по дате, а потому что идёт контрольная.
        actions = `<a class="prof-btn prof-btn-primary sc-hbtn" href="${esc(scExamLock.url)}">Вернуться к контрольной</a>
            <div class="sc-hint">курс недоступен, пока идёт контрольная</div>`;
    } else if (c.not_started) {
        actions = `<span class="prof-btn sc-hbtn sc-dis">Старт ${c.start ? fmtDayMonth(c.start) : 'скоро'}</span>
            <div class="sc-hint">курс откроется после первого занятия</div>`;
    } else if (c.continue_url) {
        actions = `<a class="prof-btn prof-btn-primary sc-hbtn" href="${esc(c.continue_url)}">Продолжить · урок ${c.continue_num}</a>
            <div class="sc-hint">продолжить с текущего урока</div>`;
    } else {
        actions = `<span class="prof-btn sc-hbtn sc-dis">${pct === 100 ? 'Курс пройден' : 'Нет доступных уроков'}</span>`;
    }
    // Открытый курс (Эпик 15): вместо дат — пометка о свободном прохождении.
    const meta = [c.open ? 'Свободное прохождение — весь курс доступен сразу' : '', c.teacher, c.room]
        .filter(Boolean).map(esc).join('<span class="sc-sep">·</span>');

    document.getElementById('scHero').innerHTML = `
        <div class="sc-hero-top">
            <div class="sc-hinfo">
                <span class="sc-code ${chipSoft(c.subject_key)}">${esc(c.code)}</span>
                <div class="sc-htitle">${esc(c.title)}</div>
                ${meta ? `<div class="sc-hmeta">${meta}</div>` : ''}
                <div class="sc-hprog">
                    <div class="sc-hpl">
                        <span class="sc-hpt">Пройдено ${c.passed} из ${c.total} ${scPlural(c.total, ['урока', 'уроков', 'уроков'])}</span>
                        <span class="sc-hpct ${chipText(c.subject_key)}">${pct}%</span>
                    </div>
                    <div class="sc-hpbar"><span class="${chipBg(c.subject_key)}" style="width:${pct}%"></span></div>
                </div>
            </div>
            <div class="sc-hact">${actions}</div>
        </div>`;
}

function scRowHtml(l) {
    const clickable = 'locked' !== l.status && !!l.player_url;
    const cls = 'sc-row ' + ({ done: 'done', available: 'open', locked: 'lock' }[l.status] || 'lock') + (clickable ? ' click' : '');
    const sub = [l.date ? fmtDayMonth(l.date) : '', l.room].filter(Boolean).map(esc).join(' · ');
    return `<div class="${cls}"${clickable ? ` data-url="${esc(l.player_url)}"` : ''}>
        <span class="sc-num">${l.num}</span>
        <span class="sc-lb"><span class="sc-ltitle">${esc(l.title)}</span>${sub ? `<span class="sc-lsub">${sub}</span>` : ''}</span>
        ${clickable ? `<span class="sc-go">${l.status === 'done' ? 'Пересмотреть' : 'Открыть'} →</span>` : ''}
        ${SC_PILL[l.status] || ''}
    </div>`;
}

function scDefaultOpen(cId, mi, done, total, hasOpen) {
    const ov = (scState.expand[cId] || {})[mi];
    if (ov !== undefined) { return ov; }
    if (hasOpen) { return true; }          // модуль с текущим уроком раскрыт
    if (done === total) { return false; }  // пройденный модуль свёрнут
    if (done === 0) { return false; }      // будущий свёрнут
    return true;
}

function scRenderProgram(courses) {
    const c = scCourse(courses);
    const body = document.getElementById('scProgBody');
    const q = scState.query.trim().toLowerCase();
    const match = (l) => !q || l.title.toLowerCase().includes(q) || String(l.num) === q;

    const modCount = c.modules ? c.modules.length : 0;
    document.getElementById('scProgSub').textContent =
        `${c.total} ${scPlural(c.total, ['урок', 'урока', 'уроков'])}` +
        (modCount ? ` · ${modCount} ${scPlural(modCount, ['модуль', 'модуля', 'модулей'])}` : '');

    const searchWrap = document.getElementById('scSearchWrap');
    const expandBtn = document.getElementById('scExpand');

    // Курс ещё не начался — превью модулей без раскрытия.
    if (c.not_started && c.modules) {
        searchWrap.style.display = 'none';
        expandBtn.style.display = 'none';
        body.innerHTML = `<div class="sc-notice">Курс стартует ${c.start ? '<b>' + esc(fmtDayMonth(c.start)) + '</b>' : 'скоро'}. Уроки откроются после первого занятия.</div>` +
            c.modules.map((m, mi) => `<div class="sc-mod">
                <div class="sc-mhead static">
                    <span class="sc-mnum">Модуль ${mi + 1}</span>
                    <span class="sc-mname">${esc(m.name)}</span>
                    <span class="sc-mcnt">${m.lessons.length} ${scPlural(m.lessons.length, ['урок', 'урока', 'уроков'])}</span>
                    <span class="sc-pill lock">закрыт</span>
                </div></div>`).join('');
        return;
    }
    searchWrap.style.display = '';
    expandBtn.style.display = c.modules ? '' : 'none';

    // Плоский курс без модулей.
    if (!c.modules) {
        const rows = (c.lessons || []).filter(match);
        body.innerHTML = rows.map(scRowHtml).join('') || '<div class="sc-empty">Ничего не найдено.</div>';
        scBindRows(body);
        return;
    }

    // Модули.
    let mods = c.modules.map((m, mi) => ({
        m, mi,
        done: m.lessons.filter(l => l.status === 'done').length,
        hasOpen: m.lessons.some(l => l.status === 'available'),
        found: m.lessons.filter(match),
    }));
    if (q) { mods = mods.filter(x => x.found.length); }

    body.innerHTML = mods.map(({ m, mi, done, hasOpen, found }) => {
        const open = q ? true : scDefaultOpen(c.id, mi, done, m.lessons.length, hasOpen);
        const allDone = m.lessons.length > 0 && done === m.lessons.length;
        const rows = q ? found : m.lessons;
        const pct = m.lessons.length ? Math.round(done / m.lessons.length * 100) : 0;
        return `<div class="sc-mod${open ? ' open' : ''}${allDone ? ' done' : ''}" data-mi="${mi}">
            <div class="sc-mhead" role="button">
                <span class="sc-caret">${icoChevronRight(14)}</span>
                <span class="sc-mnum">Модуль ${mi + 1}</span>
                <span class="sc-mname">${esc(m.name)}</span>
                ${allDone ? '<span class="sc-mdone">✓</span>' : ''}
                <span class="sc-mcnt">${done} из ${m.lessons.length}</span>
                <span class="sc-mmini"><span style="width:${pct}%"></span></span>
            </div>
            <div class="sc-mbody">${rows.map(scRowHtml).join('')}</div>
        </div>`;
    }).join('') || '<div class="sc-empty">Ничего не найдено.</div>';

    body.querySelectorAll('.sc-mhead[role="button"]').forEach(h => h.addEventListener('click', () => {
        const mod = h.closest('.sc-mod');
        const mi = +mod.dataset.mi;
        const nowOpen = !mod.classList.contains('open');
        mod.classList.toggle('open', nowOpen);
        (scState.expand[c.id] = scState.expand[c.id] || {})[mi] = nowOpen;
        scSyncExpand(courses);
    }));
    scBindRows(body);
    scSyncExpand(courses);
}

function scBindRows(body) {
    body.querySelectorAll('.sc-row.click').forEach(r => r.addEventListener('click', () => {
        if (r.dataset.url) { window.location.href = r.dataset.url; }
    }));
}

function scSyncExpand(courses) {
    const c = scCourse(courses);
    const btn = document.getElementById('scExpand');
    if (!c.modules) { return; }
    const mods = [...document.querySelectorAll('#scProgBody .sc-mod')];
    const anyClosed = mods.some(m => !m.classList.contains('open'));
    btn.textContent = anyClosed ? 'Развернуть все' : 'Свернуть все';
    btn.onclick = () => {
        const exp = (scState.expand[c.id] = scState.expand[c.id] || {});
        mods.forEach(m => { exp[+m.dataset.mi] = anyClosed; });
        scRenderProgram(courses);
    };
}

/* ── Grades (дневник, сырые баллы) ────────────────────────────────────── */
function renderGrades(root, d) {
    const groups = groupGrades(d.grades || []);
    root.innerHTML = `
    <div class="prof-dash">
        ${childBar()}
        <div class="prof-dash-hello"><h1>Мои оценки</h1><p>Решённые задачи и баллы за экзамены.</p></div>
        <div class="prof-card">
            <div class="prof-card-head"><h3>Работы и контрольные</h3><span class="ch-sub">${groups.length}</span></div>
            <div>${groups.length ? groups.map(gradeGroupHtml).join('') : empty('Оценок пока нет.')}</div>
        </div>
    </div>`;
    wireChild(root);
    // Аккордеон попыток: клик по строке разворачивает прошлые попытки.
    root.querySelectorAll('[data-grade-toggle]').forEach((row) => {
        row.addEventListener('click', () => {
            const more = row.parentElement.querySelector('.prof-grade-more');
            if (more) { more.hidden = !more.hidden; row.classList.toggle('open'); }
        });
    });
}

/** Группировка оценок по работе/контрольной (source_type:source_id); попытки — последняя первой. */
function groupGrades(grades) {
    const map = new Map();
    grades.forEach((g) => {
        const key = g.group_key || g.title;
        if (!map.has(key)) { map.set(key, { title: g.title, type: g.type || '', group_name: g.group_name || '', attempts: [] }); }
        map.get(key).attempts.push(g);
    });
    const groups = [...map.values()];
    const byDateDesc = (a, b) => String(b.graded_at || '').localeCompare(String(a.graded_at || ''));
    groups.forEach((gr) => gr.attempts.sort(byDateDesc));
    groups.sort((a, b) => byDateDesc(a.attempts[0] || {}, b.attempts[0] || {}));
    return groups;
}

function gradeGroupHtml(gr) {
    const latest     = gr.attempts[0];
    const more       = gr.attempts.slice(1);
    const expandable = more.length > 0;
    const pending    = latest.display === 'pending';
    const typeTag    = gr.type ? `<span class="prof-type-tag">${esc(gr.type)}</span>` : '';
    const cnt        = expandable ? ` · попыток: ${gr.attempts.length}` : '';
    const sub        = [ esc(gr.group_name), fmtDateTime(latest.graded_at) ].filter(Boolean).join(' · ') + cnt;

    const main = `<div class="prof-work-item${expandable ? ' is-clickable' : ''}"${expandable ? ' data-grade-toggle' : ''}>
        <div class="prof-work-ico grade">${icoStar(18)}</div>
        <div class="prof-work-main"><div class="prof-work-title">${esc(gr.title)}${typeTag}</div><div class="prof-work-sub">${sub}</div></div>
        <span class="prof-work-count${pending ? ' prof-work-count--pending' : ''}">${esc(latest.value)}</span>
        ${expandable ? `<span class="prof-grade-chev">${icoChevronDown(16)}</span>` : ''}
    </div>`;

    const moreHtml = expandable
        ? `<div class="prof-grade-more" hidden>${more.map((a, i) => gradeAttemptRow(a, gr.attempts.length - 1 - i)).join('')}</div>`
        : '';

    return `<div class="prof-grade-group">${main}${moreHtml}</div>`;
}

/** Строка прошлой попытки в аккордеоне. n — номер попытки (1 = самая ранняя). */
function gradeAttemptRow(a, n) {
    const pending = a.display === 'pending';
    return `<div class="prof-grade-attempt">
        <span class="prof-grade-att-label">Попытка ${n}${a.graded_at ? ' · ' + esc(fmtDateTime(a.graded_at)) : ''}</span>
        <span class="prof-work-count${pending ? ' prof-work-count--pending' : ''}">${esc(a.value)}</span>
    </div>`;
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
/** #14: пометка аудитории «· Название» (пусто, если аудитория не задана). */
function roomTag(l) {
    return l.room ? ` · ${esc(l.room)}` : '';
}

/** Имя преподавателя занятия (полностью, с учётом замены — Эпик 5). */
function teacherTag(l) {
    return l.teacher ? ` · ${esc(l.teacher)}` : '';
}

function schedRow(l) {
    const inner = `
        <div class="prof-lesson-time"><div class="lt-start">${esc(l.start || '')}</div><div class="lt-end">${fmtDayMonth(l.date)}</div></div>
        <div class="prof-lesson-bar"></div>
        <div class="prof-lesson-body">
            <div class="prof-lesson-grp">${esc(l.group_name)}${l.kind === 'individual' ? ' <span class="prof-sub-tag indi">инд.</span>' : ''}</div>
            <div class="prof-lesson-topic">${esc(l.topic || '—')}${roomTag(l)}${teacherTag(l)}</div>
        </div>`;
    // #3: клик по занятию в расписании ведёт прямо в плеер курса — даже если урок
    // ещё заблокирован по времени (там ученика встретит экран ожидания + таймер).
    // Занятие без контента (нет player_url) остаётся некликабельной строкой.
    if (l.player_url) {
        return `<a class="prof-lesson-row prof-lesson-go" href="${esc(l.player_url)}">${inner}</a>`;
    }
    return `<div class="prof-lesson-row">${inner}</div>`;
}

// Баннер «идёт контрольная»: весь контент кабинета недоступен, пока активна
// запирающая попытка (ExamLockService). Явно объясняет причину (а не «по дате»)
// и ведёт обратно на контрольную, чтобы её завершить.
function examLockBanner(d) {
    if (!d.exam_lock) { return ''; }
    return `<a class="prof-exam-lock" href="${esc(d.exam_lock.url)}">
        <span class="prof-exam-lock__ico">${icoLock(20)}</span>
        <span class="prof-exam-lock__body">
            <span class="prof-exam-lock__title">Идёт контрольная «${esc(d.exam_lock.title)}»</span>
            <span class="prof-exam-lock__sub">Курс недоступен, пока вы её не завершите. Нажмите, чтобы вернуться к работе.</span>
        </span>
        ${icoChevronRight(18)}
    </a>`;
}

function dlRow(d) {
    // T12.2 (D13): прошедший дедлайн не скрываем — решать можно, помечаем «Просрочено».
    // Части соединяем через « · » с фильтром пустых — иначе при пустом названии
    // группы строка начиналась с висячей точки «· …».
    const when = d.overdue
        ? `<span class="prof-dl-overdue">Просрочено</span> ${fmtDateTime(d.due_at)}`
        : `до ${fmtDateTime(d.due_at)}`;
    const sub = [ esc(d.group_name), when ].filter(Boolean).join(' · ');
    const inner = `
        <div class="prof-work-ico att">${icoClock(18)}</div>
        <div class="prof-work-main"><div class="prof-work-title">${esc(d.topic || 'Домашнее задание')}</div><div class="prof-work-sub">${sub}</div></div>`;
    // Bug 2: клик по дедлайну ведёт прямо к нужной работе в плеере урока
    // (?step=<ключ>); без player_url (урок без контента) — некликабельная строка.
    if (d.player_url) {
        return `<a class="prof-work-item prof-lesson-go is-clickable${d.overdue ? ' overdue' : ''}" href="${esc(d.player_url)}">${inner}</a>`;
    }
    return `<div class="prof-work-item${d.overdue ? ' overdue' : ''}">${inner}</div>`;
}

function gradeRow(g) {
    // Дата + время сдачи (ДД.ММ.ГГГГ ЧЧ:ММ).
    const sub = [ esc(g.group_name), fmtDateTime(g.graded_at) ].filter(Boolean).join(' · ');
    return `<div class="prof-work-item">
        <div class="prof-work-ico grade">${icoStar(18)}</div>
        <div class="prof-work-main"><div class="prof-work-title">${esc(g.title)}</div><div class="prof-work-sub">${sub}</div></div>
        <span class="prof-work-count">${esc(g.value)}</span>
    </div>`;
}


function attRow(r) {
    return `<div class="prof-work-item">
        <div class="prof-work-main"><div class="prof-work-title">${esc(r.topic || '—')}</div><div class="prof-work-sub">${fmtDayMonth(r.date)}</div></div>
        <span class="prof-att-mark ${r.present ? 'p' : 'a'}">${r.present ? 'Был' : 'Н'}</span>
    </div>`;
}

function homeTile(label, val, color, ico) {
    const icons = { cal: icoCalendar, check: icoCheck, alert: icoAlert };
    return `<div class="prof-stat-tile">
        <div class="st-top"><span class="st-ico" style="background:${color}1a;color:${color}">${icons[ico](16)}</span>${esc(label)}</div>
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
function fmtDateTime(s) { if (!s) return ''; return fmtDate(s) + ' ' + String(s).slice(11, 16); }
function empty(t) { return `<div class="rev-empty">${esc(t)}</div>`; }
function emptyCard(t) { return `<div class="prof-card"><div class="prof-card-empty">${esc(t)}</div></div>`; }

function emptyHtml(title, text) {
    return emptyState('prof-dash', icoHome(34), title, text);
}
