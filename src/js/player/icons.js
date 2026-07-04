/**
 * Иконки и мета типов шагов плеера. Глифы типов (typeIco) — 1-в-1 с
 * конструктором курса (admin/services/step-editor.js `ICON`), дизайн из
 * lms-course-player не используем. Цвета типов продублированы в SCSS
 * (player/_variables.scss) — здесь они нужны для SVG-заливки в ленте/дереве.
 */

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

// Глифы типов шага — 1-в-1 с конструктором курса (step-editor.js `ICON`, 24×24).
// Наш StepType → UI-тип конструктора: text→lecture, video→video, task→task,
// work→practice, assessment→assessment. Дизайн из lms-course-player игнорируем.
const GLYPH = {
	text:       '<path d="M6 3h9l5 5v13a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1zm8 1.5V8h3.5L14 4.5zM8 12h8v1.6H8V12zm0 3.4h8V17H8v-1.6zM8 8.6h4v1.6H8V8.6z"/>',
	video:      '<path d="M4 5h16a1 1 0 0 1 1 1v12a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1zm6 3.2v7.6l6-3.8-6-3.8z"/>',
	task:       '<path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 17h-2v-2h2v2zm2.07-7.75-.9.92C13.45 12.9 13 13.5 13 15h-2v-.5c0-1.1.45-2.1 1.17-2.83l1.24-1.26c.37-.36.59-.86.59-1.41 0-1.1-.9-2-2-2s-2 .9-2 2H8c0-2.21 1.79-4 4-4s4 1.79 4 4c0 .88-.36 1.68-.93 2.25z"/>',
	work:       '<path d="M9.4 16.6 4.8 12l4.6-4.6L8 6l-6 6 6 6 1.4-1.4zm5.2 0L19.2 12l-4.6-4.6L16 6l6 6-6 6-1.4-1.4z"/>',
	assessment: '<path d="M4 5h7v2H4V5zm0 6h7v2H4v-2zm0 6h7v2H4v-2zm14.3-9.3 1.4 1.4-5 5-3-3 1.4-1.4 1.6 1.6 3.6-3.6zm0 6 1.4 1.4-5 5-3-3 1.4-1.4 1.6 1.6 3.6-3.6z"/>',
};

export function typeIco( type, color, s = 22 ) {
	const glyph = GLYPH[ type ] || GLYPH.text;
	return `<svg width="${ s }" height="${ s }" viewBox="0 0 24 24" fill="${ color }">${ glyph }</svg>`;
}

export const ICO = {
	check: ( s = 14 ) => `<svg width="${ s }" height="${ s }" viewBox="0 0 20 20" fill="none"><path d="M4 10.5 8 14l8-8.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>`,
	cross: ( s = 12 ) => `<svg width="${ s }" height="${ s }" viewBox="0 0 20 20" fill="none"><path d="M5 5l10 10M15 5 5 15" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>`,
	lock: ( s = 13 ) => `<svg width="${ s }" height="${ s }" viewBox="0 0 20 20" fill="none"><rect x="4.5" y="8.5" width="11" height="8" rx="2" stroke="currentColor" stroke-width="1.5"/><path d="M7 8.5V6.5a3 3 0 0 1 6 0v2" stroke="currentColor" stroke-width="1.5"/></svg>`,
	chevR: ( s = 15 ) => `<svg width="${ s }" height="${ s }" viewBox="0 0 20 20" fill="none"><path d="M8 4.5 13.5 10 8 15.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>`,
	chevL: ( s = 15 ) => `<svg width="${ s }" height="${ s }" viewBox="0 0 20 20" fill="none"><path d="M12 4.5 6.5 10l5.5 5.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>`,
	chevD: ( s = 13 ) => `<svg width="${ s }" height="${ s }" viewBox="0 0 20 20" fill="none"><path d="M4.5 8 10 13.5 15.5 8" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>`,
	flag: ( s = 14 ) => `<svg width="${ s }" height="${ s }" viewBox="0 0 20 20" fill="none"><path d="M5 17V3.5M5 4h9.5l-2 3 2 3H5" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>`,
	clock: ( s = 16 ) => `<svg width="${ s }" height="${ s }" viewBox="0 0 20 20" fill="none"><circle cx="10" cy="10" r="7.5" stroke="currentColor" stroke-width="1.5"/><path d="M10 6v4.2l2.8 1.6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>`,
};

export function esc( s ) {
	return String( s ).replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' ).replace( /"/g, '&quot;' );
}
