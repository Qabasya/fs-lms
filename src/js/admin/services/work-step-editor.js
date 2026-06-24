import '../_types.js';
import { createStepEditor, readSteps } from './step-editor.js';

/* global jQuery, fs_lms_vars */
const $ = jQuery;

/**
 * WorkStepEditor — конструктор работы как степ-лист «только задачи».
 *
 * Тот же единый редактор шагов (`step-editor.js`), что у урока и курс-билдера, но:
 * меню ограничено `['task']` (любая задача из банка), а сохранение —
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
	const steps   = readSteps( el );

	el.innerHTML = '';

	createStepEditor( {
		mount:        el,
		lesson:       { id: workId, steps },
		subjectKey:   subject,
		allowedTypes: [ 'task' ],
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
