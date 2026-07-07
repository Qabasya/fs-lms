import { esc, shortName, chipBg, closeGradePop, closeCtxMenu, openCtxMenuRaw } from './utils.js';
import { icoHome, icoUsers, icoJournal, icoDocCheck, icoSwap, icoCalendarBoard, icoBook, icoStar, icoCaret, icoGear, icoLogout } from '../common/icons.js';
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
    dashboard:            icoHome(19),
    groups:               icoUsers(19),
    journal:              icoJournal(19),
    summary:              icoDocCheck(19),
    substitutions:        icoSwap(19),
    ktp:                  icoCalendarBoard(19),
    'learner-home':       icoHome(19),
    'learner-lessons':    icoBook(19),
    'learner-grades':     icoStar(19),
    'learner-attendance': icoCalendarBoard(19),
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
/* На мобильном сайдбар — off-canvas поверх контента (_layout.scss):
   после любого перехода закрываем его, иначе он заслоняет выбранный экран.
   Только body-класс, localStorage не трогаем (это навигация, не выбор юзера). */
function closeMenuOnMobile() {
    if (window.matchMedia('(max-width: 720px)').matches) {
        document.body.classList.add('prof-menu-off');
    }
}

function go(screen) {
    document.querySelectorAll('.prof-screen').forEach(s =>
        s.classList.toggle('active', s.dataset.screen === screen));
    document.querySelectorAll('.prof-nav-item[data-go]').forEach(n =>
        n.classList.toggle('active', n.dataset.go === screen));
    setTopbar(screen);
    closeMenuOnMobile();
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
            ${icoCaret(10)}
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
            <span class="ni-ico">${NAV_ICONS[item.key] || ''}</span>
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
                ${icoGear(18)}
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
            ${icoLogout(16)}
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
    // На мобильном (сайдбар — off-canvas, см. _layout.scss) без сохранённого
    // выбора меню по умолчанию скрыто, на десктопе — открыто.
    let off = window.matchMedia('(max-width: 720px)').matches;
    try {
        const stored = localStorage.getItem(MENU_KEY);
        if (stored !== null) { off = '1' === stored; }
    } catch { /* private mode */ }
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
