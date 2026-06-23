import '../_types.js';
import { createStepEditor, readSteps } from './step-editor.js';

/* global jQuery */
const $ = jQuery;

/**
 * LessonStepEditor — монтирует единый редактор шагов (эталон курс-билдера) в
 * нативном метабоксе урока (`.fs-lms-step-builder`), заменяя прежний StepBuilder.
 *
 * Тот же UI, что и в курс-билдере; автосейв; без «перенести шаг» (шаги не
 * переносятся между уроками — только add/remove/reorder).
 */
export const LessonStepEditor = {

	init() {
		$( '.fs-lms-step-builder' ).each( ( _, el ) => mountOne( el ) );
	},
};

function mountOne( el ) {
	const $el      = $( el );
	const lessonId = parseInt( $el.data( 'lesson-id' ), 10 ) || 0;
	const subject  = String( $el.data( 'subject' ) || '' );
	const steps    = readSteps( el );

	el.innerHTML = '';

	createStepEditor( {
		mount:      el,
		lesson:     { id: lessonId, steps },
		subjectKey: subject,
	} );
}
