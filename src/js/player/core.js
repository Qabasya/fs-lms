/**
 * Ядро плеера (T14.5): состояние шагов из серверных панелей (.pstep),
 * переход по шагам (рейка, клавиатура ←/→), deep-link ?step=, запись
 * прогресса через AJAX.
 *
 * Tasks.md З4: обязательны только шаги, которые сдаются кнопкой (задание с
 * автопроверкой, работа, контрольная). Лекция, видео, трансляция и ручное
 * задание засчитываются при открытии и ничего не блокируют; решённое задание
 * открывает все следующие шаги до очередного обязательного — то же правило,
 * что у сервера (LessonGateResolver::requireSolvedUpTo).
 */
import { toast } from './shell.js';

// Шаги без сдачи: гейт не держат (сервер пропускает их по типу).
const INLINE = [ 'text', 'video', 'broadcast' ];

const vars = window.fs_lms_player_vars;

const showListeners = [];
const refreshListeners = [];
let core = null;

/** Подписка шаговых модулей (task/work/video…) на показ панели. */
export function onPanelShow( cb ) {
	showListeners.push( cb );
}

/** Подписка на каждое обновление состояния (смена шага/статуса) — рейка и т.п. */
export function onRefresh( cb ) {
	refreshListeners.push( cb );
}

/** Доступ к ядру для шаговых модулей (после initCore). */
export function getCore() {
	return core;
}

/** Preview-плеер курса (Фаза 5, D3/D4): без сохранения/проверки/прогресса. */
export function isPreview() {
	return '1' === document.getElementById( 'fsPlayerApp' )?.dataset.preview;
}

/** Teacher-режим плеера (Этап 2, ★): преподаватель смотрит урок своей группы
 *  без ученика — прогресс шага не пишется (нет person, писать некуда/некому). */
export function isTeacherMode() {
	return '1' === document.getElementById( 'fsPlayerApp' )?.dataset.teacher;
}

export function initCore() {
	const app = document.getElementById( 'fsPlayerApp' );
	if ( ! app || ! vars ) { return; }

	const panels = Array.from( document.querySelectorAll( '#fsStepRoot .pstep' ) );
	if ( ! panels.length ) { return; }

	const groupLessonId = app.dataset.groupLessonId;
	const lessonEnd     = document.getElementById( 'fsLessonEnd' );
	const player        = document.getElementById( 'fsPlayer' );

	let active = 0;

	const isAvailable  = ( i ) => !! panels[ i ] && 'locked' !== panels[ i ].dataset.gate;
	const isDone       = ( i ) => [ 'completed', 'failed' ].includes( panels[ i ].dataset.status );
	const isInlineLike = ( p ) => INLINE.includes( p.dataset.stepType ) || '1' === p.dataset.manual;

	// Виртуальный шаг («Трансляция» / «Запись занятия») строит плеер: прогресс по нему не пишем и не считаем.
	const isVirtual = ( p ) => '1' === p.dataset.virtual;

	function mark( stepKey, status ) {
		if ( isPreview() || isTeacherMode() ) { return Promise.resolve( null ); }
		const fd = new FormData();
		fd.append( 'action', vars.actions.markStep );
		fd.append( 'security', vars.nonces.markStep );
		fd.append( 'group_lesson_id', groupLessonId );
		fd.append( 'step_key', stepKey );
		fd.append( 'status', status );
		return fetch( vars.ajax_url, { method: 'POST', body: fd } )
			.then( ( r ) => r.json() )
			.catch( () => null );
	}

	function setStatus( i, status ) {
		panels[ i ].dataset.status = status;
	}

	/** Шаг решён — открываем следующие за ним шаги до очередного обязательного включительно. */
	function unlockAfter( i ) {
		for ( let n = i + 1; n < panels.length; n++ ) {
			if ( 'locked' === panels[ n ].dataset.gate ) {
				panels[ n ].dataset.gate = 'available';
			}
			if ( ! isInlineLike( panels[ n ] ) ) { break; }
		}
	}

	function unlockNext() {
		unlockAfter( active );
		refresh();
	}

	function updateTopbar() {
		const counted = panels.filter( ( p ) => ! isVirtual( p ) );
		const done    = counted.filter( ( p ) => 'completed' === p.dataset.status ).length;
		const txt     = document.getElementById( 'fsProgTxt' );
		const bar     = document.getElementById( 'fsProgBar' );
		if ( txt ) { txt.textContent = `Урок · ${ done } из ${ counted.length }`; }
		if ( bar ) { bar.style.setProperty( '--progress', `${ counted.length ? ( done / counted.length ) * 100 : 0 }%` ); }
	}

	function refresh() {
		// Конец урока (выход к курсу / следующий урок) — под последним шагом.
		if ( lessonEnd ) { lessonEnd.hidden = active !== panels.length - 1; }
		updateTopbar();
		refreshListeners.forEach( ( cb ) => cb( core ) );
	}

	/** Шаг без сдачи засчитывается, как только его открыли. */
	function completeIfInline() {
		const panel = panels[ active ];
		if ( ! isInlineLike( panel ) || isDone( active ) ) { return; }
		setStatus( active, 'completed' );
		if ( ! isVirtual( panel ) ) { mark( panel.dataset.step, 'completed' ); }
		unlockAfter( active );
	}

	function show( i ) {
		if ( i < 0 || i >= panels.length || ! isAvailable( i ) ) { return; }
		// Направление перехода — для анимации въезда панели (CSS step-slide-*
		// в _strip.scss; display-toggle через hidden перезапускает анимацию).
		const dir = i > active ? 'fwd' : 'back';
		panels[ active ].hidden = true;
		active = i;
		const panel = panels[ active ];
		panel.classList.remove( 'step-anim-fwd', 'step-anim-back' );
		panel.classList.add( 'step-anim-' + dir );
		panel.hidden = false;
		completeIfInline();
		refresh();
		// Новый шаг — с начала контента (шапка прокручивается вместе со страницей).
		if ( player && window.scrollY > player.offsetTop ) {
			window.scrollTo( { top: player.offsetTop } );
		}
		showListeners.forEach( ( cb ) => cb( panel, core ) );
	}

	// Клавиатура ←/→ (не при фокусе в полях ввода и не под модалкой).
	document.addEventListener( 'keydown', ( e ) => {
		const tag = ( e.target.tagName || '' ).toLowerCase();
		if ( [ 'textarea', 'input', 'select' ].includes( tag ) || document.querySelector( '.cp-dim' ) ) { return; }
		if ( 'ArrowRight' === e.key ) { show( active + 1 ); }
		if ( 'ArrowLeft' === e.key ) { show( active - 1 ); }
	} );

	core = {
		panels,
		show,
		mark,
		unlockNext,
		refresh,
		setStatus: ( i, status ) => { setStatus( i, status ); refresh(); },
		activeIndex: () => active,
		groupLessonId,
	};

	// Стартовый шаг: deep-link ?step= (если доступен), иначе первый доступный.
	let start = panels.findIndex( ( _, i ) => isAvailable( i ) );
	const deepStep = app.dataset.activeStep || '';
	if ( deepStep ) {
		const di = panels.findIndex( ( p ) => p.dataset.step === deepStep );
		if ( di >= 0 && isAvailable( di ) ) {
			start = di;
		} else if ( di >= 0 ) {
			// Ссылка вела к работе (из «Моих оценок», дедлайнов), но шаг закрыт гейтом —
			// без пояснения ученик не понял бы, почему открылось начало урока.
			toast( 'Эта работа откроется после предыдущих шагов урока.', 'error' );
		}
	}
	active = start >= 0 ? start : 0;
	panels[ active ].hidden = false;
	completeIfInline();
	refresh();
	showListeners.forEach( ( cb ) => cb( panels[ active ], core ) );

	return core;
}
