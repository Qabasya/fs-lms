/**
 * Lesson player — пошаговое прохождение урока (★, T1.5.12). Pure-JS, DOM-driven:
 * данные шага читаются из data-атрибутов панелей/степпера. Один шаг на экран,
 * «Назад»/«Далее», запись прогресса (viewed/completed) через AJAX, гейтинг по data-gate.
 * Интерактивные task-шаги управляются через task-widget.js (Этап 6, Phase E).
 */

import { initTaskWidget } from '../components/task-widget.js';

const vars  = window.fs_lms_player_vars;
// 'task' намеренно исключён: авто-грейд task-шагов происходит через submit, не viewed.
const INLINE = [ 'text', 'video', 'material' ];

export function initLessonPlayer() {
	const root = document.querySelector( '.fs-player[data-group-lesson-id]' );
	if ( ! root || ! vars ) { return; }

	const groupLessonId = root.dataset.groupLessonId;
	const panels = Array.from( root.querySelectorAll( '.fs-player__panel' ) );
	const navs   = Array.from( root.querySelectorAll( '.fs-player__stepnav' ) );
	if ( ! panels.length ) { return; }

	const prevBtn = root.querySelector( '.fs-player__prev' );
	const nextBtn = root.querySelector( '.fs-player__next' );
	const count   = root.querySelector( '[data-count]' );
	let active = 0;

	const widgetMap = new Map(); // panel index → { collectAnswer }

	const isAvailable = ( i ) => !! panels[ i ] && 'available' === panels[ i ].dataset.gate;

	function setStatus( i, status ) {
		panels[ i ].dataset.status = status;
		if ( navs[ i ] ) { navs[ i ].dataset.status = status; }
	}

	function unlockNext() {
		const n = active + 1;
		if ( panels[ n ] && 'locked' === panels[ n ].dataset.gate ) {
			panels[ n ].dataset.gate = 'available';
			if ( navs[ n ] ) { navs[ n ].dataset.gate = 'available'; }
		}
		updateNav();
	}

	function updateNav() {
		prevBtn.disabled = 0 === active;
		nextBtn.disabled = ! isAvailable( active + 1 );
		if ( count ) { count.textContent = `${ active + 1 } / ${ panels.length }`; }
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

	function maybeInitWidget( i ) {
		if ( widgetMap.has( i ) ) { return; }
		const panel = panels[ i ];
		if ( 'task' !== panel.dataset.type ) { return; }
		const widget = initTaskWidget( panel );
		if ( widget ) { widgetMap.set( i, widget ); }
	}

	function show( i ) {
		if ( i < 0 || i >= panels.length || ! isAvailable( i ) ) { return; }
		panels[ active ].hidden = true;
		if ( navs[ active ] ) { navs[ active ].classList.remove( 'is-active' ); }
		active = i;
		panels[ active ].hidden = false;
		if ( navs[ active ] ) { navs[ active ].classList.add( 'is-active' ); }
		updateNav();
		markViewedIfInline();
		maybeInitWidget( active );
	}

	prevBtn.addEventListener( 'click', () => show( active - 1 ) );
	nextBtn.addEventListener( 'click', () => show( active + 1 ) );
	navs.forEach( ( nav, i ) => nav.addEventListener( 'click', () => show( i ) ) );

	// "Отметить пройденным" — только для inline и ручных заданий.
	root.querySelectorAll( '.fs-player__complete' ).forEach( ( btn ) => {
		btn.addEventListener( 'click', () => {
			setStatus( active, 'completed' );
			btn.disabled = true;
			mark( btn.dataset.step, 'completed' ).then( () => unlockNext() );
		} );
	} );

	// Проверка интерактивного задания (делегированный обработчик).
	root.addEventListener( 'click', async ( e ) => {
		const btn = e.target.closest( '.fs-task-submit' );
		if ( ! btn || btn.disabled ) { return; }

		const widget = widgetMap.get( active );
		if ( ! widget ) { return; }

		const panel    = panels[ active ];
		const answer   = widget.collectAnswer();
		btn.disabled   = true;

		const fd = new FormData();
		fd.append( 'action',          vars.submit_task_action );
		fd.append( 'security',        vars.submit_task_nonce );
		fd.append( 'group_lesson_id', groupLessonId );
		fd.append( 'step_key',        btn.dataset.step );
		fd.append( 'answer',          answer );

		let res;
		try {
			const r = await fetch( vars.ajax_url, { method: 'POST', body: fd } );
			res     = await r.json();
		} catch {
			btn.disabled = false;
			return;
		}

		const resultEl = panel.querySelector( '.fs-task-result' );

		if ( ! res?.success ) {
			if ( resultEl ) { resultEl.textContent = res?.data?.message || 'Ошибка. Попробуйте ещё раз.'; }
			btn.disabled = false;
			return;
		}

		const data = res.data;

		// Фидбэк
		if ( resultEl ) {
			resultEl.textContent     = data.is_correct
				? `✓ Верно! (${ data.score }/${ data.max_score })`
				: `✗ Неверно. (${ data.score }/${ data.max_score })`;
			resultEl.dataset.correct = data.is_correct ? '1' : '0';
		}

		// Счётчик попыток
		const indicator = panel.querySelector( '.fs-attempt-indicator' );
		if ( indicator && data.max_attempts > 0 ) {
			indicator.dataset.used   = String( data.attempts_used );
			indicator.textContent    = `Попыток использовано: ${ data.attempts_used } из ${ data.max_attempts }`;
		}

		// Подсказка
		if ( data.reveal_hint ) {
			const hint = panel.querySelector( '.fs-hint' );
			if ( hint ) { hint.open = true; }
		}

		// Статус шага
		const status = data.step_status;
		if ( 'completed' === status || 'failed' === status ) {
			setStatus( active, status );
			btn.disabled = true;
			if ( 'completed' === status ) { unlockNext(); }
		} else {
			btn.disabled = false;
		}
	} );

	// init — показать первый доступный шаг
	panels.forEach( ( p ) => { p.hidden = true; } );
	const start = panels.findIndex( ( _, i ) => isAvailable( i ) );
	active = start >= 0 ? start : 0;
	panels[ active ].hidden = false;
	if ( navs[ active ] ) { navs[ active ].classList.add( 'is-active' ); }
	updateNav();
	markViewedIfInline();
	maybeInitWidget( active );
}
