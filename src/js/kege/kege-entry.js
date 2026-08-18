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

import { KEGE_RITUAL_STAGES, clearKegeState, kegeBr, kegeKim, loadKegeState, saveKegeState } from './kege-state.js';

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

/**
 * Куда вернуть ритуал, если сохранённая стадия — 'exam'/'done', а самого экрана
 * на странице нет (попытка уже сдана и сервер снова отдал ритуал). Введённые
 * данные при этом сохраняются: с готовым КИМ возвращаемся сразу к активации.
 */
function ritualFallback( state ) {
	return state.kim ? 'act' : 'entry';
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
		const last = i === slides.length - 1;
		state.slide = i;
		persist();
		slides.forEach( ( s, idx ) => { s.hidden = idx !== i; } );
		prev.hidden = 0 === i;
		next.hidden = last;
		// «Далее» доступна только после пролистывания всей инструкции.
		done.hidden = ! last;
	};
	showSlide( Math.min( state.slide || 0, slides.length - 1 ) );

	prev.addEventListener( 'click', () => showSlide( Math.max( 0, state.slide - 1 ) ) );
	next.addEventListener( 'click', () => showSlide( Math.min( slides.length - 1, state.slide + 1 ) ) );

	// Кнопка отрисована только на последнем слайде (done.hidden = ! last),
	// поэтому дополнительной проверки «дошёл ли до конца» здесь уже не нужно.
	done.addEventListener( 'click', () => {
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

/**
 * Предпросмотр автора: попытки нет и StartAttempt не вызывается — экран экзамена
 * уже отрендерен скрытым (ege-computer.php), поэтому просто раскрываем его и
 * сообщаем kege-exam.js, что пора обновить шапку значениями ритуала.
 *
 * Стадия пишется в состояние: обновление страницы должно вернуть экран
 * экзамена, а не откатить на активацию (сервер здесь ничего не знает —
 * настоящей попытки нет).
 */
function startPreviewExam( root, state, persist ) {
	const exam = document.getElementById( 'kegeExam' );
	if ( ! exam ) { return; }
	state.stage = 'exam';
	persist();
	root.hidden = true;
	document.getElementById( 'kegeFinish' )?.setAttribute( 'hidden', '' );
	exam.hidden = false;
	document.dispatchEvent( new CustomEvent( 'fs-kege-preview-start' ) );
}

function initActStage( root, state, persist, kegeVars, assessmentId, preview ) {
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
		if ( preview ) {
			startPreviewExam( root, state, persist );
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

/** Балл строки: «—», пока задание не оценено — зеркалит `$kegeScore` из finish.php. */
function kegeRowScore( score ) {
	return null === score || undefined === score ? '—' : String( Math.round( score * 100 ) / 100 );
}

/**
 * Перестраивает лист ответов данными, посчитанными сервером (см.
 * `PreviewResultCallbacks`/`KegeResultSheetService::buildFromAnswers()`).
 *
 * Только предпросмотр: реальная сдача перезагружает страницу и получает те же
 * данные уже отрисованными в PHP (`kege/finish.php`) — эта функция дублирует
 * ровно ту разметку (шапка счёта, деление строк на две таблицы пополам)
 * специально для случая, когда перезагрузки не будет и взять готовый HTML
 * неоткуда: в предпросмотре попытки в БД нет.
 *
 * @param {{rows: Array, answered: number, total: number, primary: number,
 *           primary_max: number, secondary: number, secondary_max: number}} sheet
 */
export function renderKegeSheet( sheet ) {
	const cnt = document.querySelector( '#kegeFinCnt b' );
	if ( cnt ) { cnt.textContent = sheet.answered + '/' + sheet.total; }

	const scoreVal = document.getElementById( 'kegeFinScoreVal' );
	const scoreSub = document.getElementById( 'kegeFinScoreSub' );
	if ( scoreVal ) { scoreVal.textContent = sheet.secondary + '/' + sheet.secondary_max; }
	if ( scoreSub ) {
		scoreSub.textContent = 'Первичный балл: ' + kegeRowScore( sheet.primary ) + '/' + kegeRowScore( sheet.primary_max );
	}

	const tables = document.getElementById( 'kegeFinTables' );
	if ( ! tables ) { return; }
	tables.innerHTML = '';

	// Две таблицы рядом (макет): первая половина строк — левая, остаток — правая
	// (зеркалит array_chunk() в finish.php).
	const half   = Math.ceil( sheet.rows.length / 2 );
	const chunks = half > 0 ? [ sheet.rows.slice( 0, half ), sheet.rows.slice( half ) ] : [];

	chunks.forEach( ( chunk ) => {
		const table = document.createElement( 'table' );
		table.className = 'kege-fin-tbl';

		const thead    = document.createElement( 'thead' );
		const headRow  = document.createElement( 'tr' );
		[ '№', 'Балл', 'Ваш\nответ', 'Правильный\nответ' ].forEach( ( label ) => {
			const th = document.createElement( 'th' );
			// textContent + \n вместо <br>: white-space: pre-line у .kege-fin-tbl
			// рендерит перенос так же, как и в теле таблицы (единый способ).
			th.textContent = label;
			headRow.appendChild( th );
		} );
		thead.appendChild( headRow );
		table.appendChild( thead );

		const tbody = document.createElement( 'tbody' );
		chunk.forEach( ( row ) => {
			const tr = document.createElement( 'tr' );

			const n = document.createElement( 'td' );
			n.className   = 'kege-fin-tbl__n';
			n.textContent = row.number;

			const score = document.createElement( 'td' );
			score.textContent = kegeRowScore( row.score );

			const answer = document.createElement( 'td' );
			answer.className   = 'kege-fin-tbl__ans';
			answer.textContent = row.answer;

			const correct = document.createElement( 'td' );
			correct.className   = 'kege-fin-tbl__ans';
			correct.textContent = row.correct;

			tr.append( n, score, answer, correct );
			tbody.appendChild( tr );
		} );
		table.appendChild( tbody );

		tables.appendChild( table );
	} );
}

/**
 * Лист ответов (kege/finish.php): строки таблиц и баллы отдаёт сервер, клиенту
 * остаются тренажёрные номера КИМ/бланка из ритуала и выход со станции.
 */
function initFinishScreen( state ) {
	const finish = document.getElementById( 'kegeFinish' );
	if ( ! finish ) { return; }

	const attemptId = finish.dataset.attemptId || '0';
	const kimEl     = document.getElementById( 'kegeFinKim' );
	const brEl      = document.getElementById( 'kegeFinBr' );

	const syncHead = ( ritual ) => {
		if ( kimEl ) { kimEl.textContent = 'КИМ № ' + kegeKim( ritual, attemptId ); }
		if ( brEl )  { brEl.textContent  = 'БР № ' + kegeBr( ritual, attemptId ); }
	};
	syncHead( state );

	// Предпросмотр автора: лист раскрывается без перезагрузки страницы, уже после
	// того, как ритуал сгенерировал номера (см. kege-exam.js).
	document.addEventListener( 'fs-kege-preview-finish', () => syncHead( loadKegeState() ) );

	document.getElementById( 'kegeFinishBtn' )?.addEventListener( 'click', () => {
		// Лист ответов — последний экран станции: уходим туда же, куда ведёт
		// «Вернуться» шелла, и снимаем состояние ритуала, чтобы следующий заход
		// начинался с номера бланка регистрации.
		clearKegeState();
		window.location.href = document.getElementById( 'kegeApp' )?.dataset.backUrl || '/';
	} );
}

/**
 * Кнопка «Начать заново» на плашке предпросмотра (T-preview-1): сброс ритуала
 * и ответов доступен с любого экрана станции, не только с листа ответов —
 * раньше выйти можно было только дойдя до конца и нажав «Завершить экзамен».
 * Кнопка есть только в предпросмотре (см. ege-computer.php) — гард не нужен.
 */
export function initPreviewRestart() {
	document.getElementById( 'kegePreviewRestart' )?.addEventListener( 'click', () => {
		clearKegeState();
		window.location.reload();
	} );
}

export function initKegeEntry() {
	const root = document.getElementById( 'kegeEntry' );
	const app  = document.getElementById( 'kegeApp' );
	if ( ! app ) { return; }

	const assessmentId = app.dataset.assessmentId;
	const kegeVars      = window.fs_lms_kege_vars;
	const preview       = '1' === app.dataset.preview;

	const state = loadKegeState();
	// Пишем только свои ключи: открытое задание ведёт kege-exam.js, и запись
	// целого объекта затирала бы его значением, устаревшим с момента загрузки.
	const persist = () => saveKegeState( {
		stage: state.stage,
		br:    state.br,
		slide: state.slide,
		kim:   state.kim,
		code:  state.code,
	} );

	initFinishScreen( state );

	if ( ! root ) { return; }

	// Восстановление после обновления страницы: возвращаем ровно тот экран, на
	// котором пользователь был, вместе с уже введёнными бланком, КИМ и кодом.
	// 'exam'/'done' — стадии предпросмотра (в реальном прохождении их держит
	// сервер: активная попытка отдаёт сразу экран экзамена, без ритуала).
	const examScreen   = document.getElementById( 'kegeExam' );
	const finishScreen = document.getElementById( 'kegeFinish' );

	if ( 'exam' === state.stage && examScreen ) {
		startPreviewExam( root, state, persist );
	} else if ( 'done' === state.stage && finishScreen ) {
		root.hidden = true;
		finishScreen.removeAttribute( 'hidden' );
	} else {
		const stage = KEGE_RITUAL_STAGES.includes( state.stage ) ? state.stage : ritualFallback( state );
		showStage( root, stage );
		if ( 'reg' === stage ) { populateRegStage( state, persist ); }
		if ( 'act' === stage ) { populateActStage( state, persist ); }
	}

	initEntryStage( root, state, persist );
	initInstrStage( root, state, persist, () => populateRegStage( state, persist ) );
	initRegStage( root, state, persist );
	initActStage( root, state, persist, kegeVars, assessmentId, preview );
}
