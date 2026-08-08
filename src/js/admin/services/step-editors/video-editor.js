import { escapeHtml as esc } from '../../../common/utils.js';

// ── Видео-шаг: главы и вложения (D21, T14.12) ─────────────────────────
// Вынесено из step-editor.js без изменения поведения: автосейв инстанса
// редактора (`scheduleSave`) передаётся параметром.

function fmtChapterTime( sec ) {
	sec = Math.max( 0, parseInt( sec, 10 ) || 0 );
	return `${ Math.floor( sec / 60 ) }:${ String( sec % 60 ).padStart( 2, '0' ) }`;
}

function parseChapterTime( raw ) {
	const parts = String( raw ).trim().split( ':' ).map( ( p ) => parseInt( p, 10 ) || 0 );
	if ( 1 === parts.length ) { return parts[ 0 ]; }
	if ( 2 === parts.length ) { return parts[ 0 ] * 60 + parts[ 1 ]; }
	return parts[ 0 ] * 3600 + parts[ 1 ] * 60 + parts[ 2 ];
}

export function renderChapterRows( box, step, scheduleSave ) {
	const chapters = step.payload.chapters || [];
	box.innerHTML  = chapters.map( ( ch, i ) => `
		<div class="fs-cb-chapter-row">
			<input class="field-input fs-cb-ch-time" data-ch-time="${ i }" value="${ fmtChapterTime( ch.t ) }" placeholder="мм:сс">
			<input class="field-input fs-cb-ch-title" data-ch-title="${ i }" value="${ esc( ch.title || '' ) }" placeholder="Название главы">
			<button type="button" class="button fs-sb-btn-danger" data-ch-del="${ i }">✕</button>
		</div>` ).join( '' );

	box.querySelectorAll( '[data-ch-time]' ).forEach( ( input ) => {
		input.addEventListener( 'change', () => {
			chapters[ parseInt( input.dataset.chTime, 10 ) ].t = parseChapterTime( input.value );
			input.value = fmtChapterTime( chapters[ parseInt( input.dataset.chTime, 10 ) ].t );
			scheduleSave();
		} );
	} );
	box.querySelectorAll( '[data-ch-title]' ).forEach( ( input ) => {
		input.addEventListener( 'input', () => {
			chapters[ parseInt( input.dataset.chTitle, 10 ) ].title = input.value;
			scheduleSave();
		} );
	} );
	box.querySelectorAll( '[data-ch-del]' ).forEach( ( btn ) => {
		btn.addEventListener( 'click', () => {
			chapters.splice( parseInt( btn.dataset.chDel, 10 ), 1 );
			renderChapterRows( box, step, scheduleSave );
			scheduleSave();
		} );
	} );
}

export function renderAttachmentRows( box, step, scheduleSave ) {
	const ids     = step.payload.attachments || [];
	box.innerHTML = ids.map( ( id, i ) => `
		<div class="fs-cb-attach-row">
			<span class="fs-cb-attach-title" data-att-title="${ id }">#${ id }</span>
			<button type="button" class="button fs-sb-btn-danger" data-att-del="${ i }">✕</button>
		</div>` ).join( '' );

	// Название файла — лениво из медиабиблиотеки (в payload храним только id).
	if ( window.wp?.media ) {
		ids.forEach( ( id ) => {
			const model = window.wp.media.attachment( id );
			model.fetch().then( () => {
				const el = box.querySelector( `[data-att-title="${ id }"]` );
				if ( el ) { el.textContent = model.get( 'title' ) || model.get( 'filename' ) || `#${ id }`; }
			} ).catch( () => {} );
		} );
	}

	box.querySelectorAll( '[data-att-del]' ).forEach( ( btn ) => {
		btn.addEventListener( 'click', () => {
			ids.splice( parseInt( btn.dataset.attDel, 10 ), 1 );
			renderAttachmentRows( box, step, scheduleSave );
			scheduleSave();
		} );
	} );
}
