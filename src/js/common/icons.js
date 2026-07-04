/**
 * Единственный источник SVG-иконок для всех JS-бандлов
 * (admin / frontend / profile / player / common).
 *
 * Правила:
 * - НЕ писать `<svg>` в шаблонных строках модулей — импортировать фабрику отсюда;
 *   нет нужной иконки — добавить её здесь, а не по месту.
 * - Именованные экспорты-функции (tree-shaking): `icoCheck( 16 )` → строка `<svg>…</svg>`.
 * - Штриховые иконки: viewBox 20×20, stroke="currentColor" (цвет наследуется от родителя);
 *   у шевронов второй аргумент — цвет обводки (например, 'var(--muted-2)').
 * - Заливочные иконки (fill="currentColor"): кареты/грипы (viewBox 12/14) и
 *   admin-экшены (viewBox 20/24 — исторические глифы Material).
 * - Глифы типов шагов (STEP_GLYPHS, viewBox 24) едины для курс-билдера (stepIcon)
 *   и плеера (player/icons.js → typeIco).
 * - PHP-зеркало для шаблонов — enum `Inc\Enums\Ui\Icon` (глифы check/chevron/lock
 *   должны визуально совпадать с этим файлом).
 */

/* ── Пути-константы (для swap атрибута d на живом DOM, см. profile/utils.js toast) ── */
export const PATH_CHECK = 'M4 10.5 8 14l8-8.5';
export const PATH_CROSS = 'M5 5l10 10M15 5 5 15';

/* ── Штриховые, viewBox 20×20 ─────────────────────────────────────────── */
const stroke20 = ( body, s, c = 'currentColor' ) =>
	`<svg width="${ s }" height="${ s }" viewBox="0 0 20 20" fill="none">${ body.split( '{c}' ).join( c ) }</svg>`;

export const icoCheck = ( s = 14 ) =>
	stroke20( `<path d="${ PATH_CHECK }" stroke="{c}" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>`, s );

export const icoCross = ( s = 12 ) =>
	stroke20( `<path d="${ PATH_CROSS }" stroke="{c}" stroke-width="1.8" stroke-linecap="round"/>`, s );

export const icoLock = ( s = 13 ) =>
	stroke20( '<rect x="4.5" y="8.5" width="11" height="8" rx="2" stroke="{c}" stroke-width="1.5"/><path d="M7 8.5V6.5a3 3 0 0 1 6 0v2" stroke="{c}" stroke-width="1.5"/>', s );

export const icoChevronRight = ( s = 15, c = 'currentColor' ) =>
	stroke20( '<path d="M8 4.5 13.5 10 8 15.5" stroke="{c}" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>', s, c );

export const icoChevronLeft = ( s = 15, c = 'currentColor' ) =>
	stroke20( '<path d="M12 4.5 6.5 10l5.5 5.5" stroke="{c}" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>', s, c );

export const icoChevronDown = ( s = 13, c = 'currentColor' ) =>
	stroke20( '<path d="M4.5 8 10 13.5 15.5 8" stroke="{c}" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>', s, c );

export const icoFlag = ( s = 14 ) =>
	stroke20( '<path d="M5 17V3.5M5 4h9.5l-2 3 2 3H5" stroke="{c}" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/>', s );

export const icoClock = ( s = 16 ) =>
	stroke20( '<circle cx="10" cy="10" r="7.5" stroke="{c}" stroke-width="1.5"/><path d="M10 6v4.2l2.8 1.6" stroke="{c}" stroke-width="1.5" stroke-linecap="round"/>', s );

export const icoCalendar = ( s = 16 ) =>
	stroke20( '<path d="M4 6h12v10H4zM4 9h12M7 4v3M13 4v3" stroke="{c}" stroke-width="1.6" stroke-linecap="round"/>', s );

export const icoAlert = ( s = 16 ) =>
	stroke20( '<path d="M10 4v7M10 14.5v.5" stroke="{c}" stroke-width="1.8" stroke-linecap="round"/>', s );

export const icoBookmark = ( s = 18 ) =>
	stroke20( '<path d="M5 4h10v12l-5-2.5L5 16z" stroke="{c}" stroke-width="1.5" stroke-linejoin="round"/>', s );

export const icoShield = ( s = 18 ) =>
	stroke20( '<path d="M10 2l7 3v5c0 4-3 7-7 8-4-1-7-4-7-8V5z" stroke="{c}" stroke-width="1.5" stroke-linejoin="round"/>', s );

export const icoSearch = ( s = 14 ) =>
	stroke20( '<circle cx="9" cy="9" r="5.5" stroke="{c}" stroke-width="1.6"/><path d="m13.5 13.5 3.2 3.2" stroke="{c}" stroke-width="1.6" stroke-linecap="round"/>', s );

export const icoSwap = ( s = 15 ) =>
	stroke20( '<path d="M4 7h9m0 0-3-3m3 3-3 3M16 13H7m0 0 3-3m-3 3 3 3" stroke="{c}" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>', s );

export const icoContinue = ( s = 16 ) =>
	stroke20( '<path d="M4 10h9m0 0-3-3m3 3-3 3M16 10h.01" stroke="{c}" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>', s );

export const icoLogout = ( s = 16 ) =>
	stroke20( '<path d="M8 5V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v12a1 1 0 0 1-1 1H9a1 1 0 0 1-1-1v-1M11 10H3m0 0 3-3m-3 3 3 3" stroke="{c}" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>', s );

export const icoHome = ( s = 19 ) =>
	stroke20( '<path d="M3 9.5 10 4l7 5.5M5 8.5V16h10V8.5" stroke="{c}" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>', s );

export const icoUsers = ( s = 19 ) =>
	stroke20( '<circle cx="7.5" cy="7" r="2.5" stroke="{c}" stroke-width="1.6"/><path d="M3 16c0-2.5 2-4.5 4.5-4.5S12 13.5 12 16M13 5.2a2.5 2.5 0 0 1 0 4.6M17 16c0-2-1.2-3.7-3-4.3" stroke="{c}" stroke-width="1.6" stroke-linecap="round"/>', s );

export const icoJournal = ( s = 19 ) =>
	stroke20( '<rect x="3" y="3.5" width="14" height="13" rx="2" stroke="{c}" stroke-width="1.6"/><path d="M3 7.5h14M8 7.5v9" stroke="{c}" stroke-width="1.6"/>', s );

export const icoDocCheck = ( s = 19 ) =>
	stroke20( '<path d="M5 3h8l3 3v11H5z" stroke="{c}" stroke-width="1.6" stroke-linejoin="round"/><path d="M7.5 10l2 2 4-4.5" stroke="{c}" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>', s );

export const icoCalendarBoard = ( s = 19 ) =>
	stroke20( '<rect x="3" y="4" width="14" height="13" rx="2" stroke="{c}" stroke-width="1.6"/><path d="M3 8h14M7 2.5v3M13 2.5v3" stroke="{c}" stroke-width="1.6" stroke-linecap="round"/>', s );

export const icoBook = ( s = 19 ) =>
	stroke20( '<path d="M4 4h7v12H4zM11 4h5v12h-5" stroke="{c}" stroke-width="1.6" stroke-linejoin="round"/>', s );

export const icoStar = ( s = 19 ) =>
	stroke20( '<path d="M10 3 12 7l4.5.6-3.3 3.2.8 4.5L10 13.2 6 15.5l.8-4.5L3.5 7.7 8 7z" stroke="{c}" stroke-width="1.5" stroke-linejoin="round"/>', s );

/** Маркер кабинета/аудитории (map-pin), viewBox 16×16. */
export const icoMapPin = ( s = 13 ) =>
	`<svg width="${ s }" height="${ s }" viewBox="0 0 16 16" fill="none"><path d="M8 2C5.5 2 4 3.8 4 6c0 3 4 8 4 8s4-5 4-8c0-2.2-1.5-4-4-4z" stroke="currentColor" stroke-width="1.3"/><circle cx="8" cy="6" r="1.4" fill="currentColor"/></svg>`;

/** Шестерёнка настроек (заливочная), viewBox 20×20. */
export const icoGear = ( s = 18 ) =>
	`<svg width="${ s }" height="${ s }" viewBox="0 0 20 20" fill="none"><path fill="currentColor" fill-rule="evenodd" clip-rule="evenodd" d="M8.94 2.5a1 1 0 0 0-.98.8l-.24 1.19a5.6 5.6 0 0 0-1.28.74l-1.15-.4a1 1 0 0 0-1.19.45L3.04 7.11a1 1 0 0 0 .2 1.25l.9.79a5.7 5.7 0 0 0 0 1.5l-.9.79a1 1 0 0 0-.2 1.25l1.06 1.84a1 1 0 0 0 1.19.45l1.15-.4c.39.3.82.55 1.28.74l.24 1.19a1 1 0 0 0 .98.8h2.12a1 1 0 0 0 .98-.8l.24-1.19c.46-.19.89-.44 1.28-.74l1.15.4a1 1 0 0 0 1.19-.45l1.06-1.84a1 1 0 0 0-.2-1.25l-.9-.79a5.7 5.7 0 0 0 0-1.5l.9-.79a1 1 0 0 0 .2-1.25l-1.06-1.84a1 1 0 0 0-1.19-.45l-1.15.4a5.6 5.6 0 0 0-1.28-.74l-.24-1.19a1 1 0 0 0-.98-.8H8.94zM10 12.8a2.8 2.8 0 1 1 0-5.6 2.8 2.8 0 0 1 0 5.6z"/></svg>`;

/* ── Заливочные мелкие (кареты, грипы, пин) ───────────────────────────── */

/** Карет-треугольник вниз, viewBox 12×12; cls — класс на <svg> (напр. 'kp-caret'). */
export const icoCaret = ( s = 12, cls = '' ) =>
	`<svg${ cls ? ` class="${ cls }"` : '' } width="${ s }" height="${ s }" viewBox="0 0 12 12"><path d="M3 4.5 6 8l3-3.5z" fill="currentColor"/></svg>`;

/** Грип перетаскивания (6 точек), viewBox 12×12. */
export const icoGrip = ( s = 12 ) =>
	`<svg width="${ s }" height="${ s }" viewBox="0 0 12 12"><path fill="currentColor" d="M4 2.5h1v1H4zm3 0h1v1H7zM4 5.5h1v1H4zm3 0h1v1H7zM4 8.5h1v1H4zm3 0h1v1H7z"/></svg>`;

/** Пин закреплённой темы (заливочный), viewBox 14×14. */
export const icoPinFilled = ( s = 11 ) =>
	`<svg width="${ s }" height="${ s }" viewBox="0 0 14 14" fill="currentColor"><path d="M9.5 1.5 12.5 4.5 10 7l.5 3-3-2-3.5 3.5L4.5 8 2 7.5 4.5 5 7 4z"/></svg>`;

/* ── Заливочные admin-экшены (fill="currentColor") ────────────────────── */
const fill20 = ( body, s ) =>
	`<svg width="${ s }" height="${ s }" viewBox="0 0 20 20" fill="currentColor">${ body }</svg>`;
const fill24 = ( body, s ) =>
	`<svg width="${ s }" height="${ s }" viewBox="0 0 24 24" fill="currentColor">${ body }</svg>`;

export const icoPlus = ( s = 13 ) =>
	fill20( '<path d="M10 4v6H4v2h6v6h2v-6h6v-2h-6V4z"/>', s );

export const icoDuplicate = ( s = 13 ) =>
	fill24( '<path d="M16 1H4c-1.1 0-2 .9-2 2v14h2V3h12V1zm3 4H8c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h11c1.1 0 2-.9 2-2V7c0-1.1-.9-2-2-2zm0 16H8V7h11v14z"/>', s );

export const icoX = ( s = 13 ) =>
	fill24( '<path d="M19 6.41 17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/>', s );

export const icoReplace = ( s = 13 ) =>
	fill24( '<path d="M16 17.01V10h-2v7.01h-3L15 21l4-3.99h-3zM9 3 5 6.99h3V14h2V6.99h3L9 3z"/>', s );

export const icoImport = ( s = 13 ) =>
	fill20( '<path d="M10 2 5 7h3v5h4V7h3l-5-5zM3 14h14v3a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1v-3z"/>', s );

export const icoTrash = ( s = 13 ) =>
	fill24( '<path d="M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z"/>', s );

export const icoModule = ( s = 13 ) =>
	fill20( '<path d="M3 3h6v6H3V3zm8 0h6v6h-6V3zM3 11h6v6H3v-6zm8 4h6v2h-6v-2zm2-2h2v-2h-2v2z"/>', s );

export const icoEye = ( s = 14 ) =>
	fill20( '<path d="M10 4C5.5 4 2 10 2 10s3.5 6 8 6 8-6 8-6-3.5-6-8-6zm0 10a4 4 0 1 1 0-8 4 4 0 0 1 0 8zm0-6a2 2 0 1 0 0 4 2 2 0 0 0 0-4z"/>', s );

/* ── Глифы типов шагов урока (viewBox 24×24) ──────────────────────────────
   Ключи — UI-типы конструктора (step-editor.js TYPE_UI): lecture, video,
   practice, assessment, task. Маппинг StepType → UI: text→lecture, video→video,
   task→task, work→practice, assessment→assessment (см. player/icons.js). */
export const STEP_GLYPHS = {
	lecture:    '<path d="M6 3h9l5 5v13a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1zm8 1.5V8h3.5L14 4.5zM8 12h8v1.6H8V12zm0 3.4h8V17H8v-1.6zM8 8.6h4v1.6H8V8.6z"/>',
	video:      '<path d="M4 5h16a1 1 0 0 1 1 1v12a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1zm6 3.2v7.6l6-3.8-6-3.8z"/>',
	practice:   '<path d="M9.4 16.6 4.8 12l4.6-4.6L8 6l-6 6 6 6 1.4-1.4zm5.2 0L19.2 12l-4.6-4.6L16 6l6 6-6 6-1.4-1.4z"/>',
	assessment: '<path d="M4 5h7v2H4V5zm0 6h7v2H4v-2zm0 6h7v2H4v-2zm14.3-9.3 1.4 1.4-5 5-3-3 1.4-1.4 1.6 1.6 3.6-3.6zm0 6 1.4 1.4-5 5-3-3 1.4-1.4 1.6 1.6 3.6-3.6z"/>',
	task:       '<path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 17h-2v-2h2v2zm2.07-7.75-.9.92C13.45 12.9 13 13.5 13 15h-2v-.5c0-1.1.45-2.1 1.17-2.83l1.24-1.26c.37-.36.59-.86.59-1.41 0-1.1-.9-2-2-2s-2 .9-2 2H8c0-2.21 1.79-4 4-4s4 1.79 4 4c0 .88-.36 1.68-.93 2.25z"/>',
};

/** Глиф типа шага для админки (fill наследуется из CSS, как в конструкторе). */
export const stepIcon = ( ui, s = 22 ) =>
	`<svg viewBox="0 0 24 24" width="${ s }" height="${ s }">${ STEP_GLYPHS[ ui ] || STEP_GLYPHS.lecture }</svg>`;
