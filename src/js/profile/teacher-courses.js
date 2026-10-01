/* ══════════════════════════════════════════════════════════════════════
   Страница курса преподавателя/методиста/админа (Tasks.md З4): та же
   «Программа курса», что у ученика в «Моих курсах», но без прогресса —
   модули → уроки, поиск, «Развернуть всё». Курсы — вкладки sc-tabs
   (карусель в один ряд, course-tabs.js). «Открыть»: автору курсов (методист,
   офис, админ) — плеер в режиме предпросмотра, рядовому преподавателю —
   урок его группы в режиме преподавателя (если в группе он заведён).
   Вход — клик по курсу в сайдбаре кабинета.
   ══════════════════════════════════════════════════════════════════════ */

import { esc, chipBg, shortName } from './utils.js';
import { icoSearch, icoChevronRight } from '../common/icons.js';
import { createApi } from './api.js';
import { teacherLayout, moreHtml, bindMore } from './program-window.js';
import { courseTabsShell, syncCourseTabs } from './course-tabs.js';

let api = null;
let root = null;
const programs = new Map(); // courseId → Promise<{modules, canPreview}>
const state = { active: 0, query: '', expand: {} };

function cfg() { return window.fsProfile || {}; }
function courses() { return cfg().coursesTaught || []; }

function plural(n, forms) {
    const a = n % 10, b = n % 100;
    if (a === 1 && b !== 11) { return forms[0]; }
    if (a >= 2 && a <= 4 && (b < 12 || b > 14)) { return forms[1]; }
    return forms[2];
}

export function renderTeacherCourses(screenRoot) {
    root = screenRoot;
    const list = courses();
    if (!list.length) {
        root.innerHTML = '<div class="prof-dash"><div class="prof-card"><div class="rev-empty">Курсов пока нет.</div></div></div>';
        return;
    }

    const wanted = Number(new URLSearchParams(window.location.search).get('course'));
    state.active = list.some(c => c.id === wanted) ? wanted : list[0].id;

    root.innerHTML = `
    <div class="prof-dash sc">
        <div class="prof-dash-hello">
            <h1>Мои курсы</h1>
            <p>${list.length} ${plural(list.length, ['курс', 'курса', 'курсов'])}</p>
        </div>
        ${courseTabsShell('tcTabs')}
        <div class="prof-card">
            <div class="prof-card-head sc-prog-head">
                <div><h3 id="tcTitle"></h3><span class="ch-sub" id="tcSub"></span></div>
                <div class="sc-search">
                    ${icoSearch(14)}
                    <input type="text" id="tcSearch" placeholder="Поиск по урокам">
                </div>
                <button type="button" class="prof-btn prof-btn-sm" id="tcExpand">Развернуть всё</button>
            </div>
            <div id="tcBody"><div class="rev-loading">Загрузка…</div></div>
        </div>
    </div>`;

    root.querySelector('#tcSearch').addEventListener('input', (e) => {
        state.query = e.target.value;
        renderProgram();
    });
    root.querySelector('#tcExpand').addEventListener('click', toggleAll);

    // Программа грузится, только когда страницу открыли (openTeacherCourse):
    // экран монтируется вместе с кабинетом, а запрос на каждую загрузку не нужен.
    renderTabs();
}

/** Открыть страницу на конкретном курсе (клик по курсу в сайдбаре). */
export function openTeacherCourse(courseId) {
    if (!root) { return; }
    state.active = Number(courseId);
    state.query = '';
    const search = root.querySelector('#tcSearch');
    if (search) { search.value = ''; }
    renderTabs();
    renderProgram();
}

function renderTabs() {
    const wrap = root.querySelector('#tcTabs');
    wrap.innerHTML = courses().map(c => `
        <button type="button" class="sc-tab${c.id === state.active ? ' on' : ''}" data-id="${c.id}">
            <span class="sc-chip ${chipBg(c.subject_key)}">${esc(shortName(c.title))}</span>
            <span class="sc-tb"><span class="sc-tname">${esc(c.title)}</span></span>
        </button>`).join('');
    wrap.querySelectorAll('.sc-tab').forEach(b => b.addEventListener('click', () => openTeacherCourse(b.dataset.id)));
    syncCourseTabs(wrap);
}

function loadProgram(courseId) {
    if (!programs.has(courseId)) {
        if (!api) { api = createApi(cfg().taughtCourses); }
        const p = api('getProgram', { course_id: courseId })
            .then(d => ({ modules: d.modules || [], canPreview: !!d.can_preview }));
        p.catch(() => programs.delete(courseId)); // сбой — следующий показ попробует снова
        programs.set(courseId, p);
    }
    return programs.get(courseId);
}

async function renderProgram() {
    const courseId = state.active;
    const course = courses().find(c => c.id === courseId);
    const body = root.querySelector('#tcBody');
    root.querySelector('#tcTitle').textContent = course ? course.title : 'Программа курса';

    let modules, canPreview;
    try {
        ({ modules, canPreview } = await loadProgram(courseId));
    } catch (e) {
        body.innerHTML = `<div class="sc-empty">${esc(e.message)}</div>`;
        return;
    }
    if (courseId !== state.active) { return; } // пока грузили, выбрали другой курс

    const total = modules.reduce((n, m) => n + m.lessons.length, 0);
    root.querySelector('#tcSub').textContent = `${total} ${plural(total, ['урок', 'урока', 'уроков'])}`
        + (modules.length ? ` · ${modules.length} ${plural(modules.length, ['модуль', 'модуля', 'модулей'])}` : '');

    const q = state.query.trim().toLowerCase();
    const match = (l) => !q || l.title.toLowerCase().includes(q) || String(l.num) === q;
    const expand = state.expand[courseId] || {};

    // Новые уроки сверху; от последнего проведённого (по дате, в любой группе)
    // видны два ближайших, остальные будущие — под заглушкой «Ещё N уроков впереди».
    const rowOf = (l) => rowHtml(courseId, l, canPreview);
    const html = teacherLayout(modules).map(({ m, mi, rows: windowRows, hidden, open: defaultOpen }) => {
        const found = q ? m.lessons.filter(match).reverse() : windowRows;
        if (q && !found.length) { return ''; }
        const open = q ? true : (expand[mi] ?? defaultOpen);
        return `<div class="sc-mod${open ? ' open' : ''}" data-mi="${mi}">
            <div class="sc-mhead" role="button">
                <span class="sc-caret">${icoChevronRight(14)}</span>
                <span class="sc-mnum">Модуль ${mi + 1}</span>
                <span class="sc-mname">${esc(m.title)}</span>
                <span class="sc-mcnt">${m.lessons.length} ${plural(m.lessons.length, ['урок', 'урока', 'уроков'])}</span>
            </div>
            <div class="sc-mbody prof-fold"><div class="prof-fold-inner">${q ? '' : moreHtml(hidden, rowOf)}${found.map(rowOf).join('')}</div></div>
        </div>`;
    }).join('');

    body.innerHTML = html || '<div class="sc-empty">Ничего не найдено.</div>';

    body.querySelectorAll('.sc-mhead[role="button"]').forEach(h => h.addEventListener('click', () => {
        const mod = h.closest('.sc-mod');
        const nowOpen = !mod.classList.contains('open');
        mod.classList.toggle('open', nowOpen);
        (state.expand[courseId] = state.expand[courseId] || {})[+mod.dataset.mi] = nowOpen;
        syncExpandBtn();
    }));
    body.querySelectorAll('.sc-row.click').forEach(r => r.addEventListener('click', () => {
        window.location.href = r.dataset.url;
    }));
    bindMore(body);
    syncExpandBtn();
}

function lessonUrl(courseId, lessonId) {
    const url = new URL(cfg().coursePreviewUrl, window.location.origin);
    url.searchParams.set('course', courseId);
    url.searchParams.set('lesson', lessonId);
    return url.toString();
}

function rowHtml(courseId, l, canPreview) {
    const url = canPreview ? lessonUrl(courseId, l.id) : l.teacher_url;
    const title = `<span class="sc-lb"><span class="sc-ltitle">${esc(l.title || 'Без названия')}</span></span>`;
    if (!url) {
        return `<div class="sc-row open"><span class="sc-num">${l.num}</span>${title}</div>`;
    }
    return `<div class="sc-row open click" data-url="${esc(url)}">
        <span class="sc-num">${l.num}</span>
        ${title}
        <span class="sc-go">Открыть →</span>
    </div>`;
}

function toggleAll() {
    const mods = [...root.querySelectorAll('#tcBody .sc-mod')];
    const openAll = mods.some(m => !m.classList.contains('open'));
    const expand = state.expand[state.active] = {};
    mods.forEach(m => {
        m.classList.toggle('open', openAll);
        expand[+m.dataset.mi] = openAll;
    });
    syncExpandBtn();
}

function syncExpandBtn() {
    const mods = [...root.querySelectorAll('#tcBody .sc-mod')];
    const btn = root.querySelector('#tcExpand');
    btn.hidden = !mods.length;
    btn.textContent = mods.some(m => !m.classList.contains('open')) ? 'Развернуть всё' : 'Свернуть всё';
}
