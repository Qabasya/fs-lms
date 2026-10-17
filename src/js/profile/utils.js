// Общие утилиты живут в common/utils.js — здесь только реэкспорт под привычными
// именами кабинета (esc ≡ escapeHtml) и профиль-специфика (тост, чипы, шкалы).
export { fmtDate, fmtDayMonth, fmtDateTime, fmtDuration, fmtClock, todayIso, initials, debounce } from '../common/utils.js';

// esc импортируется, а не реэкспортируется: `export ... from` не создаёт
// локального имени, а esc нужен здесь же — в emptyState() и openCtxMenuRaw().
import { escapeHtml as esc } from '../common/utils.js';
export { esc };

/* Shared utilities: toast, HTML escaping, formatting, context menu state */

import { AVA_COLORS } from './constants.js';
import { PATH_CHECK, PATH_CROSS, icoCheck } from '../common/icons.js';

let toastTimer;

/**
 * Показывает тост. type='ok' (по умолчанию) — зелёная галка; type='error' —
 * красный крестик (НБ-4). Иконка/цвет переключаются на общем элементе #profToast.
 */
export function toast(msg, type = 'ok') {
    const t = document.getElementById('profToast');
    if (!t) return;
    t.classList.toggle('error', type === 'error');
    const path = t.querySelector('svg path');
    if (path) { path.setAttribute('d', type === 'error' ? PATH_CROSS : PATH_CHECK); }
    t.querySelector('span').textContent = msg;
    t.classList.add('show');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => t.classList.remove('show'), 2000);
}


/* ── Formatting (shared across screens) ────────────────────────── */

/** Короткое имя группы для чипа: «10 «А»» → «10 А» → первые 4 символа. */
export function shortName(name) {
    return String(name).replace(/[«»]/g, '').replace(/\s+/g, ' ').trim().slice(0, 4);
}


/** Первое слово ФИО (фамилия). */
export function firstWord(name) {
    return String(name).split(' ').filter(Boolean)[0] || '';
}

/** Русская плюрализация: plural(3, 'занятие', 'занятия', 'занятий'). Правило — в common/plural.js. */
export { pluralRu as plural } from '../common/plural.js';

/** Число с точностью до сотых, без хвостовых нулей. */
export function fmtNum(n) {
    return String(Math.round(Number(n) * 100) / 100);
}




/* ── Colors (shared) ───────────────────────────────────────────── */

/**
 * Индекс цвета предмета/курса (0..11): стабильный хэш subject_key.
 * Палитра — src/scss/shared/_chip-palette.scss (.chip-cN и варианты);
 * PHP-зеркало — ProfileViewResolver::chipColorIndex(). Цвет закреплён за
 * ПРЕДМЕТОМ (#17: группы и курсы одного предмета красятся одинаково), не
 * зависит от порядка групп и совпадает в кабинете и плеере. Без инлайна.
 */
export function chipIndex(key) {
    const s = String(key || '');
    let h = 0;
    for (let i = 0; i < s.length; i++) { h = (h * 31 + s.charCodeAt(i)) | 0; }
    return Math.abs(h) % 12;
}

export const chipBg     = (key) => `chip-c${chipIndex(key)}`;

/**
 * URL аватара: группа (Настройки → Аватары, window.fsProfile.avatars) → курс (обложка
 * из Конструктора курса, приходит в данных строки) → предмет. '' — не задан, чип цветной.
 *
 * @param {string} subjectKey
 * @param {?(string|number)} groupId   ID группы.
 * @param {string} [courseUrl]         Аватар курса группы/строки (поле `avatar` из данных).
 */
export function avatarUrl(subjectKey, groupId = null, courseUrl = '') {
    const map = (window.fsProfile && window.fsProfile.avatars) || {};
    const byGroup = groupId !== null && map.group ? map.group[String(groupId)] : '';
    return byGroup || courseUrl || (map.subject && map.subject[String(subjectKey || '')]) || '';
}

/**
 * Чип сущности: картинка-аватар либо цветной чип с буквами названия.
 *
 * @param {string} baseClass  Класс размера чипа (prof-group-chip / sc-chip / kp-chip).
 * @param {string} subjectKey Ключ предмета — цвет и аватар по умолчанию.
 * @param {string} label      Название, из которого берутся буквы цветного чипа.
 * @param {?(string|number)} groupId ID группы — её аватар главнее аватара курса и предмета.
 * @param {string} [courseUrl] Аватар курса (поле `avatar` строки).
 */
export function chipHtml(baseClass, subjectKey, label, groupId = null, courseUrl = '') {
    const url = avatarUrl(subjectKey, groupId, courseUrl);
    if (url) {
        return `<span class="${baseClass} chip-img"><img src="${esc(url)}" alt=""></span>`;
    }
    return `<span class="${baseClass} ${chipBg(subjectKey)}">${esc(shortName(label))}</span>`;
}
export const chipText   = (key) => `chip-tc-c${chipIndex(key)}`;
export const chipBorder = (key) => `chip-bd-c${chipIndex(key)}`;
export const chipSoft   = (key) => `chip-soft-c${chipIndex(key)}`;

/**
 * Класс цвета левой полосы карточки занятия: экзамен — красный, индивидуальное — серый,
 * дальше по ключу предмета (ОГЭ — фиолетовый, Python — зелёный, робототехника — жёлтый);
 * ЕГЭ и прочие предметы — акцентный синий (пустая строка).
 *
 * @param {string} kind       Вид занятия: 'exam' | 'individual' | другое.
 * @param {string} subjectKey Ключ предмета.
 */
export function scheduleTone(kind, subjectKey) {
    if ('exam' === kind) { return 'tone-exam'; }
    if ('individual' === kind) { return 'tone-indi'; }
    const key = String(subjectKey || '').toLowerCase();
    if (key.includes('oge')) { return 'tone-oge'; }
    if (key.includes('python')) { return 'tone-python'; }
    if (key.includes('robo')) { return 'tone-robo'; }
    return '';
}

/** Ключ предмета группы сайдбара по id (fallback — id, чтобы цвет был стабилен). */
export function groupSubjectKey(gid) {
    const groups = (window.fsProfile && window.fsProfile.groups) || [];
    const g = groups.find(x => String(x.id) === String(gid));
    return (g && (g.subject_key || g.subject)) || String(gid);
}

/** Цвет аватара ученика по индексу в переданном списке ({person_id}). */
export function avaColor(list, personId) {
    const idx = (list || []).findIndex(s => s.person_id === personId);
    return AVA_COLORS[(idx < 0 ? 0 : idx) % AVA_COLORS.length];
}

/* ── Empty state (shared markup) ───────────────────────────────── */

/**
 * Полноэкранное пустое состояние экрана.
 *
 * @param {string} wrapClass Класс обёртки экрана (prof-dash / prof-journal / …).
 * @param {string} iconSvg   SVG-иконка (готовая разметка).
 * @param {string} title
 * @param {string} [text]
 * @param {boolean} [danger] Красная иконка (состояние ошибки).
 */
export function emptyState(wrapClass, iconSvg, title, text, danger) {
    return `<div class="${wrapClass}"><div class="prof-ktp-empty">
        <div class="ke-ico${danger ? ' ke-ico--danger' : ''}">${iconSvg}</div>
        <h3>${esc(title)}</h3><p>${esc(text || '')}</p>
    </div></div>`;
}

/* ── Context menu (shared) ─────────────────────────────────────── */
/** Адрес экрана кабинета с заданными параметрами (`?screen=…`): для ссылок «в новой вкладке». */
export function profileUrl(params) {
    const url = new URL(window.location.href);
    url.search = '';
    url.hash = '';
    Object.entries(params).forEach(([k, v]) => url.searchParams.set(k, v));
    return url.href;
}

let ctxOnClose = null;

/** Больше стольких пунктов — меню получает прокрутку (Tasks.md п. 2: ученики группы). */
const CTX_SCROLL_AFTER = 14;

/** `opts.alignRight` — правый край меню по правому краю якоря (кнопки «⋯» у правого края строки). */
export function openCtxMenu(anchor, items, onPick, opts = {}) {
    const menu = document.getElementById('profCtxMenu');
    const backdrop = document.getElementById('profCtxBackdrop');
    if (!menu || !backdrop) return;

    menu.classList.toggle('prof-ctx-menu--scroll', items.length > CTX_SCROLL_AFTER);
    menu.innerHTML = items.map(it => `<div class="ctx-item ${it.active ? 'on' : ''}" data-v="${esc(it.v)}">
        <span class="ctx-check">${it.active ? icoCheck(15) : ''}</span>
        ${it.swatchImg ? `<span class="ctx-sw chip-img"><img src="${esc(it.swatchImg)}" alt=""></span>` : it.swatch || it.swatchClass ? `<span class="ctx-sw${it.swatchClass ? ' ' + esc(it.swatchClass) : ''}"${it.swatch ? ` style="background:${it.swatch}"` : ''}>${esc(it.chip || '')}</span>` : ''}
        <span class="ctx-lbl">${esc(it.label)}</span>
    </div>`).join('');

    menu.querySelectorAll('.ctx-item').forEach(el =>
        el.addEventListener('click', () => { onPick(el.dataset.v); closeCtxMenu(); })
    );

    const r = anchor.getBoundingClientRect();
    menu.classList.add('open');
    menu.style.minWidth = Math.max(220, r.width) + 'px';
    menu.style.left = Math.max(10, Math.min(r.left, window.innerWidth - 300)) + 'px';
    menu.style.top = (r.bottom + 6) + 'px';
    backdrop.classList.add('open');
    // Реальная ширина известна только после показа: длинные пункты («⋯» в строках) уводили меню за правый край окна.
    if (opts.alignRight) {
        menu.style.left = Math.max(10, r.right - menu.getBoundingClientRect().width) + 'px';
    }
    // Снизу не помещается — открываем вверх от якоря; не помещается и там — прижимаем к нижнему краю окна (длинный список прокручивается).
    const height = menu.getBoundingClientRect().height;
    if (r.bottom + 6 + height > window.innerHeight - 10) {
        const above = r.top - 6 - height;
        menu.style.top = Math.max(10, above >= 10 ? above : window.innerHeight - 10 - height) + 'px';
    }
    const overflow = menu.getBoundingClientRect().right - (window.innerWidth - 10);
    if (overflow > 0) { menu.style.left = Math.max(10, parseFloat(menu.style.left) - overflow) + 'px'; }
    ctxOnClose = null;
}

export function openCtxMenuRaw(html, anchor, onClose, opts = {}) {
    const menu = document.getElementById('profCtxMenu');
    const backdrop = document.getElementById('profCtxBackdrop');
    if (!menu || !backdrop) return;
    menu.classList.remove('prof-ctx-menu--scroll');
    menu.innerHTML = html;
    const r = anchor.getBoundingClientRect();
    menu.classList.add('open');
    let left = Math.min(r.left, window.innerWidth - 220);
    menu.style.left = Math.max(10, left) + 'px';
    // opts.up — раскрыть вверх (для нижних якорей, напр. шестерёнка профиля).
    menu.style.top = opts.up
        ? Math.max(10, r.top - menu.offsetHeight - 6) + 'px'
        : (r.bottom + 4) + 'px';
    backdrop.classList.add('open');
    ctxOnClose = onClose || null;
}

export function closeCtxMenu() {
    const menu = document.getElementById('profCtxMenu');
    const backdrop = document.getElementById('profCtxBackdrop');
    if (menu) menu.classList.remove('open');
    const gradePop = document.getElementById('profGradePop');
    if (!gradePop || !gradePop.classList.contains('open')) {
        if (backdrop) backdrop.classList.remove('open');
    }
    if (ctxOnClose) { ctxOnClose(); ctxOnClose = null; }
}

/* ── Grade popover (shared) ────────────────────────────────────── */
let positionedGradePop = null;

function clearGradePopPositioning() {
    positionedGradePop = null;
    window.removeEventListener('resize', repositionGradePop);
}

function repositionGradePop() {
    if (!positionedGradePop) return;
    const { pop, anchor } = positionedGradePop;
    if (!pop.classList.contains('open')) {
        clearGradePopPositioning();
        return;
    }
    // Центрированная форма сама следует за экраном; её координаты не меняем.
    if (!pop.classList.contains('is-modal')) positionGradePop(pop, anchor);
}

export function openGradePopPositioned(pop, anchor) {
    pop.classList.add('open');
    positionedGradePop = { pop, anchor };
    window.addEventListener('resize', repositionGradePop);
    positionGradePop(pop, anchor);
    const backdrop = document.getElementById('profCtxBackdrop');
    if (backdrop) backdrop.classList.add('open');
}

function positionGradePop(pop, anchor) {
    const r = anchor.getBoundingClientRect();
    const pw = pop.offsetWidth, ph = pop.offsetHeight;
    let left = r.left + r.width / 2 - pw / 2;
    left = Math.max(10, Math.min(left, window.innerWidth - pw - 10));
    let top = r.bottom + 6;
    if (top + ph > window.innerHeight - 10) top = r.top - ph - 6;
    // НБ-8: верх/низ-clamp — высокий поповер (форма инд. занятия) у нижних/верхних
    // якорей не должен уходить за вьюпорт; при нехватке высоты прижимаем к верху
    // (переполнение гасит max-height/overflow на .prof-grade-pop).
    top = Math.max(10, Math.min(top, window.innerHeight - ph - 10));
    pop.style.left = left + 'px';
    pop.style.top = top + 'px';
}

/* Тот же поповер, но по центру экрана с затемнением: для длинных форм, которым тесно у якоря (настройки проведения). */
export function openGradePopModal(pop) {
    pop.classList.add('open', 'is-modal');
    pop.style.left = '';
    pop.style.top = '';
    const backdrop = document.getElementById('profCtxBackdrop');
    if (backdrop) backdrop.classList.add('open', 'dim');
}

export function closeGradePop() {
    clearGradePopPositioning();
    const pop = document.getElementById('profGradePop');
    if (pop) pop.classList.remove('open', 'is-modal');
    const menu = document.getElementById('profCtxMenu');
    if (!menu || !menu.classList.contains('open')) {
        const backdrop = document.getElementById('profCtxBackdrop');
        if (backdrop) backdrop.classList.remove('open', 'dim');
    }
}
