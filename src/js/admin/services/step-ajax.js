import '../_types.js';

/* global jQuery, fs_lms_vars */
const $ = jQuery;

/**
 * step-ajax.js — AJAX-транспорт редактора шагов + генератор временных ключей.
 * Вынесено из step-editor.js без изменения поведения; используется ядром
 * редактора шагов, курс-билдером и превью ссылочного контента.
 */

const acts = () => fs_lms_vars.ajax_actions;
export { acts };

let _idc = 5000;
export const tmpKey = ( p ) => `${ p }_tmp_${ Date.now() }_${ ++_idc }`;

// ── AJAX (нонс по экшену; оба нонса в fs_lms_vars глобально) ────
function nonceFor( action ) {
	const a = acts();
	const lessonScoped     = [ a.saveLessonSteps, a.getStepCandidates ];
	const assessmentScoped = [ a.getTaskPreview, a.getRefPreview ];
	if ( lessonScoped.includes( action ) )     { return fs_lms_vars.nonces.authorLesson; }
	if ( assessmentScoped.includes( action ) ) { return fs_lms_vars.nonces.authorAssessment; }
	return fs_lms_vars.nonces.authorCourse;
}

/**
 * @param {string}      action
 * @param {Object}      data
 */
export function ajax( action, data ) {
	return new Promise( ( resolve, reject ) => {
		$.post( fs_lms_vars.ajaxurl, Object.assign( { action, security: nonceFor( action ) }, data ) )
			.done( ( resp ) => ( resp && resp.success ) ? resolve( resp.data ) : reject( ( resp && resp.data ) || 'Ошибка' ) )
			.fail( () => reject( 'Ошибка сети' ) );
	} );
}
