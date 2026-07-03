/**
 * Иконки и мета типов шагов плеера (по дизайн-хэндоффу lms-course-player).
 * Цвета типов продублированы в SCSS (player/_variables.scss) — здесь они
 * нужны для SVG-заливки в ленте/дереве.
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

export function typeIco( type, color, s = 22 ) {
	const head = `<svg width="${ s }" height="${ s }" viewBox="0 0 20 20" fill="none">`;
	const tail = '</svg>';
	if ( 'text' === type ) {
		return head +
			`<path d="M5 2.5h6.2L15.5 6.8V17.5H5V2.5z" fill="${ color }"/>` +
			'<path d="M11.2 2.5v4.3h4.3L11.2 2.5z" fill="#fff" opacity=".45"/>' +
			'<rect x="7" y="10" width="6.4" height="1.4" rx=".7" fill="#fff" opacity=".9"/>' +
			'<rect x="7" y="13" width="4.6" height="1.4" rx=".7" fill="#fff" opacity=".9"/>' + tail;
	}
	if ( 'video' === type ) {
		return head +
			`<rect x="2.5" y="4.2" width="15" height="11.6" rx="2.6" fill="${ color }"/>` +
			'<path d="M8.4 7.3v5.4L13 10 8.4 7.3z" fill="#fff"/>' + tail;
	}
	if ( 'task' === type ) {
		return head +
			`<circle cx="10" cy="10" r="7.6" fill="${ color }"/>` +
			'<path d="M7.9 7.9a2.15 2.15 0 1 1 3.3 1.85c-.65.42-1.2.85-1.2 1.65v.25" stroke="#fff" stroke-width="1.7" stroke-linecap="round" fill="none"/>' +
			'<circle cx="10" cy="14.1" r="1" fill="#fff"/>' + tail;
	}
	if ( 'work' === type ) {
		return head +
			`<path d="M7 5.5 3.4 10 7 14.5M13 5.5 16.6 10 13 14.5" stroke="${ color }" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round" fill="none"/>` + tail;
	}
	// assessment — список с галочками
	return head +
		`<path d="M3.4 5.2l1.2 1.2 2-2.1" stroke="${ color }" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" fill="none"/>` +
		`<rect x="8.6" y="4.6" width="8" height="1.6" rx=".8" fill="${ color }"/>` +
		`<path d="M3.4 10.2l1.2 1.2 2-2.1" stroke="${ color }" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" fill="none"/>` +
		`<rect x="8.6" y="9.6" width="8" height="1.6" rx=".8" fill="${ color }"/>` +
		`<path d="M3.4 15.2l1.2 1.2 2-2.1" stroke="${ color }" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" fill="none"/>` +
		`<rect x="8.6" y="14.6" width="8" height="1.6" rx=".8" fill="${ color }"/>` + tail;
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
