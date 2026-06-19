/**
 * Lesson player — пошаговое прохождение урока (★, T1.5.12). Pure-JS, DOM-driven:
 * данные шага читаются из data-атрибутов панелей/степпера. Один шаг на экран,
 * «Назад»/«Далее», запись прогресса (viewed/completed) через AJAX, гейтинг по data-gate.
 */

const vars = window.fs_lms_player_vars;
const INLINE = [ 'text', 'video', 'material', 'task' ];

export function initLessonPlayer() {
	const root = document.querySelector( '.fs-player[data-group-lesson-id]' );
	if ( ! root || ! vars ) {
		return;
	}

	const groupLessonId = root.dataset.groupLessonId;
	const panels = Array.from( root.querySelectorAll( '.fs-player__panel' ) );
	const navs   = Array.from( root.querySelectorAll( '.fs-player__stepnav' ) );
	if ( ! panels.length ) {
		return;
	}

	const prevBtn = root.querySelector( '.fs-player__prev' );
	const nextBtn = root.querySelector( '.fs-player__next' );
	const count   = root.querySelector( '[data-count]' );
	let active = 0;

	const isAvailable = ( i ) => !! panels[ i ] && 'available' === panels[ i ].dataset.gate;

	function setStatus( i, status ) {
		panels[ i ].dataset.status = status;
		if ( navs[ i ] ) {
			navs[ i ].dataset.status = status;
		}
	}

	function unlockNext() {
		const n = active + 1;
		if ( panels[ n ] && 'locked' === panels[ n ].dataset.gate ) {
			panels[ n ].dataset.gate = 'available';
			if ( navs[ n ] ) {
				navs[ n ].dataset.gate = 'available';
			}
		}
		updateNav();
	}

	function updateNav() {
		prevBtn.disabled = 0 === active;
		nextBtn.disabled = ! isAvailable( active + 1 );
		if ( count ) {
			count.textContent = `${ active + 1 } / ${ panels.length }`;
		}
	}

	function mark( stepKey, status ) {
		const fd = new FormData();
		fd.append( 'action', vars.action );
		fd.append( 'security', vars.nonce );
		fd.append( 'group_lesson_id', groupLessonId );
		fd.append( 'step_key', stepKey );
		fd.append( 'status', status );
		return fetch( vars.ajax_url, { method: 'POST', body: fd } )
			.then( ( r ) => r.json() )
			.catch( () => null );
	}

	function markViewedIfInline() {
		const panel = panels[ active ];
		if ( INLINE.includes( panel.dataset.type ) && 'available' === panel.dataset.status ) {
			setStatus( active, 'viewed' );
			mark( panel.dataset.step, 'viewed' );
		}
	}

	function show( i ) {
		if ( i < 0 || i >= panels.length || ! isAvailable( i ) ) {
			return;
		}
		panels[ active ].hidden = true;
		if ( navs[ active ] ) {
			navs[ active ].classList.remove( 'is-active' );
		}
		active = i;
		panels[ active ].hidden = false;
		if ( navs[ active ] ) {
			navs[ active ].classList.add( 'is-active' );
		}
		updateNav();
		markViewedIfInline();
	}

	prevBtn.addEventListener( 'click', () => show( active - 1 ) );
	nextBtn.addEventListener( 'click', () => show( active + 1 ) );
	navs.forEach( ( nav, i ) => nav.addEventListener( 'click', () => show( i ) ) );

	root.querySelectorAll( '.fs-player__complete' ).forEach( ( btn ) => {
		btn.addEventListener( 'click', () => {
			setStatus( active, 'completed' );
			btn.disabled = true;
			mark( btn.dataset.step, 'completed' ).then( () => unlockNext() );
		} );
	} );

	// init — показать первый доступный шаг
	panels.forEach( ( p ) => { p.hidden = true; } );
	const start = panels.findIndex( ( _, i ) => isAvailable( i ) );
	active = start >= 0 ? start : 0;
	panels[ active ].hidden = false;
	if ( navs[ active ] ) {
		navs[ active ].classList.add( 'is-active' );
	}
	updateNav();
	markViewedIfInline();
}
