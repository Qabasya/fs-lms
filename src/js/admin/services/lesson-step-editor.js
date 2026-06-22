import '../_types.js';
import { createStepEditor, TYPE_UI } from './step-editor.js';

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
	const steps    = readSteps( $el );

	// JSON-данные прочитаны — очищаем контейнер под рендер редактора.
	el.innerHTML = '';

	// Стили степ-редактора пока живут под `.fs-lms-cb-wrap` (курс-билдер) — применяем
	// тот же CSS, чтобы UI был идентичным. В 2b вынесем в общий `.fs-se`-партиал.
	el.classList.add( 'fs-lms-cb-wrap' );

	createStepEditor( {
		mount:      el,
		lesson:     { id: lessonId, steps },
		subjectKey: subject,
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