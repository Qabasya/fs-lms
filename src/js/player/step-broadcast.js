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
	document.querySelectorAll( '[data-recording-pick]' ).forEach( initPicker );
	document.querySelectorAll( '[data-recording-track]' ).forEach( trackRecordingOpen );

	if ( ! vars?.actions?.setRecording ) { return; }

	document.querySelectorAll( '[data-recording-form]' ).forEach( ( form ) => {
		const input = form.querySelector( '[name="recording"]' );
		const save  = ( link ) => submit( form, link );

		form.addEventListener( 'submit', ( e ) => {
			e.preventDefault();
			save( input.value.trim() );
		} );
		form.querySelector( '[data-recording-clear]' )?.addEventListener( 'click', () => save( '' ) );
	} );

	document.querySelectorAll( '[data-recording-skip]' ).forEach( ( btn ) => {
		btn.addEventListener( 'click', () => submitSkip( btn ) );
	} );
}

/**
 * Выбор загруженной записи из списка подходящих (модуль VideoLibrary): действие и nonce приходят из разметки формы.
 */
function initPicker( form ) {
	form.addEventListener( 'submit', async ( e ) => {
		e.preventDefault();
		const recordingId = form.querySelector( '[name="recording_id"]' )?.value;
		if ( ! recordingId ) {
			toast( 'Выберите запись из списка', 'error' );
			return;
		}

		const buttons = form.querySelectorAll( 'button' );
		buttons.forEach( ( b ) => { b.disabled = true; } );

		const fd = new FormData();
		fd.append( 'action', form.dataset.action );
		fd.append( 'security', form.dataset.nonce );
		fd.append( 'group_lesson_id', form.dataset.groupLessonId );
		fd.append( 'recording_id', recordingId );

		try {
			await playerPost( fd );
			toast( 'Запись привязана к занятию' );
			window.location.reload();
		} catch ( err ) {
			toast( err.toUserText ? err.toUserText() : err.message, 'error' );
			buttons.forEach( ( b ) => { b.disabled = false; } );
		}
	} );
}

async function submit( form, link ) {
	const buttons = form.querySelectorAll( 'button' );
	buttons.forEach( ( b ) => { b.disabled = true; } );

	const fd = new FormData();
	fd.append( 'action', vars.actions.setRecording );
	fd.append( 'security', vars.nonces.setRecording );
	fd.append( 'group_lesson_id', form.dataset.groupLessonId );
	fd.append( 'recording', link );

	try {
		await playerPost( fd );
		toast( link ? 'Ссылка на запись сохранена' : 'Ссылка убрана' );
		window.location.reload();
	} catch ( err ) {
		toast( err.toUserText ? err.toUserText() : err.message, 'error' );
		buttons.forEach( ( b ) => { b.disabled = false; } );
	}
}

/** Отмена (или возврат) шага «Запись занятия»: записи у этого занятия не будет. */
async function submitSkip( btn ) {
	btn.disabled = true;

	const fd = new FormData();
	fd.append( 'action', vars.actions.setRecording );
	fd.append( 'security', vars.nonces.setRecording );
	fd.append( 'group_lesson_id', btn.dataset.groupLessonId );
	fd.append( 'recording_skipped', btn.dataset.recordingSkip );

	try {
		await playerPost( fd );
		toast( '1' === btn.dataset.recordingSkip ? 'Шаг записи отменён' : 'Шаг записи возвращён' );
		window.location.reload();
	} catch ( err ) {
		toast( err.toUserText ? err.toUserText() : err.message, 'error' );
		btn.disabled = false;
	}
}

/**
 * Открытие записи занятия (клик по ссылке или первый запуск видео) — в ленту «Активность» преподавателя.
 * Без ожидания и без ошибок: сбой записи не должен мешать смотреть запись; повторы отсекает сервер.
 */
function trackRecordingOpen( box ) {
	if ( ! vars?.actions?.logActivity ) { return; }

	let sent = false;
	const send = () => {
		if ( sent ) { return; }
		sent = true;

		const fd = new FormData();
		fd.append( 'action', vars.actions.logActivity );
		fd.append( 'security', vars.nonces.logActivity );
		fd.append( 'type', 'recording' );
		fd.append( 'group_lesson_id', box.dataset.groupLessonId );
		playerPost( fd ).catch( () => {} );
	};

	box.querySelectorAll( 'a[href]' ).forEach( ( a ) => a.addEventListener( 'click', send ) );
	// `play` не всплывает — слушаем на захвате.
	box.addEventListener( 'play', send, true );
}
