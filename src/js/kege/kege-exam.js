/**
 * Экран экзамена станции КЕГЭ: таймер, боковой навигатор заданий, ответ
 * (текст/таблица). Переиспользует общие примитивы попытки из
 * frontend/services/assessment.js (тот же AJAX-контракт: attempt_id/task_id/
 * answer_text, action saveAttemptAnswer, nonce startAttempt) — не дублирует
 * логику autosave/таймера, только адаптирует их под разметку станции.
 */
import { saveAnswer, debounce, startCountdown } from '../frontend/services/assessment.js';

const LS_KEY = 'fsKegeSimV1';

function loadRitualState() {
	try {
		return JSON.parse( localStorage.getItem( LS_KEY ) ) || {};
	} catch ( e ) {
		return {};
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

/** Тренажёрный номер бланка/КИМ: берём из ритуала входа, иначе — детерминированный фолбэк от attemptId. */
function fallbackBr( attemptId ) {
	return String( attemptId ).padStart( 12, '0' ).slice( -12 );
}
function fallbackKim( attemptId ) {
	return '25' + String( attemptId ).padStart( 6, '0' ).slice( -6 );
}

/** Официальные номера заданий с табличным (многозначным) ответом (T15.10) — форма из настоящего КЕГЭ по информатике. */
function tableShapeFor( taskNumber ) {
	if ( 25 === taskNumber ) {
		return { cols: [ 'Число', 'Результат деления' ], rows: 5 };
	}
	if ( 27 === taskNumber ) {
		return { cols: [ '1', '2' ], rows: [ '1', '2' ] };
	}
	return { cols: [ 'Значение' ], rows: 3 };
}

function serializeTable( rowLabels, cols, grid ) {
	return grid.map( ( row ) => row.join( '|' ) ).join( '\n' );
}

function parseTable( text ) {
	if ( ! text ) { return []; }
	return text.split( '\n' ).map( ( row ) => row.split( '|' ) );
}

export function initKegeExam() {
	const app = document.getElementById( 'kegeApp' );
	const exam = document.getElementById( 'kegeExam' );
	if ( ! app || ! exam ) { return; }

	const kegeVars     = window.fs_lms_kege_vars;
	const attemptId    = app.dataset.attemptId;
	const assessmentId = app.dataset.assessmentId;
	const deadlineAt   = app.dataset.deadline;
	const ritual       = loadRitualState();

	const savedAnswers = new Map(); // taskId (string) -> answerText

	/* ── Шапка: КИМ/бланк (тренажёрные значения) + таймер ── */
	const kimEl = document.getElementById( 'kegeHeadKim' );
	const brEl  = document.getElementById( 'kegeHeadBr' );
	if ( kimEl ) { kimEl.textContent = 'КИМ № ' + ( ritual.kim || fallbackKim( attemptId ) ); }
	if ( brEl )  { brEl.textContent  = 'БР № ' + ( ( ritual.br || [] ).join( '' ) || fallbackBr( attemptId ) ); }

	const timerEl = document.getElementById( 'kegeTimer' );
	startCountdown( timerEl, deadlineAt, {
		onExpire: () => { void submitAttempt( true ); },
	} );

	/* ── Навигатор заданий ── */
	const numBtns = Array.from( document.querySelectorAll( '#kegeNums .kege-numb' ) );
	const order   = numBtns.map( ( b ) => b.dataset.kegeN );
	let current   = 'i';

	const cntEl = document.getElementById( 'kegeCnt' );
	const syncCount = () => {
		if ( cntEl ) { cntEl.textContent = String( savedAnswers.size ); }
	};

	// data-kege-n на кнопках сайдбара — порядковый номер (1..N), а не WP post ID
	// задания, поэтому «сохранено»-статус ищем через таблицу taskId → номер.
	const taskIdToN = new Map();
	document.querySelectorAll( '.kege-t-body[data-kege-panel]' ).forEach( ( p ) => {
		if ( p.dataset.taskId ) { taskIdToN.set( String( p.dataset.taskId ), p.dataset.kegePanel ); }
	} );

	const markSaved = ( taskId, hasAnswer ) => {
		const n = taskIdToN.get( String( taskId ) );
		if ( ! n ) { return; }
		const btn = numBtns.find( ( b ) => b.dataset.kegeN === String( n ) );
		if ( btn ) { btn.classList.toggle( 'kege-numb--saved', hasAnswer ); }
	};

	function buildAnswerUi( n ) {
		const bottom = document.getElementById( 'kegeExBottom' );
		const panelWrap = document.getElementById( 'kegeAnsPanel' );
		bottom.innerHTML = '';

		if ( 'i' === n ) {
			panelWrap.hidden = true;
			return;
		}

		const taskPanel = document.querySelector( '.kege-t-body[data-kege-panel="' + n + '"]' );
		if ( ! taskPanel ) {
			panelWrap.hidden = true;
			return;
		}

		const taskId     = taskPanel.dataset.taskId;
		const shape      = taskPanel.dataset.answerShape;
		const taskNumber = Number( taskPanel.dataset.taskNumber || 0 );
		const saved      = savedAnswers.get( taskId ) || '';

		const statusEl = document.createElement( 'span' );
		statusEl.className = 'kege-save-status';

		if ( 'table' === shape ) {
			panelWrap.hidden = false;
			panelWrap.innerHTML = '';

			const { cols, rows } = tableShapeFor( taskNumber );
			const rowLabels = Array.isArray( rows ) ? rows : Array.from( { length: rows }, ( _, i ) => String( i + 1 ) );
			const savedGrid = parseTable( saved );

			const head = document.createElement( 'div' );
			head.className = 'kege-ap-head';
			head.textContent = 'Введите значения в таблицу';
			panelWrap.appendChild( head );

			const table = document.createElement( 'table' );
			table.className = 'kege-ap-table';
			const headRow = document.createElement( 'tr' );
			headRow.appendChild( document.createElement( 'th' ) );
			cols.forEach( ( c ) => {
				const th = document.createElement( 'th' );
				th.textContent = c;
				headRow.appendChild( th );
			} );
			table.appendChild( headRow );

			const inputs = [];
			rowLabels.forEach( ( label, ri ) => {
				const tr = document.createElement( 'tr' );
				const th = document.createElement( 'th' );
				th.textContent = label;
				tr.appendChild( th );
				const rowInputs = [];
				cols.forEach( ( c, ci ) => {
					const td = document.createElement( 'td' );
					const input = document.createElement( 'input' );
					input.value = ( savedGrid[ ri ] && savedGrid[ ri ][ ci ] ) || '';
					td.appendChild( input );
					tr.appendChild( td );
					rowInputs.push( input );
				} );
				inputs.push( rowInputs );
				table.appendChild( tr );
			} );
			panelWrap.appendChild( table );

			const actions = document.createElement( 'div' );
			actions.className = 'kege-ap-actions';
			const clearBtn = document.createElement( 'button' );
			clearBtn.type = 'button';
			clearBtn.className = 'kege-clear-btn';
			clearBtn.textContent = 'Очистить';
			const saveBtn = document.createElement( 'button' );
			saveBtn.type = 'button';
			saveBtn.className = 'kege-save2';
			saveBtn.textContent = 'Сохранить ответ';
			actions.append( clearBtn, saveBtn );
			panelWrap.append( actions, statusEl );

			const persistTable = async () => {
				const grid = inputs.map( ( row ) => row.map( ( i ) => i.value.trim() ) );
				const text = grid.some( ( row ) => row.some( Boolean ) ) ? serializeTable( rowLabels, cols, grid ) : '';
				await saveAnswer( kegeVars, attemptId, taskId, text, statusEl );
				if ( text ) { savedAnswers.set( taskId, text ); } else { savedAnswers.delete( taskId ); }
				markSaved( taskId, savedAnswers.has( taskId ) );
				syncCount();
			};
			saveBtn.addEventListener( 'click', persistTable );
			clearBtn.addEventListener( 'click', () => {
				inputs.forEach( ( row ) => row.forEach( ( i ) => { i.value = ''; } ) );
				savedAnswers.delete( taskId );
				markSaved( taskId, false );
				syncCount();
				saveAnswer( kegeVars, attemptId, taskId, '', statusEl );
			} );
		} else {
			panelWrap.hidden = true;

			const input = document.createElement( 'input' );
			input.className = 'kege-ans-in';
			input.spellcheck = false;
			input.value = saved;

			const saveBtn = document.createElement( 'button' );
			saveBtn.type = 'button';
			saveBtn.className = 'kege-save-btn';
			saveBtn.textContent = 'Сохранить ответ';

			const persist = debounce( async () => {
				const v = input.value.trim();
				await saveAnswer( kegeVars, attemptId, taskId, v, statusEl );
				if ( v ) { savedAnswers.set( taskId, v ); } else { savedAnswers.delete( taskId ); }
				markSaved( taskId, savedAnswers.has( taskId ) );
				syncCount();
			}, 1500 );

			saveBtn.addEventListener( 'click', persist );
			input.addEventListener( 'keydown', ( e ) => { if ( 'Enter' === e.key ) { persist(); } } );

			bottom.append( input, saveBtn, statusEl );
		}
	}

	function showPanel( n ) {
		document.querySelectorAll( '.kege-t-body[data-kege-panel]' ).forEach( ( p ) => {
			p.hidden = p.dataset.kegePanel !== String( n );
		} );
		numBtns.forEach( ( b ) => b.classList.toggle( 'kege-numb--cur', b.dataset.kegeN === String( n ) ) );
		current = n;
		buildAnswerUi( n );
		const scroll = document.getElementById( 'kegeTaskScroll' );
		if ( scroll ) { scroll.scrollTop = 0; }
	}

	numBtns.forEach( ( b ) => b.addEventListener( 'click', () => showPanel( b.dataset.kegeN ) ) );

	document.getElementById( 'kegeExPrev' )?.addEventListener( 'click', () => {
		const i = order.indexOf( String( current ) );
		if ( i > 0 ) { showPanel( order[ i - 1 ] ); }
	} );
	document.getElementById( 'kegeExNext' )?.addEventListener( 'click', () => {
		const i = order.indexOf( String( current ) );
		if ( i >= 0 && i < order.length - 1 ) { showPanel( order[ i + 1 ] ); }
	} );

	document.getElementById( 'kegeScrUp' )?.addEventListener( 'click', () => {
		document.getElementById( 'kegeNums' )?.scrollBy( { top: -180, behavior: 'smooth' } );
	} );
	document.getElementById( 'kegeScrDn' )?.addEventListener( 'click', () => {
		document.getElementById( 'kegeNums' )?.scrollBy( { top: 180, behavior: 'smooth' } );
	} );

	/* ── Досрочное завершение ── */
	async function submitAttempt( auto ) {
		if ( ! kegeVars ) { return; }
		try {
			const fd = new FormData();
			fd.append( 'action', kegeVars.actions.submitAttempt );
			fd.append( 'security', kegeVars.nonces.submitAttempt );
			fd.append( 'attempt_id', String( attemptId ) );
			const res  = await fetch( kegeVars.ajax_url, { method: 'POST', body: fd } );
			const json = await res.json();
			if ( json.success ) {
				window.location.reload();
			} else if ( ! auto ) {
				toast( json.data || 'Не удалось завершить экзамен.' );
			}
		} catch ( e ) {
			if ( ! auto ) { toast( 'Сетевая ошибка при отправке.' ); }
		}
	}

	function confirmFinish() {
		const ovl = document.createElement( 'div' );
		ovl.className = 'kege-ovl';

		const card = document.createElement( 'div' );
		card.className = 'kege-mcard';

		const h4 = document.createElement( 'h4' );
		h4.textContent = 'Завершить экзамен досрочно?';
		const totalTasks = order.length - 1; // order includes 'i' (страница инструкции)
		const p = document.createElement( 'p' );
		p.textContent = 'Дано ответов: ' + savedAnswers.size + ' из ' + totalTasks + '. После завершения изменить ответы будет невозможно.';

		const row = document.createElement( 'div' );
		row.className = 'kege-m-row';
		const cancel = document.createElement( 'button' );
		cancel.type = 'button';
		cancel.className = 'kege-m-ghost';
		cancel.textContent = 'Отмена';
		const ok = document.createElement( 'button' );
		ok.type = 'button';
		ok.className = 'kege-btn kege-btn--red';
		ok.textContent = 'Завершить экзамен';

		row.append( cancel, ok );
		card.append( h4, p, row );
		ovl.appendChild( card );
		document.body.appendChild( ovl );

		cancel.addEventListener( 'click', () => ovl.remove() );
		ok.addEventListener( 'click', () => { ovl.remove(); void submitAttempt( false ); } );
	}

	document.getElementById( 'kegeFinishEarly' )?.addEventListener( 'click', confirmFinish );

	/* ── Предзагрузка сохранённых ответов (T15.10): переиспользуем getAttemptResult, ── */
	/* чтобы боковые «сохранено»-индикаторы и поля не были пустыми после перезагрузки. */
	( async () => {
		if ( ! kegeVars?.actions?.getAttemptResult ) { return; }
		try {
			const fd = new FormData();
			fd.append( 'action', kegeVars.actions.getAttemptResult );
			fd.append( 'security', kegeVars.nonces.startAttempt );
			fd.append( 'attempt_id', String( attemptId ) );
			const res  = await fetch( kegeVars.ajax_url, { method: 'POST', body: fd } );
			const json = await res.json();
			if ( json.success && Array.isArray( json.data?.answers ) ) {
				json.data.answers.forEach( ( a ) => {
					if ( a.answerText ) {
						savedAnswers.set( String( a.taskId ), a.answerText );
						markSaved( a.taskId, true );
					}
				} );
				syncCount();
				if ( 'i' !== current ) { buildAnswerUi( current ); }
			}
		} catch ( e ) {
			// Тихо игнорируем — предзагрузка необязательна, autosave продолжит работать.
		}
	} )();

	showPanel( 'i' );
}
