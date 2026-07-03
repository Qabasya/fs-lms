/* Shared utilities: toast, HTML escaping, formatting, context menu state */

import { GROUP_COLORS, AVA_COLORS } from './constants.js';

let toastTimer;

export function toast(msg) {
    const t = document.getElementById('profToast');
    if (!t) return;
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

/** Сегодняшняя дата 'YYYY-MM-DD'. */
export function todayIso() {
    return new Date().toISOString().slice(0, 10);
}

/* ── Colors (shared) ───────────────────────────────────────────── */

/**
 * Цвет группы: индекс в fsProfile.groups → GROUP_COLORS (согласован между
 * сайдбаром, пикерами и дашбордом); для группы вне списка — стабильный хэш id.
 */
export function groupColor(gid) {
    const groups = (window.fsProfile && window.fsProfile.groups) || [];
    const idx = groups.findIndex(g => String(g.id) === String(gid));
    if (idx >= 0) return GROUP_COLORS[idx % GROUP_COLORS.length];
    let h = 0;
    const s = String(gid);
    for (let i = 0; i < s.length; i++) { h = (h * 31 + s.charCodeAt(i)) | 0; }
    return GROUP_COLORS[Math.abs(h) % GROUP_COLORS.length];
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
        <span class="ctx-check">${it.active ? '<svg width="15" height="15" viewBox="0 0 20 20" fill="none"><path d="M4 10.5 8 14l8-8.5" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg>' : ''}</span>
        ${it.swatch ? `<span class="ctx-sw" style="background:${it.swatch}">${esc(it.chip || '')}</span>` : ''}
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
    const pw = 188, ph = pop.offsetHeight;
    let left = r.left + r.width / 2 - pw / 2;
    left = Math.max(10, Math.min(left, window.innerWidth - pw - 10));
    let top = r.bottom + 6;
    if (top + ph > window.innerHeight - 10) top = r.top - ph - 6;
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
