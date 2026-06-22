import '../_types.js';
import { createStepEditor, TYPE_UI } from './step-editor.js';

/* global jQuery, fs_lms_vars */
const $ = jQuery;

/**
 * WorkStepEditor — конструктор работы как степ-лист «только задачи».
 *
 * Тот же единый редактор шагов (`step-editor.js`), что у урока и курс-билдера, но:
 * меню ограничено `['question','code']` (Вопрос / Задание с кодом), а сохранение —
 * `item_ids` работы (AJAX `SaveWorkItems`), а не `steps[]` урока.
 */
export const WorkStepEditor = {

	init() {
		$( '.fs-lms-work-builder' ).each( ( _, el ) => mountOne( el ) );
	},
};

function mountOne( el ) {
	const $el     = $( el );
	const workId  = parseInt( $el.data( 'work-id' ), 10 ) || 0;
	const subject = String( $el.data( 'subject' ) || '' );
	const steps   = readSteps( $el );

	el.innerHTML = '';
	el.classList.add( 'fs-lms-cb-wrap' ); // те же стили степ-редактора (хак, как в метабоксе урока)

	createStepEditor( {
		mount:        el,
		lesson:       { id: workId, steps },
		subjectKey:   subject,
		allowedTypes: [ 'question', 'code' ],
		persist:      ( s ) => saveWorkItems( workId, s ),
	} );
}

/** Степ-лист → item_ids (ссылки задач) → AJAX-автосейв. */
function saveWorkItems( workId, steps ) {
	const itemIds = steps
		.map( ( s ) => parseInt( s.payload && s.payload.ref, 10 ) || 0 )
		.filter( ( id ) => id > 0 );

	return new Promise( ( resolve, reject ) => {
		$.post( fs_lms_vars.ajaxurl, {
			action:   fs_lms_vars.ajax_actions.saveWorkItems,
			security: fs_lms_vars.nonces.authorWork,
			work_id:  workId,
			item_ids: itemIds,
		} )
			.done( ( resp ) => ( resp && resp.success ) ? resolve() : reject( ( resp && resp.data ) || 'Ошибка' ) )
			.fail( () => reject( 'Ошибка сети' ) );
	} );
}

/** @return {Array<{key:string,type:string,payload:object,title:string,_title:string}>} */
function readSteps( $el ) {
	const raw = $el.find( '.fs-sb-data' ).first().text();
	if ( ! raw ) {
		return [];
	}
	try {
		const parsed = JSON.parse( raw );
		return Array.isArray( parsed )
			? parsed.map( ( s ) => ( {
				key:     String( s.key || '' ),
				type:    String( s.type || '' ),
				payload: ( s.payload && typeof s.payload === 'object' ) ? s.payload : {},
				title:   s.title || '',
				_title:  s._title || '',
			} ) ).filter( ( s ) => TYPE_UI[ s.type ] )
			: [];
	} catch ( e ) {
		return [];
	}
}
