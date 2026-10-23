/* ══════════════════════════════════════════════════════════════════════
   Открытие в новой вкладке пунктов меню, групп и курсов сайдбара, занятий КТП, учеников, уроков и сдач работ: колёсико мыши, Ctrl/Cmd+клик и контекстное меню.
   Строки кабинета — не ссылки (<div>/<button> с обработчиком клика), поэтому «новая вкладка» собирается здесь одним
   делегированным обработчиком по адресу строки: `data-href`, `data-url` (урок), `data-alert-*` (ученик на «Главной»)
   или `data-src-*` (сдача; адрес — глубокая ссылка экрана `?screen=…&submission=ID`, см. submissionDeepLink в app.js).
   ══════════════════════════════════════════════════════════════════════ */

import { openCtxMenu, profileUrl, toast } from './utils.js';
import { copyToClipboard } from '../common/utils.js';

const TARGET = [
    '.prof-screen [data-href]', '.prof-screen [data-url]', '.prof-screen [data-alert-pid]', '.prof-screen [data-src-id]',
    // Сайдбар (меню, группы, курсы) и карточки «Главной», ведущие в журнал группы.
    '.prof-nav-item[data-go]', '.prof-group-item[data-grp]', '.prof-course-item[data-course]', '.prof-screen[data-screen="dashboard"] [data-grp]',
].join(', ');
const INNER_CONTROLS = 'button, a, input, select, textarea, label';

/** Адрес строки или null, если у неё нет страницы, которую можно открыть отдельно. */
function hrefOf(el) {
    if (el.dataset.href) { return el.dataset.href; }
    if (el.dataset.url) { return el.dataset.url; }
    // Сайдбар: адрес совпадает с глубокой ссылкой, которую app.js разбирает при загрузке (экран / ?gid / ?course).
    if (el.matches('.prof-nav-item[data-go]')) { return profileUrl({ screen: el.dataset.go }); }
    if (el.matches('.prof-group-item[data-grp]')) { return profileUrl({ screen: 'groups', gid: el.dataset.grp }); }
    if (el.matches('.prof-course-item[data-course]')) { return profileUrl({ screen: 'teacher-courses', course: el.dataset.course }); }
    if (el.dataset.alertPid) {
        return profileUrl({ screen: 'summary', gid: el.dataset.alertGrp, pid: el.dataset.alertPid });
    }
    // Сдача — только у экранов, где работает глубокая ссылка на неё; попытки экзамена отдельного адреса не имеют.
    const screen = el.closest('.prof-screen')?.dataset.screen;
    if ('submission' === el.dataset.srcType && ['works', 'summary', 'activity'].includes(screen)) {
        return profileUrl({ screen, submission: el.dataset.srcId });
    }
    // Карточка «Главной» с группой открывает журнал этой группы (состояние журнала — journal_g, см. nav-state.js).
    if (el.dataset.grp && 'dashboard' === screen) {
        return profileUrl({ screen: 'journal', journal_g: el.dataset.grp });
    }
    return null;
}

/** Строка под курсором и её адрес; клик по вложенной кнопке/ссылке строки (чекбокс, «⋯») сюда не относится. */
function resolve(e) {
    const el = e.target.closest(TARGET);
    if (!el) { return null; }
    const inner = e.target.closest(INNER_CONTROLS);
    if (inner && inner !== el && el.contains(inner)) { return null; }
    const href = hrefOf(el);
    return href ? { el, href } : null;
}

function openTab(href) {
    window.open(href, '_blank', 'noopener');
}

/** Точка под курсором в роли якоря меню. */
function cursorAnchor(e) {
    const rect = { left: e.clientX, right: e.clientX, top: e.clientY, bottom: e.clientY, width: 0, height: 0 };
    return { getBoundingClientRect: () => rect };
}

export function initNewTabLinks() {
    // Ctrl/Cmd+клик: перехват в фазе захвата, чтобы собственный обработчик строки не открыл её в этой вкладке.
    document.addEventListener('click', e => {
        if (!(e.ctrlKey || e.metaKey)) { return; }
        const hit = resolve(e);
        if (!hit) { return; }
        e.preventDefault();
        e.stopPropagation();
        openTab(hit.href);
    }, true);

    // Колёсико: без mousedown-отмены браузер включает автопрокрутку вместо открытия.
    document.addEventListener('mousedown', e => {
        if (1 === e.button && resolve(e)) { e.preventDefault(); }
    });
    document.addEventListener('auxclick', e => {
        if (1 !== e.button) { return; }
        const hit = resolve(e);
        if (!hit) { return; }
        e.preventDefault();
        openTab(hit.href);
    });

    document.addEventListener('contextmenu', e => {
        const hit = resolve(e);
        if (!hit) { return; }
        e.preventDefault();
        openCtxMenu(cursorAnchor(e), [
            { v: 'tab', label: 'Открыть в новой вкладке' },
            { v: 'copy', label: 'Копировать ссылку' },
        ], async v => {
            if ('tab' === v) { openTab(hit.href); return; }
            toast(await copyToClipboard(hit.href) ? 'Ссылка скопирована' : 'Не удалось скопировать ссылку');
        });
    });
}
