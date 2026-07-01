import { esc, toast, closeGradePop, closeCtxMenu } from './utils.js';
import { renderDashboard } from './dashboard.js';
import { renderJournal, setJournalGroup } from './journal.js';
import { renderKTP } from './ktp.js';
import { renderLearnerHome, renderLearnerLessons, renderLearnerGrades, renderLearnerAttendance } from './learner.js';

const GROUP_COLORS = ['#3b5bdb','#0ca678','#7048e8','#f08c00','#e8590c','#1c7ed6','#e64980','#2f9e44'];
function shortName(name) { return String(name).replace(/[«»]/g, '').replace(/\s+/g, ' ').trim().slice(0, 4); }

/* ── Screen registry: key → renderer ─────────────────────────────────── */
const SCREENS = {
    dashboard:            (root) => renderDashboard(root, { openJournalFor }),
    journal:              (root) => renderJournal(root),
    ktp:                  (root) => renderKTP(root),
    'learner-home':       renderLearnerHome,
    'learner-lessons':    renderLearnerLessons,
    'learner-grades':     renderLearnerGrades,
    'learner-attendance': renderLearnerAttendance,
};

const TOPBAR = {
    dashboard:            { crumb: 'Личный кабинет',   title: 'Главная' },
    journal:              { crumb: 'Журнал',           title: 'Журнал' },
    ktp:                  { crumb: 'Планирование',     title: 'КТП и расписание' },
    'learner-home':       { crumb: 'Личный кабинет',   title: 'Главная' },
    'learner-lessons':    { crumb: 'Обучение',         title: 'Мои курсы' },
    'learner-grades':     { crumb: 'Успеваемость',     title: 'Мои оценки' },
    'learner-attendance': { crumb: 'Успеваемость',     title: 'Посещаемость' },
};

const NAV_ICONS = {
    dashboard:            '<path d="M3 9.5 10 4l7 5.5M5 8.5V16h10V8.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>',
    journal:              '<rect x="3" y="3.5" width="14" height="13" rx="2" stroke="currentColor" stroke-width="1.6"/><path d="M3 7.5h14M8 7.5v9" stroke="currentColor" stroke-width="1.6"/>',
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
        html += '<div class="prof-nav-label">Мои группы</div>';
        html += cfg.groups.map((g, i) => `
            <div class="prof-group-item" data-grp="${g.id}">
                <span class="prof-group-chip" style="background:${GROUP_COLORS[i % GROUP_COLORS.length]}">${esc(shortName(g.name))}</span>
                <div class="prof-group-meta">
                    <div class="prof-group-name">${esc(g.name)}</div>
                    <div class="prof-group-sub">${esc(g.subject)}</div>
                </div>
            </div>`).join('');
    }
    if (nav) nav.innerHTML = html;

    const user = document.getElementById('profUser');
    if (user) {
        const u = cfg.user || { name: '', initials: '' };
        const roleLabel = ROLE_LABELS[cfg.role] || 'Пользователь';
        user.innerHTML = `
            <div class="prof-avatar">${esc(u.initials)}</div>
            <div class="su-meta">
                <div class="su-name">${esc(u.name)}</div>
                <div class="su-role">${esc(roleLabel)}</div>
            </div>
            <button class="prof-icon-ghost" title="Настройки">
                <svg width="18" height="18" viewBox="0 0 20 20" fill="none"><circle cx="10" cy="10" r="2.4" stroke="currentColor" stroke-width="1.5"/><path d="M10 3v2M10 15v2M3 10h2M15 10h2M5 5l1.4 1.4M13.6 13.6 15 15M15 5l-1.4 1.4M6.4 13.6 5 15" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
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

/* ── Wiring ──────────────────────────────────────────────────────────── */
function wire() {
    document.querySelectorAll('.prof-nav-item[data-go]').forEach(n =>
        n.addEventListener('click', () => go(n.dataset.go)));
    document.querySelectorAll('.prof-group-item').forEach(el =>
        el.addEventListener('click', () => openJournalFor(el.dataset.grp)));

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

export function initProfile() {
    cfg = window.fsProfile || defaultConfig();
    if (!cfg.screens || !cfg.screens.length) cfg = defaultConfig();

    buildSidebar();
    buildStage();
    mountScreens();
    wire();
    go(cfg.screens[0]);
}
