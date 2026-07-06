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

export function esc(s) {
    return String(s)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

/* ── Formatting (shared across screens) ────────────────────────── */

/** Короткое имя группы для чипа: «10 «А»» → «10 А» → первые 4 символа. */
export function shortName(name) {
    return String(name).replace(/[«»]/g, '').replace(/\s+/g, ' ').trim().slice(0, 4);
}

/** Инициалы из ФИО: «Иванова Софья» → «ИС». */
export function initials(name) {
    return String(name).split(' ').filter(Boolean).map(w => w[0]).join('').slice(0, 2).toUpperCase();
}

/** Первое слово ФИО (фамилия). */
export function firstWord(name) {
    return String(name).split(' ').filter(Boolean)[0] || '';
}

/** Русская плюрализация: plural(3, 'занятие', 'занятия', 'занятий'). */
export function plural(n, one, few, many) {
    const m10 = n % 10, m100 = n % 100;
    if (m10 === 1 && m100 !== 11) return one;
    if (m10 >= 2 && m10 <= 4 && (m100 < 10 || m100 >= 20)) return few;
    return many;
}

/** Число с точностью до сотых, без хвостовых нулей. */
export function fmtNum(n) {
    return String(Math.round(Number(n) * 100) / 100);
}

/** 'YYYY-MM-DD…' → 'DD.MM'. */
export function fmtDayMonth(s) {
    if (!s) return '';
    const p = String(s).slice(0, 10).split('-');
    return p.length === 3 ? `${p[2]}.${p[1]}` : s;
}

/** Полная дата 'ДД.ММ.ГГГГ'. */
export function fmtDate(s) {
    if (!s) return '';
    const p = String(s).slice(0, 10).split('-');
    return p.length === 3 ? `${p[2]}.${p[1]}.${p[0]}` : s;
}

/** Сегодняшняя дата 'YYYY-MM-DD'. */
export function todayIso() {
    return new Date().toISOString().slice(0, 10);
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
export const chipText   = (key) => `chip-tc-c${chipIndex(key)}`;
export const chipBorder = (key) => `chip-bd-c${chipIndex(key)}`;
export const chipSoft   = (key) => `chip-soft-c${chipIndex(key)}`;

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
let ctxOnClose = null;

export function openCtxMenu(anchor, items, onPick) {
    const menu = document.getElementById('profCtxMenu');
    const backdrop = document.getElementById('profCtxBackdrop');
    if (!menu || !backdrop) return;

    menu.innerHTML = items.map(it => `<div class="ctx-item ${it.active ? 'on' : ''}" data-v="${esc(it.v)}">
        <span class="ctx-check">${it.active ? icoCheck(15) : ''}</span>
        ${it.swatch || it.swatchClass ? `<span class="ctx-sw${it.swatchClass ? ' ' + esc(it.swatchClass) : ''}"${it.swatch ? ` style="background:${it.swatch}"` : ''}>${esc(it.chip || '')}</span>` : ''}
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
    ctxOnClose = null;
}

export function openCtxMenuRaw(html, anchor, onClose, opts = {}) {
    const menu = document.getElementById('profCtxMenu');
    const backdrop = document.getElementById('profCtxBackdrop');
    if (!menu || !backdrop) return;
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
export function openGradePopPositioned(pop, anchor) {
    pop.classList.add('open');
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
    const backdrop = document.getElementById('profCtxBackdrop');
    if (backdrop) backdrop.classList.add('open');
}

export function closeGradePop() {
    const pop = document.getElementById('profGradePop');
    if (pop) pop.classList.remove('open');
    const menu = document.getElementById('profCtxMenu');
    if (!menu || !menu.classList.contains('open')) {
        const backdrop = document.getElementById('profCtxBackdrop');
        if (backdrop) backdrop.classList.remove('open');
    }
}
