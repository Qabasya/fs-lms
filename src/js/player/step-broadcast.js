/**
 * Шаг «Трансляция» в режиме преподавателя (Tasks.md З1): ссылка на запись
 * занятия вставляется прямо в шаг. Форма рендерится сервером только в
 * teacher-режиме (partials/step-broadcast.php); сохранение — тот же экшен, что
 * у попапа камеры в КТП. После сохранения страница перезагружается: шаг
 * пересобирается сервером в состояние «после занятия».
 */
import { playerPost } from './request.js';
import { toast } from './shell.js';

const vars = window.fs_lms_player_vars;

export function initStepBroadcast() {
	if ( ! vars?.actions?.setRecording ) { return; }

	document.querySelectorAll( '[data-recording-form]' ).forEach( ( form ) => {
		const input = form.querySelector( '[name="recording_link"]' );
		const save  = ( link ) => submit( form, link );

		form.addEventListener( 'submit', ( e ) => {
			e.preventDefault();
			save( input.value.trim() );
		} );
		form.querySelector( '[data-recording-clear]' )?.addEventListener( 'click', () => save( '' ) );
	} );
}

async function submit( form, link ) {
	const buttons = form.querySelectorAll( 'button' );
	buttons.forEach( ( b ) => { b.disabled = true; } );

	const fd = new FormData();
	fd.append( 'action', vars.actions.setRecording );
	fd.append( 'security', vars.nonces.setRecording );
	fd.append( 'group_lesson_id', form.dataset.groupLessonId );
	fd.append( 'recording_link', link );

	try {
		await playerPost( fd );
		toast( link ? 'Ссылка на запись сохранена' : 'Ссылка убрана' );
		window.location.reload();
	} catch ( err ) {
		toast( err.toUserText ? err.toUserText() : err.message, 'error' );
		buttons.forEach( ( b ) => { b.disabled = false; } );
	}
}
