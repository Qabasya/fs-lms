/**
 * Ритуал станции КЕГЭ: вход (номер бланка) → инструкция (слайды) →
 * регистрация (номер КИМ) → активация → реальный старт попытки.
 *
 * Полностью на клиенте (localStorage) — бэкенду ничего не известно, пока
 * пользователь не нажмёт «Начать экзамен»: только тогда уходит настоящий
 * AJAX-запрос startAttempt (тот же эндпоинт, что и у générique attempt.php).
 * КИМ/бланк/код активации — тренажёрные значения без серверного смысла,
 * персистятся в localStorage, чтобы пережить перезагрузку страницы.
 */

const LS_KEY = 'fsKegeSimV1';

function loadState() {
	try {
		return Object.assign( { stage: 'entry', br: [ '', '', '' ], slide: 0, kim: null, code: null }, JSON.parse( localStorage.getItem( LS_KEY ) ) || {} );
	} catch ( e ) {
		return { stage: 'entry', br: [ '', '', '' ], slide: 0, kim: null, code: null };
	}
}

function toast( msg ) {
	const el = document.getElementById( 'kegeToast' );
	if ( ! el ) { return; }
	el.textContent = msg;
	el.classList.add( 'show' );
	clearTimeout( toast._t );
	toast._t = setTimeout( () => el.classList.remove( 'show' ), 2400 );
}

function showStage( root, name ) {
	root.querySelectorAll( '[data-kege-stage]' ).forEach( ( el ) => {
		el.hidden = el.dataset.kegeStage !== name;
	} );
}

function randDigits( n ) {
	return Array.from( { length: n }, () => Math.floor( Math.random() * 10 ) ).join( '' );
}

function initEntryStage( root, state, persist ) {
	const ids  = [ 'kegeBr0', 'kegeBr1', 'kegeBr2' ];
	const ins  = ids.map( ( id ) => document.getElementById( id ) );
	const next = document.getElementById( 'kegeEntryNext' );
	const fill = document.getElementById( 'kegeFillDemo' );
	if ( ins.some( ( el ) => ! el ) || ! next ) { return; }

	const sync = () => {
		state.br = ins.map( ( el ) => el.value );
		persist();
		next.disabled = state.br.join( '' ).length !== 13;
	};

	ins.forEach( ( el, i ) => {
		el.value = state.br[ i ] || '';
		el.addEventListener( 'input', () => {
			el.value = el.value.replace( /\D/g, '' );
			if ( el.value.length === Number( el.maxLength ) && i < ins.length - 1 ) {
				ins[ i + 1 ].focus();
			}
			sync();
		} );
	} );
	sync();

	if ( fill ) {
		fill.addEventListener( 'click', () => {
			ins[ 0 ].value = '1';
			ins[ 1 ].value = '111111';
			ins[ 2 ].value = '111111';
			sync();
		} );
	}

	next.addEventListener( 'click', () => {
		state.stage = 'instr';
		persist();
		showStage( root, 'instr' );
	} );
}

function initInstrStage( root, state, persist, onDone ) {
	const slides = Array.from( document.querySelectorAll( '#kegeSlides .kege-slide' ) );
	const prev   = document.getElementById( 'kegeSlidePrev' );
	const next   = document.getElementById( 'kegeSlideNext' );
	const done   = document.getElementById( 'kegeInstrNext' );
	if ( ! slides.length || ! prev || ! next || ! done ) { return; }

	const showSlide = ( i ) => {
		state.slide = i;
		persist();
		slides.forEach( ( s, idx ) => { s.hidden = idx !== i; } );
		prev.hidden = 0 === i;
		next.hidden = i === slides.length - 1;
	};
	showSlide( Math.min( state.slide || 0, slides.length - 1 ) );

	prev.addEventListener( 'click', () => showSlide( Math.max( 0, state.slide - 1 ) ) );
	next.addEventListener( 'click', () => showSlide( Math.min( slides.length - 1, state.slide + 1 ) ) );

	done.addEventListener( 'click', () => {
		if ( state.slide < slides.length - 1 ) {
			showSlide( state.slide + 1 );
			return;
		}
		state.stage = 'reg';
		persist();
		showStage( root, 'reg' );
		onDone();
	} );
}

function fillBrDisplay( ids, br ) {
	ids.forEach( ( id, i ) => {
		const el = document.getElementById( id );
		if ( el ) { el.textContent = br[ i ] || ''; }
	} );
}

function fillKimDisplay( ids, kim ) {
	if ( ! kim ) { return; }
	const parts = [ kim.slice( 0, 4 ), kim.slice( 4, 7 ), kim.slice( 7 ) ];
	ids.forEach( ( id, i ) => {
		const el = document.getElementById( id );
		if ( el ) { el.textContent = parts[ i ] || ''; }
	} );
}

function populateRegStage( state, persist ) {
	fillBrDisplay( [ 'kegeRegBr0', 'kegeRegBr1', 'kegeRegBr2' ], state.br );

	const cellIds = [ 'kegeKim0', 'kegeKim1', 'kegeKim2' ];
	const okBtn   = document.getElementById( 'kegeRegOk' );

	if ( state.kim ) {
		fillKimDisplay( cellIds, state.kim );
		if ( okBtn ) { okBtn.disabled = false; }
		return;
	}

	if ( okBtn ) { okBtn.disabled = true; }
	const cells = cellIds.map( ( id ) => document.getElementById( id ) );
	if ( cells.some( ( el ) => ! el ) ) { return; }

	const anim = setInterval( () => {
		cells[ 0 ].textContent = randDigits( 4 );
		cells[ 1 ].textContent = randDigits( 3 );
		cells[ 2 ].textContent = randDigits( 1 );
	}, 65 );

	setTimeout( () => {
		clearInterval( anim );
		state.kim = '2510' + String( 1000 + Math.floor( Math.random() * 9000 ) );
		persist();
		fillKimDisplay( cellIds, state.kim );
		if ( okBtn ) { okBtn.disabled = false; }
		toast( 'Номер КИМ сгенерирован' );
	}, 1400 );
}

function populateActStage( state, persist ) {
	fillBrDisplay( [ 'kegeActBr0', 'kegeActBr1', 'kegeActBr2' ], state.br );
	fillKimDisplay( [ 'kegeActKim0', 'kegeActKim1', 'kegeActKim2' ], state.kim );

	if ( ! state.code ) {
		state.code = String( 1000 + Math.floor( Math.random() * 9000 ) );
		persist();
	}
	const hint = document.getElementById( 'kegeActCode' );
	if ( hint ) { hint.textContent = state.code; }
}

function initRegStage( root, state, persist ) {
	const edit = document.getElementById( 'kegeRegEdit' );
	const ok   = document.getElementById( 'kegeRegOk' );
	if ( edit ) {
		edit.addEventListener( 'click', () => { state.stage = 'entry'; persist(); showStage( root, 'entry' ); } );
	}
	if ( ok ) {
		ok.addEventListener( 'click', () => {
			state.stage = 'act';
			persist();
			showStage( root, 'act' );
			populateActStage( state, persist );
		} );
	}
}

function initActStage( root, state, persist, kegeVars, assessmentId ) {
	const edit  = document.getElementById( 'kegeActEdit' );
	const input = document.getElementById( 'kegeCodeInput' );
	const err   = document.getElementById( 'kegeCodeErr' );
	const start = document.getElementById( 'kegeStartBtn' );
	if ( ! input || ! err || ! start ) { return; }

	if ( edit ) {
		edit.addEventListener( 'click', () => { state.stage = 'entry'; persist(); showStage( root, 'entry' ); } );
	}

	input.addEventListener( 'input', () => {
		input.value = input.value.replace( /\D/g, '' );
		input.classList.remove( 'kege-code-in--err' );
		err.hidden = true;
	} );

	const doStart = async () => {
		if ( input.value !== state.code ) {
			input.classList.add( 'kege-code-in--err' );
			err.hidden = false;
			return;
		}
		if ( ! kegeVars ) { return; }
		start.disabled = true;
		try {
			const fd = new FormData();
			fd.append( 'action', kegeVars.actions.startAttempt );
			fd.append( 'security', kegeVars.nonces.startAttempt );
			fd.append( 'assessment_id', String( assessmentId ) );
			// Задача 5: контекст группы/занятия из URL (from_gid/from_gl) — привязка попытки.
			const kegeQs   = new URLSearchParams( window.location.search );
			const kegeGid  = kegeQs.get( 'from_gid' );
			const kegeGl   = kegeQs.get( 'from_gl' );
			if ( kegeGid ) { fd.append( 'group_id', kegeGid ); }
			if ( kegeGl ) { fd.append( 'group_lesson_id', kegeGl ); }
			const res  = await fetch( kegeVars.ajax_url, { method: 'POST', body: fd } );
			const json = await res.json();
			if ( json.success ) {
				window.location.reload();
			} else {
				toast( json.data || 'Не удалось начать экзамен.' );
				start.disabled = false;
			}
		} catch ( e ) {
			toast( 'Сетевая ошибка.' );
			start.disabled = false;
		}
	};

	start.addEventListener( 'click', doStart );
	input.addEventListener( 'keydown', ( e ) => { if ( 'Enter' === e.key ) { doStart(); } } );
}

/** Тренажёрная контрольная сумма — детерминирована по attemptId/КИМ/бланку, без серверного смысла. */
function computeChecksum( seed ) {
	let h = 7;
	for ( const c of seed ) { h = ( h * 31 + c.charCodeAt( 0 ) ) >>> 0; }
	const d = String( h ).padStart( 10, '0' ).slice( 0, 10 );
	return d.match( /../g ).join( '-' );
}

function initFinishScreen( state ) {
	const finish = document.getElementById( 'kegeFinish' );
	if ( ! finish ) { return; }

	const sumEl = document.getElementById( 'kegeFinSum' );
	if ( sumEl ) {
		const seed = ( finish.dataset.attemptId || '' ) + ( state.kim || '' ) + state.br.join( '' );
		sumEl.textContent = computeChecksum( seed || String( Date.now() ) );
	}

	const retry = document.getElementById( 'kegeRetryBtn' );
	if ( retry ) {
		retry.addEventListener( 'click', () => {
			localStorage.removeItem( LS_KEY );
			window.location.reload();
		} );
	}
}

export function initKegeEntry() {
	const root = document.getElementById( 'kegeEntry' );
	const app  = document.getElementById( 'kegeApp' );
	if ( ! app ) { return; }

	const assessmentId = app.dataset.assessmentId;
	const kegeVars      = window.fs_lms_kege_vars;

	const state   = loadState();
	const persist = () => localStorage.setItem( LS_KEY, JSON.stringify( state ) );

	initFinishScreen( state );

	if ( ! root ) { return; }

	showStage( root, state.stage );
	if ( 'reg' === state.stage ) { populateRegStage( state, persist ); }
	if ( 'act' === state.stage ) { populateActStage( state, persist ); }

	initEntryStage( root, state, persist );
	initInstrStage( root, state, persist, () => populateRegStage( state, persist ) );
	initRegStage( root, state, persist );
	initActStage( root, state, persist, kegeVars, assessmentId );
}
