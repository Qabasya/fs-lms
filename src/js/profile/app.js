import { esc, shortName, chipBg, closeGradePop, closeCtxMenu, openCtxMenuRaw } from './utils.js';
import { renderDashboard } from './dashboard.js';
import { renderJournal, setJournalGroup } from './journal.js';
import { renderGroups, setGroupsGroup } from './groups.js';
import { renderSummary } from './summary.js';
import { renderSubstitutions } from './substitutions.js';
import { renderKTP } from './ktp.js';
import { renderLearnerHome, renderLearnerLessons, renderLearnerGrades, renderLearnerAttendance } from './learner.js';

/* ── Screen registry: key → renderer ─────────────────────────────────── */
const SCREENS = {
    dashboard:            (root) => renderDashboard(root, { openJournalFor, openReview: () => go('summary') }),
    groups:               (root) => renderGroups(root, { openJournal: openJournalFor }),
    journal:              (root) => renderJournal(root),
    summary:              (root) => renderSummary(root),
    substitutions:        (root) => renderSubstitutions(root),
    ktp:                  (root) => renderKTP(root),
    'learner-home':       renderLearnerHome,
    'learner-lessons':    renderLearnerLessons,
    'learner-grades':     renderLearnerGrades,
    'learner-attendance': renderLearnerAttendance,
};

const TOPBAR = {
    dashboard:            { crumb: 'Личный кабинет',   title: 'Главная' },
    groups:               { crumb: 'Группы',           title: 'Группы' },
    journal:              { crumb: 'Журнал',           title: 'Журнал' },
    summary:              { crumb: 'Успеваемость',      title: 'Сводка по ученику' },
    substitutions:        { crumb: 'Офис',             title: 'Замены' },
    ktp:                  { crumb: 'Планирование',     title: 'КТП и расписание' },
    'learner-home':       { crumb: 'Личный кабинет',   title: 'Главная' },
    'learner-lessons':    { crumb: 'Обучение',         title: 'Мои курсы' },
    'learner-grades':     { crumb: 'Успеваемость',     title: 'Мои оценки' },
    'learner-attendance': { crumb: 'Успеваемость',     title: 'Посещаемость' },
};

const NAV_ICONS = {
    dashboard:            '<path d="M3 9.5 10 4l7 5.5M5 8.5V16h10V8.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>',
    groups:               '<circle cx="7.5" cy="7" r="2.5" stroke="currentColor" stroke-width="1.6"/><path d="M3 16c0-2.5 2-4.5 4.5-4.5S12 13.5 12 16M13 5.2a2.5 2.5 0 0 1 0 4.6M17 16c0-2-1.2-3.7-3-4.3" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>',
    journal:              '<rect x="3" y="3.5" width="14" height="13" rx="2" stroke="currentColor" stroke-width="1.6"/><path d="M3 7.5h14M8 7.5v9" stroke="currentColor" stroke-width="1.6"/>',
    summary:              '<path d="M5 3h8l3 3v11H5z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><path d="M7.5 10l2 2 4-4.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>',
    substitutions:        '<path d="M4 7h9m0 0-3-3m3 3-3 3M16 13H7m0 0 3-3m-3 3 3 3" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>',
    ktp:                  '<rect x="3" y="4" width="14" height="13" rx="2" stroke="currentColor" stroke-width="1.6"/><path d="M3 8h14M7 2.5v3M13 2.5v3" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>',
    'learner-home':       '<path d="M3 9.5 10 4l7 5.5M5 8.5V16h10V8.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>',
    'learner-lessons':    '<path d="M4 4h7v12H4zM11 4h5v12h-5" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/>',
    'learner-grades':     '<path d="M10 3 12 7l4.5.6-3.3 3.2.8 4.5L10 13.2 6 15.5l.8-4.5L3.5 7.7 8 7z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/>',
    'learner-attendance': '<rect x="3" y="4" width="14" height="13" rx="2" stroke="currentColor" stroke-width="1.6"/><path d="M3 8h14M7 2.5v3M13 2.5v3" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>',
};

const ROLE_LABELS = {
    lms_teacher:       'Преподаватель',
    lms_office:        'Администратор',
    lms_student:       'Ученик',
    lms_student_free:  'Ученик',
    lms_parent:        'Родитель',
};

let cfg;

/* #15-C: свёрнутость секций сайдбара + фильтр «Мои курсы» (module-level, как
   mod.collapsed в course-builder.js — флаг переживает re-render buildSidebar()). */
const sidebarState = { groupsCollapsed: false, coursesCollapsed: false, courseFilter: '' };
const COURSE_SEARCH_THRESHOLD = 6;

/* ── Routing ─────────────────────────────────────────────────────────── */
function go(screen) {
    document.querySelectorAll('.prof-screen').forEach(s =>
        s.classList.toggle('active', s.dataset.screen === screen));
    document.querySelectorAll('.prof-nav-item[data-go]').forEach(n =>
        n.classList.toggle('active', n.dataset.go === screen));
    setTopbar(screen);
    const act = document.querySelector(`.prof-screen[data-screen="${screen}"]`);
    if (act) act.scrollTop = 0;
}

function setTopbar(screen, override) {
    const t = override || TOPBAR[screen] || { crumb: 'Личный кабинет', title: '' };
    const crumb = document.getElementById('profTbCrumb');
    const title = document.getElementById('profTbTitle');
    if (crumb) crumb.textContent = t.crumb;
    if (title) title.textContent = t.title;
}

function openJournalFor(gid) {
    const groups = cfg.groups || [];
    const g = groups.find(x => String(x.id) === String(gid)) || groups[0];
    document.querySelectorAll('.prof-group-item').forEach(el =>
        el.classList.toggle('active', String(el.dataset.grp) === String(gid)));
    go('journal');
    setTopbar('journal', { crumb: 'Журнал', title: g ? `${g.name} · ${g.subject}` : 'Журнал' });
    if (g) setJournalGroup(g.id);
}

/* D10: клик по группе в сайдбаре открывает экран «Группы» (ростер), не журнал. */
function openGroupsFor(gid) {
    const groups = cfg.groups || [];
    const g = groups.find(x => String(x.id) === String(gid)) || groups[0];
    document.querySelectorAll('.prof-group-item').forEach(el =>
        el.classList.toggle('active', String(el.dataset.grp) === String(gid)));
    go('groups');
    setTopbar('groups', { crumb: 'Группы', title: g ? `${g.name} · ${g.subject}` : 'Группы' });
    if (g) setGroupsGroup(g.id);
}

function wireCourseItems() {
    document.querySelectorAll('.prof-course-item').forEach(el =>
        el.addEventListener('click', () => openCoursePreview(el.dataset.course, el.dataset.lesson)));
}

/* #15-B: клик по курсу в сайдбаре открывает preview-плеер курса (первый урок). */
function openCoursePreview(courseId, lessonId) {
    if (!cfg.coursePreviewUrl) return;
    const url = new URL(cfg.coursePreviewUrl, window.location.origin);
    url.searchParams.set('course', courseId);
    if (lessonId && Number(lessonId) > 0) { url.searchParams.set('lesson', lessonId); }
    window.location.href = url.toString();
}

/* #15-C: заголовок секции сайдбара со стрелкой сворачивания. */
function sectionHeader(label, stateKey) {
    const collapsed = sidebarState[stateKey];
    return `<div class="prof-nav-label prof-nav-label--toggle" data-toggle-section="${stateKey}">
        ${esc(label)}
        <span class="pnl-caret${collapsed ? ' collapsed' : ''}">
            <svg width="10" height="10" viewBox="0 0 12 12"><path fill="currentColor" d="M3 4.5 6 8l3-3.5z"/></svg>
        </span>
    </div>`;
}

function filteredCourses() {
    const q = sidebarState.courseFilter.trim().toLowerCase();
    const list = cfg.coursesTaught || [];
    if (!q) { return list; }
    return list.filter(c => c.title.toLowerCase().includes(q));
}

function courseItemsHtml() {
    const list = filteredCourses();
    if (!list.length) { return '<div class="prof-side-empty">Ничего не найдено.</div>'; }
    return list.map(c => `
        <div class="prof-course-item" data-course="${c.id}" data-lesson="${c.first_lesson_id || ''}">
            <span class="prof-group-chip ${chipBg(c.subject_key)}">${esc(shortName(c.title))}</span>
            <div class="prof-group-meta">
                <div class="prof-group-name">${esc(c.title)}</div>
            </div>
        </div>`).join('');
}

/* ── Sidebar (built from role config) ────────────────────────────────── */
function buildSidebar() {
    const nav = document.getElementById('profNav');

    let html = '<div class="prof-nav-label">Меню</div>';
    html += cfg.nav.map(item => `
        <div class="prof-nav-item" data-go="${esc(item.key)}">
            <span class="ni-ico"><svg width="19" height="19" viewBox="0 0 20 20" fill="none">${NAV_ICONS[item.key] || ''}</svg></span>
            ${esc(item.label)}
        </div>`).join('');

    if (cfg.groups && cfg.groups.length) {
        html += sectionHeader('Мои группы', 'groupsCollapsed');
        if (!sidebarState.groupsCollapsed) {
            html += cfg.groups.map(g => `
                <div class="prof-group-item" data-grp="${g.id}">
                    <span class="prof-group-chip ${chipBg(g.subject_key || g.subject)}">${esc(shortName(g.name))}</span>
                    <div class="prof-group-meta">
                        <div class="prof-group-name">${esc(g.name)}</div>
                        <div class="prof-group-sub">${esc(g.subject)}</div>
                    </div>
                </div>`).join('');
        }
    }

    if (cfg.coursesTaught && cfg.coursesTaught.length) {
        html += sectionHeader('Мои курсы', 'coursesCollapsed');
        if (!sidebarState.coursesCollapsed) {
            if (cfg.coursesTaught.length > COURSE_SEARCH_THRESHOLD) {
                html += `<div class="prof-side-search">
                    <input type="text" id="profCourseFilter" placeholder="Поиск курса…" value="${esc(sidebarState.courseFilter)}">
                </div>`;
            }
            html += `<div id="profCoursesList">${courseItemsHtml()}</div>`;
        }
    }
    if (nav) nav.innerHTML = html;

    const user = document.getElementById('profUser');
    if (user) {
        const u = cfg.user || { name: '', initials: '' };
        // Роль показываем всем, кроме ученика/родителя (им — только имя).
        const hideRole = ['lms_student', 'lms_student_free', 'lms_parent'].includes(cfg.role);
        const roleLabel = hideRole ? '' : (ROLE_LABELS[cfg.role] || 'Пользователь');
        user.innerHTML = `
            <div class="prof-avatar">${esc(u.initials)}</div>
            <div class="su-meta">
                <div class="su-name">${esc(u.name)}</div>
                ${roleLabel ? `<div class="su-role">${esc(roleLabel)}</div>` : ''}
            </div>
            <button class="prof-icon-ghost" id="profUserGear" title="Настройки">
                <svg width="18" height="18" viewBox="0 0 20 20" fill="none"><path fill="currentColor" fill-rule="evenodd" clip-rule="evenodd" d="M8.94 2.5a1 1 0 0 0-.98.8l-.24 1.19a5.6 5.6 0 0 0-1.28.74l-1.15-.4a1 1 0 0 0-1.19.45L3.04 7.11a1 1 0 0 0 .2 1.25l.9.79a5.7 5.7 0 0 0 0 1.5l-.9.79a1 1 0 0 0-.2 1.25l1.06 1.84a1 1 0 0 0 1.19.45l1.15-.4c.39.3.82.55 1.28.74l.24 1.19a1 1 0 0 0 .98.8h2.12a1 1 0 0 0 .98-.8l.24-1.19c.46-.19.89-.44 1.28-.74l1.15.4a1 1 0 0 0 1.19-.45l1.06-1.84a1 1 0 0 0-.2-1.25l-.9-.79a5.7 5.7 0 0 0 0-1.5l.9-.79a1 1 0 0 0 .2-1.25l-1.06-1.84a1 1 0 0 0-1.19-.45l-1.15.4a5.6 5.6 0 0 0-1.28-.74l-.24-1.19a1 1 0 0 0-.98-.8H8.94zM10 12.8a2.8 2.8 0 1 1 0-5.6 2.8 2.8 0 0 1 0 5.6z"/></svg>
            </button>`;
    }
}

/* ── Stage (sections + screen mount) ─────────────────────────────────── */
function buildStage() {
    const stage = document.getElementById('profStage');
    if (!stage) return;
    stage.innerHTML = cfg.screens.map((key, i) =>
        `<section class="prof-screen ${i === 0 ? 'active' : ''}" data-screen="${esc(key)}"></section>`).join('');
}

function mountScreens() {
    cfg.screens.forEach(key => {
        const root = document.querySelector(`.prof-screen[data-screen="${key}"]`);
        const render = SCREENS[key];
        if (root && render) render(root);
    });
}

/* ── Меню пользователя (шестерёнка) — dropdown вверх с «Выход» ────────── */
function openUserMenu(anchor) {
    const html = `
        <div class="ctx-item danger" data-user-act="logout">
            <svg width="16" height="16" viewBox="0 0 20 20" fill="none"><path d="M8 5V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v12a1 1 0 0 1-1 1H9a1 1 0 0 1-1-1v-1M11 10H3m0 0 3-3m-3 3 3 3" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
            Выход
        </div>`;
    openCtxMenuRaw(html, anchor, null, { up: true });
    const menu = document.getElementById('profCtxMenu');
    if (!menu) return;
    const item = menu.querySelector('[data-user-act="logout"]');
    if (item) item.addEventListener('click', () => {
        closeCtxMenu();
        const url = cfg.logoutUrl || (window.fsProfile && window.fsProfile.logoutUrl);
        if (url) window.location.href = url;
    });
}

/* ── Wiring ──────────────────────────────────────────────────────────── */
function wire() {
    document.querySelectorAll('.prof-nav-item[data-go]').forEach(n =>
        n.addEventListener('click', () => go(n.dataset.go)));
    document.querySelectorAll('.prof-group-item').forEach(el =>
        el.addEventListener('click', () => openGroupsFor(el.dataset.grp)));
    wireCourseItems();

    document.querySelectorAll('[data-toggle-section]').forEach(el =>
        el.addEventListener('click', () => {
            sidebarState[el.dataset.toggleSection] = !sidebarState[el.dataset.toggleSection];
            buildSidebar();
            wire();
        }));

    const courseFilter = document.getElementById('profCourseFilter');
    if (courseFilter) {
        courseFilter.addEventListener('input', () => {
            sidebarState.courseFilter = courseFilter.value;
            // Обновляем только список курсов — иначе поиск теряет фокус на каждый символ.
            const list = document.getElementById('profCoursesList');
            if (list) { list.innerHTML = courseItemsHtml(); wireCourseItems(); }
        });
    }

    const gear = document.getElementById('profUserGear');
    if (gear) gear.addEventListener('click', () => openUserMenu(gear));

    const backdrop = document.getElementById('profCtxBackdrop');
    if (backdrop) backdrop.addEventListener('click', () => { closeGradePop(); closeCtxMenu(); });
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') { closeGradePop(); closeCtxMenu(); }
    });
}

function defaultConfig() {
    // Fallback для standalone-предпросмотра дизайна без бэкенда.
    return {
        role: 'lms_teacher',
        readOnly: false,
        user: { name: 'Преподаватель', initials: 'ПР' },
        nav: [
            { key: 'dashboard', label: 'Главная' },
            { key: 'journal', label: 'Журнал' },
            { key: 'ktp', label: 'КТП и расписание' },
        ],
        screens: ['dashboard', 'journal', 'ktp'],
    };
}

/* ── Сворачивание сайдбара (зеркало плеера: body-класс + localStorage) ── */
const MENU_KEY = 'fsProfileMenuOff';

function initCollapse() {
    let off = false;
    try { off = '1' === localStorage.getItem(MENU_KEY); } catch { /* private mode */ }
    document.body.classList.toggle('prof-menu-off', off);

    // Анимацию включаем после первичной отрисовки — без «проигрывания» на загрузке.
    requestAnimationFrame(() =>
        requestAnimationFrame(() => document.body.classList.add('prof-anim')));

    const toggle = () => {
        const now = !document.body.classList.contains('prof-menu-off');
        document.body.classList.toggle('prof-menu-off', now);
        try { localStorage.setItem(MENU_KEY, now ? '1' : '0'); } catch { /* private mode */ }
    };
    document.getElementById('profCollapse')?.addEventListener('click', toggle);
    document.getElementById('profMenuOn')?.addEventListener('click', toggle);
}

export function initProfile() {
    cfg = window.fsProfile || defaultConfig();
    if (!cfg.screens || !cfg.screens.length) cfg = defaultConfig();

    buildSidebar();
    buildStage();
    mountScreens();
    wire();
    initCollapse();

    // Deep-link на экран: /profile/?screen=learner-lessons (ссылки из плеера курса, T14.13).
    // С gid (?screen=groups&gid=2) — открыть «Группы» на конкретной группе, как клик
    // по группе в сайдбаре (ссылки из сайдбара предпросмотра курса).
    const params = new URLSearchParams(window.location.search);
    const wanted = params.get('screen');
    const gid = params.get('gid');
    if (gid && cfg.screens.includes('groups')) {
        openGroupsFor(gid);
    } else {
        go(wanted && cfg.screens.includes(wanted) ? wanted : cfg.screens[0]);
    }
}
