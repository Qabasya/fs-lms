/**
 * Мета типов шагов плеера + адаптер иконок. Сами SVG живут в едином модуле
 * `src/js/common/icons.js` (STEP_GLYPHS едины с конструктором курса,
 * admin/services/step-editor.js). Цвета типов — ЗЕРКАЛО единой палитры
 * `src/scss/shared/_tokens.scss` ($step-type-palette / -soft): править
 * синхронно! Здесь они нужны для SVG-заливки в ленте/дереве.
 */

export { escapeHtml as esc } from '../common/utils.js';

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
	text: { label: 'Лекция', c: '#1c7ed6', soft: '#e4f0fa' },
	video: { label: 'Видео', c: '#495057', soft: '#e9eaeb' },
	broadcast: { label: 'Занятие', c: '#f03e3e', soft: '#fde8e8' },
	task: { label: 'Задача', c: '#868e96', soft: '#f0f1f2' },
	work: { label: 'Работа', c: '#099268', soft: '#e1f2ed' },
	assessment: { label: 'Экзамен', c: '#e03131', soft: '#fbe6e6' },
};

// Работа называется и красится по типу работы (WorkType); цвета — зеркало тех же ключей палитры ядра.
const WORK_TYPES = {
	practice: { label: 'Практика', c: '#099268', soft: '#e1f2ed' },
	independent: { label: 'Самостоятельная работа', c: '#7048e8', soft: '#eee9fc' },
	homework: { label: 'Домашняя работа', c: '#e8590c', soft: '#fcebe2' },
	survey: { label: 'Опрос', c: '#0c8599', soft: '#e2f0f3' },
};

export function typeMeta( type, workType = '' ) {
	return 'work' === type && WORK_TYPES[ workType ] ? WORK_TYPES[ workType ] : ( TYPES[ type ] || TYPES.text );
}

// Наш StepType → UI-тип конструктора (ключ STEP_GLYPHS).
const TYPE_UI = { text: 'lecture', video: 'video', broadcast: 'broadcast', task: 'task', work: 'practice', assessment: 'assessment' };

export function typeIco( type, color, s = 22, workType = '' ) {
	const ui    = 'work' === type && WORK_TYPES[ workType ] ? workType : TYPE_UI[ type ];
	const glyph = STEP_GLYPHS[ ui ] || STEP_GLYPHS.lecture;
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

