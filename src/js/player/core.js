/**
 * Ядро плеера (T14.5): состояние шагов из серверных панелей (.pstep),
 * навигация prev/next/лента/клавиатура, deep-link ?step=, запись прогресса
 * viewed/completed через AJAX — перенос из frontend/services/lesson-player.js.
 *
 * «Далее» на инлайновых шагах (текст/видео/ручное задание) отмечает шаг
 * пройденным — отдельной кнопки «Отметить пройденным» больше нет (D18).
 */
import { renderStrip } from './strip.js';
import { typeMeta } from './icons.js';

// Только текст/видео отмечаются «viewed» при показе (авто-грейд задач — через submit).
const INLINE = [ 'text', 'video' ];

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

export function initCore() {
	const app = document.getElementById( 'fsPlayerApp' );
	if ( ! app || ! vars ) { return; }

	const panels = Array.from( document.querySelectorAll( '#fsStepRoot .pstep' ) );
	if ( ! panels.length ) { return; }

	const groupLessonId = app.dataset.groupLessonId;
	const prevBtn = document.getElementById( 'fsNavPrev' );
	const nextBtn = document.getElementById( 'fsNavNext' );
	const posEl   = document.getElementById( 'fsNavPos' );
	const scroll  = document.getElementById( 'fsScroll' );

	let active = 0;

	const isAvailable  = ( i ) => !! panels[ i ] && 'locked' !== panels[ i ].dataset.gate;
	const isDone       = ( i ) => [ 'completed', 'failed' ].includes( panels[ i ].dataset.status );
	const isInlineLike = ( p ) => INLINE.includes( p.dataset.stepType ) || '1' === p.dataset.manual;

	function mark( stepKey, status ) {
		if ( isPreview() ) { return Promise.resolve( null ); }
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

	function unlockNext() {
		const n = active + 1;
		if ( panels[ n ] && 'locked' === panels[ n ].dataset.gate ) {
			panels[ n ].dataset.gate = 'available';
		}
		refresh();
	}

	function updateTopbar() {
		const done = panels.filter( ( p ) => 'completed' === p.dataset.status ).length;
		const txt  = document.getElementById( 'fsProgTxt' );
		const bar  = document.getElementById( 'fsProgBar' );
		if ( txt ) { txt.textContent = `Урок · ${ done } из ${ panels.length }`; }
		if ( bar ) { bar.style.width = `${ ( done / panels.length ) * 100 }%`; }
	}

	function updateNav() {
		const last = active === panels.length - 1;
		prevBtn.disabled = 0 === active;
		prevBtn.classList.toggle( 'b-dis', 0 === active );
		const nextOk = ! last && ( isAvailable( active + 1 ) || isInlineLike( panels[ active ] ) );
		nextBtn.disabled = ! nextOk;
		nextBtn.classList.toggle( 'b-dis', ! nextOk );
		if ( posEl ) {
			const p       = panels[ active ];
			const title   = p.dataset.title ? ` · ${ p.dataset.title }` : '';
			posEl.textContent = `Шаг ${ active + 1 } из ${ panels.length } · ${ typeMeta( p.dataset.stepType ).label }${ title }`;
		}
	}

	function refresh() {
		renderStrip( { panels, active, onGoto: show } );
		updateNav();
		updateTopbar();
		refreshListeners.forEach( ( cb ) => cb( core ) );
	}

	function markViewedIfInline() {
		const panel = panels[ active ];
		if ( INLINE.includes( panel.dataset.stepType ) && 'available' === panel.dataset.status ) {
			setStatus( active, 'viewed' );
			mark( panel.dataset.step, 'viewed' );
		}
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
		refresh();
		if ( scroll ) { scroll.scrollTop = 0; }
		markViewedIfInline();
		showListeners.forEach( ( cb ) => cb( panel, core ) );
	}

	prevBtn.addEventListener( 'click', () => show( active - 1 ) );

	nextBtn.addEventListener( 'click', () => {
		const panel = panels[ active ];
		if ( isInlineLike( panel ) && ! isDone( active ) ) {
			setStatus( active, 'completed' );
			mark( panel.dataset.step, 'completed' );
			unlockNext();
		}
		if ( active < panels.length - 1 ) {
			show( active + 1 );
		} else {
			refresh();
		}
	} );

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
		if ( di >= 0 && isAvailable( di ) ) { start = di; }
	}
	active = start >= 0 ? start : 0;
	panels[ active ].hidden = false;
	refresh();
	markViewedIfInline();
	showListeners.forEach( ( cb ) => cb( panels[ active ], core ) );

	return core;
}
