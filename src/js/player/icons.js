/**
 * Мета типов шагов плеера + адаптер иконок. Сами SVG живут в едином модуле
 * `src/js/common/icons.js` (STEP_GLYPHS едины с конструктором курса,
 * admin/services/step-editor.js). Цвета типов — ЗЕРКАЛО единой палитры
 * `src/scss/shared/_tokens.scss` ($step-type-palette / -soft): править
 * синхронно! Здесь они нужны для SVG-заливки в ленте/дереве.
 */

import {
	STEP_GLYPHS,
	icoCheck,
	icoCross,
	icoLock,
	icoChevronRight,
	icoChevronLeft,
	icoChevronDown,
	icoFlag,
	icoClock,
} from '../common/icons.js';

export const TYPES = {
	text: { label: 'Текст', c: '#1c7ed6', soft: '#e7f2fb' },
	video: { label: 'Видео', c: '#7048e8', soft: '#f1ecfd' },
	task: { label: 'Задача', c: '#099268', soft: '#e6f7f1' },
	work: { label: 'Работа', c: '#e8590c', soft: '#fdeee3' },
	assessment: { label: 'Контрольная', c: '#e03131', soft: '#fdecec' },
};

export function typeMeta( type ) {
	return TYPES[ type ] || TYPES.text;
}

// Наш StepType → UI-тип конструктора (ключ STEP_GLYPHS).
const TYPE_UI = { text: 'lecture', video: 'video', task: 'task', work: 'practice', assessment: 'assessment' };

export function typeIco( type, color, s = 22 ) {
	const glyph = STEP_GLYPHS[ TYPE_UI[ type ] ] || STEP_GLYPHS.lecture;
	return `<svg width="${ s }" height="${ s }" viewBox="0 0 24 24" fill="${ color }">${ glyph }</svg>`;
}

// Совместимый фасад для step-*.js/rail.js — фабрики из common/icons.js.
export const ICO = {
	check: icoCheck,
	cross: icoCross,
	lock: icoLock,
	chevR: icoChevronRight,
	chevL: icoChevronLeft,
	chevD: icoChevronDown,
	flag: icoFlag,
	clock: icoClock,
};

export function esc( s ) {
	return String( s ).replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' ).replace( /"/g, '&quot;' );
}
