/**
 * Экран экзамена станции КЕГЭ: таймер, боковой навигатор заданий, ответ
 * (текст/таблица). Переиспользует общие примитивы попытки из
 * frontend/services/assessment.js (тот же AJAX-контракт: attempt_id/task_id/
 * answer_text, action saveAttemptAnswer, nonce startAttempt) — не дублирует
 * логику autosave/таймера, только адаптирует их под разметку станции.
 */
import { saveAnswer, debounce, startCountdown } from '../frontend/services/assessment.js';
import { kegeBr, kegeKim, loadKegeState, setKegeStage, setKegeTask } from './kege-state.js';
import { renderKegeSheet } from './kege-entry.js';

function toast( msg ) {
	const el = document.getElementById( 'kegeToast' );
	if ( ! el ) { return; }
	el.textContent = msg;
	el.classList.add( 'show' );
	clearTimeout( toast._t );
	toast._t = setTimeout( () => el.classList.remove( 'show' ), 2400 );
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

/**
 * Табличный ответ в одну строку answerText: ячейки через '|', строки через
 * перевод. Подписи строк и колонок в формат не входят — они заданы номером
 * задания (tableShapeFor) и восстанавливаются при разборе, а не хранятся.
 */
function serializeTable( grid ) {
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
	const attemptId    = app.dataset.attemptId || '0';
	const assessmentId = app.dataset.assessmentId;
	const deadlineAt   = app.dataset.deadline;
	// Предпросмотр автора: попытки нет — ответы никуда не уходят, отсчёт не идёт,
	// «завершить» просто показывает финальный экран (см. ege-computer.php).
	const preview      = '1' === app.dataset.preview;
	let   ritual       = loadKegeState();

	const savedAnswers = new Map(); // taskId (string) -> answerText

	/* ── Шапка: КИМ/бланк (тренажёрные значения) + таймер ── */
	const kimEl = document.getElementById( 'kegeHeadKim' );
	const brEl  = document.getElementById( 'kegeHeadBr' );
	const syncHead = () => {
		if ( kimEl ) { kimEl.textContent = 'КИМ № ' + kegeKim( ritual, attemptId ); }
		if ( brEl )  { brEl.textContent  = 'БР № ' + kegeBr( ritual, attemptId ); }
	};
	syncHead();

	const timerEl = document.getElementById( 'kegeTimer' );
	if ( preview ) {
		// Статичный лимит вместо обратного отсчёта — предпросмотр не идёт по времени.
		const limit = Number( app.dataset.timeLimit || 0 );
		if ( timerEl ) {
			const h = String( Math.floor( limit / 60 ) ).padStart( 2, '0' );
			const m = String( limit % 60 ).padStart( 2, '0' );
			timerEl.textContent = `${ h }:${ m }:00`;
		}
		// Ритуал входа проходится уже после инициализации экрана — обновляем шапку.
		document.addEventListener( 'fs-kege-preview-start', () => {
			ritual = loadKegeState();
			syncHead();
		} );
	} else {
		startCountdown( timerEl, deadlineAt, {
			nowAt:    app.dataset.now,
			onExpire: () => { void submitAttempt( true ); },
		} );
	}

	/* ── Навигатор заданий ── */
	const numBtns = Array.from( document.querySelectorAll( '#kegeNums .kege-numb' ) );
	const order   = numBtns.map( ( b ) => b.dataset.kegeN );
	let current   = 'i';

	const cntEl = document.getElementById( 'kegeCnt' );
	const syncCount = () => {
		if ( cntEl ) { cntEl.textContent = String( savedAnswers.size ); }
	};

	// Панели заданий отрендерены сервером и не меняются — обходим DOM один раз,
	// дальше переключение видимости идёт по готовому списку.
	const panels = Array.from( document.querySelectorAll( '.kege-t-body[data-kege-panel]' ) );

	// data-kege-n на кнопках сайдбара — порядковый номер (1..N), а не WP post ID
	// задания, поэтому «сохранено»-статус ищем через таблицу taskId → номер.
	const taskIdToN = new Map();
	panels.forEach( ( p ) => {
		if ( p.dataset.taskId ) { taskIdToN.set( String( p.dataset.taskId ), p.dataset.kegePanel ); }
	} );

	const markSaved = ( taskId, hasAnswer ) => {
		const n = taskIdToN.get( String( taskId ) );
		if ( ! n ) { return; }
		const btn = numBtns.find( ( b ) => b.dataset.kegeN === String( n ) );
		if ( btn ) { btn.classList.toggle( 'kege-numb--saved', hasAnswer ); }
	};

	/* ── Сохранение ответа ───────────────────────────────────────────────────── */

	/**
	 * Привязка поля(ей) текущего задания — единственный владелец несохранённого
	 * ввода. Панель ответа перестраивается при каждом переключении задания, поэтому
	 * ответ обязан уехать на сервер ДО того, как поле исчезнет из DOM (и до сдачи
	 * работы): именно этого не хватало, когда ответ «то сохранялся, то нет».
	 *
	 * `collect()` отдаёт значение задания одной строкой в том виде, в каком оно
	 * ложится в answerText (текст / таблица / JSON составного задания);
	 * `inflight` — значение, запись которого уже отправлена и ещё не подтверждена
	 * (чтобы blur и клик по кнопке не слали один и тот же ответ дважды).
	 *
	 * @type {{taskId: string, collect: (function(): string), statusEl: HTMLElement, inflight: ?string}|null}
	 */
	let binding = null;

	/**
	 * Очередь записи. Дебаунс ввода, blur и кнопка «Сохранить ответ» срабатывают
	 * почти одновременно — параллельные запросы легли бы в БД в непредсказуемом
	 * порядке, поэтому выстраиваем их в цепочку, а не пускаем наперегонки.
	 */
	let saveChain = Promise.resolve( true );

	/** Ответ задания, подтверждённый сервером. */
	const storedAnswer = ( taskId ) => savedAnswers.get( String( taskId ) ) || '';

	/** Запись ответа: в предпросмотре — только индикатор, без AJAX и записи в БД. */
	const persistAnswer = ( taskId, text, statusEl ) => {
		saveChain = saveChain
			.then( () => {
				if ( preview ) {
					if ( statusEl ) { statusEl.textContent = '✓ (не сохраняется)'; }
					return true;
				}
				return saveAnswer( kegeVars, attemptId, taskId, text, statusEl );
			} )
			.catch( () => false );

		return saveChain;
	};

	/** Есть ли в поле ответ, которого ещё нет на сервере. */
	const isDirty = () => {
		if ( null === binding ) { return false; }
		const text = binding.collect();
		return text !== storedAnswer( binding.taskId ) && text !== binding.inflight;
	};

	/**
	 * Немедленная запись текущего поля. Значение снимается синхронно, до первого
	 * `await`, поэтому вызывать можно и перед перестроением панели ответа.
	 *
	 * Отметка «сохранено», счётчик и локальная копия ответа обновляются ТОЛЬКО по
	 * подтверждению сервера: раньше они ставились оптимистично, и упавший запрос
	 * оставлял задание помеченным как сданное.
	 */
	const flushAnswer = async () => {
		if ( ! isDirty() ) { return; }

		const current = binding;
		const text    = current.collect();

		current.inflight = text;
		const saved = await persistAnswer( current.taskId, text, current.statusEl );
		if ( current.inflight === text ) { current.inflight = null; }
		if ( ! saved ) { return; }

		if ( text ) {
			savedAnswers.set( String( current.taskId ), text );
		} else {
			savedAnswers.delete( String( current.taskId ) );
		}
		markSaved( current.taskId, savedAnswers.has( String( current.taskId ) ) );
		syncCount();
	};

	// Автосохранение по ходу ввода — как на générique-странице попытки. Кнопка
	// «Сохранить ответ» остаётся (её ждёт ученик), но ответ больше не зависит от
	// того, нажали её или просто ушли на следующее задание.
	const queueAnswer = debounce( () => { void flushAnswer(); }, 1200 );

	/**
	 * Уход со страницы (закрытие вкладки, обновление, переход по ссылке): обычный
	 * fetch браузер вправе оборвать, поэтому несохранённое дописываем маячком —
	 * тем же экшеном и нонсом, что и обычный autosave.
	 */
	window.addEventListener( 'pagehide', () => {
		if ( preview || ! kegeVars || ! navigator.sendBeacon || ! isDirty() ) { return; }

		const fd = new FormData();
		fd.append( 'action', kegeVars.actions.saveAttemptAnswer );
		fd.append( 'security', kegeVars.nonces.startAttempt );
		fd.append( 'attempt_id', String( attemptId ) );
		fd.append( 'task_id', String( binding.taskId ) );
		fd.append( 'answer_text', binding.collect() );
		navigator.sendBeacon( kegeVars.ajax_url, fd );
	} );

	/**
	 * Вешает автосохранение на поля задания: ввод — с дебаунсом, уход из поля и
	 * Enter — немедленно. Кнопка «Сохранить ответ» дёргает тот же flushAnswer().
	 *
	 * @param {HTMLInputElement[]} inputs Поля ответа задания
	 */
	const autosaveOn = ( inputs ) => {
		inputs.forEach( ( input ) => {
			input.addEventListener( 'input', queueAnswer );
			input.addEventListener( 'blur', () => { void flushAnswer(); } );
			input.addEventListener( 'keydown', ( e ) => { if ( 'Enter' === e.key ) { void flushAnswer(); } } );
		} );
	};

	/**
	 * Чипы файлов задания живут в нижней панели рядом с полем ответа (макет),
	 * а серверный шаблон рендерит их внутри панели задания — берём копию, чтобы
	 * оригинал пережил перестроение нижней панели при переключении заданий.
	 */
	function filesFor( taskPanel ) {
		const src = taskPanel.querySelector( '[data-kege-files]' );
		if ( ! src ) { return null; }
		const clone = src.cloneNode( true );
		clone.removeAttribute( 'data-kege-files' );
		clone.hidden = false;
		return clone;
	}

	function buildAnswerUi( n ) {
		const bottom = document.getElementById( 'kegeExBottom' );
		const panelWrap = document.getElementById( 'kegeAnsPanel' );
		// Обе площадки чистим всегда: скрытая панель прошлого задания оставалась в
		// DOM со своими полями, и её было видно при возврате на табличное задание.
		bottom.innerHTML    = '';
		panelWrap.innerHTML = '';
		binding             = null;

		if ( 'i' === n ) {
			panelWrap.hidden = true;
			return;
		}

		const taskPanel = panels.find( ( p ) => p.dataset.kegePanel === String( n ) );
		if ( ! taskPanel ) {
			panelWrap.hidden = true;
			return;
		}

		const files = filesFor( taskPanel );
		if ( files ) { bottom.appendChild( files ); }

		const taskId     = taskPanel.dataset.taskId;
		const shape      = taskPanel.dataset.answerShape;
		const taskNumber = Number( taskPanel.dataset.taskNumber || 0 );
		const saved      = savedAnswers.get( taskId ) || '';

		const statusEl = document.createElement( 'span' );
		statusEl.className = 'kege-save-status';

		if ( 'triple' === shape ) {
			// Задача 3: составное задание — 3 поля ответа (19/20/21), сбор в JSON,
			// одно сохранение на родительский task_id.
			panelWrap.hidden = false;

			const subs = String( taskPanel.dataset.tripleSubs || '' ).split( ',' ).filter( Boolean );
			let savedMap = {};
			try { savedMap = saved ? JSON.parse( saved ) : {}; } catch ( _e ) { savedMap = {}; }
			if ( 'object' !== typeof savedMap || null === savedMap ) { savedMap = {}; }

			// Запись может отвечать за один номер блока 19-21 — тогда поле ответа
			// одно, и заголовок панели в множественном числе читался бы неверно.
			const head = document.createElement( 'div' );
			head.className = 'kege-ap-head';
			head.textContent = subs.length > 1
				? 'Ответы на задания ' + subs.join( ', ' )
				: 'Ответ на задание ' + subs.join( '' );
			panelWrap.appendChild( head );

			const inputs = {};
			subs.forEach( ( key ) => {
				const row = document.createElement( 'label' );
				row.className = 'kege-ap-triple-row';
				const lab = document.createElement( 'span' );
				lab.className = 'kege-ap-triple-lab';
				lab.textContent = key;
				const input = document.createElement( 'input' );
				input.value = String( savedMap[ key ] ?? '' );
				row.append( lab, input );
				panelWrap.appendChild( row );
				inputs[ key ] = input;
			} );

			const actions = document.createElement( 'div' );
			actions.className = 'kege-ap-actions';
			const saveBtn = document.createElement( 'button' );
			saveBtn.type = 'button';
			saveBtn.className = 'kege-save2';
			saveBtn.textContent = 'Сохранить ответ';
			actions.append( saveBtn );
			panelWrap.append( actions, statusEl );

			binding = {
				taskId,
				statusEl,
				inflight: null,
				collect() {
					const out = {};
					let any = false;
					subs.forEach( ( key ) => {
						const v = inputs[ key ].value.trim();
						out[ key ] = v;
						if ( v ) { any = true; }
					} );
					return any ? JSON.stringify( out ) : '';
				},
			};
			autosaveOn( subs.map( ( key ) => inputs[ key ] ) );
			saveBtn.addEventListener( 'click', () => { void flushAnswer(); } );
		} else if ( 'table' === shape ) {
			panelWrap.hidden = false;

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

			binding = {
				taskId,
				statusEl,
				inflight: null,
				collect() {
					const grid = inputs.map( ( row ) => row.map( ( i ) => i.value.trim() ) );
					return grid.some( ( row ) => row.some( Boolean ) ) ? serializeTable( grid ) : '';
				},
			};
			autosaveOn( inputs.flat() );
			saveBtn.addEventListener( 'click', () => { void flushAnswer(); } );
			// «Очистить» — тоже сохранение (пустым ответом), а не только правка полей:
			// раньше отметка снималась локально, до подтверждения сервера.
			clearBtn.addEventListener( 'click', () => {
				inputs.forEach( ( row ) => row.forEach( ( i ) => { i.value = ''; } ) );
				void flushAnswer();
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

			binding = {
				taskId,
				statusEl,
				inflight: null,
				collect: () => input.value.trim(),
			};
			autosaveOn( [ input ] );
			// Клик — немедленная запись: под дебаунсом кнопка «Сохранить ответ»
			// откладывала отправку на полторы секунды, и уход со страницы или
			// досрочная сдача в этом окне теряли ответ.
			saveBtn.addEventListener( 'click', () => { void flushAnswer(); } );

			bottom.append( input, saveBtn, statusEl );
		}
	}

	function showPanel( n ) {
		// Досохраняем уходящее задание ДО перестроения панели: flushAnswer снимает
		// значение синхронно, поэтому ждать его здесь не нужно — запись уже в очереди.
		void flushAnswer();

		panels.forEach( ( p ) => {
			p.hidden = p.dataset.kegePanel !== String( n );
		} );
		numBtns.forEach( ( b ) => b.classList.toggle( 'kege-numb--cur', b.dataset.kegeN === String( n ) ) );
		current = n;
		setKegeTask( n, attemptId );
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

	/* ── Масштаб текста задания (A+ / A− / A): ступени задаёт SCSS, JS только ── */
	/* переключает класс на .kege-ex — собственных стилей не выставляет. */
	const FS_STEPS   = [ 'kege-ex--fs-1', 'kege-ex--fs-2', 'kege-ex--fs-3', 'kege-ex--fs-4', 'kege-ex--fs-5' ];
	const FS_DEFAULT = 2;
	let   fsIdx      = FS_DEFAULT;
	const applyFs = () => FS_STEPS.forEach( ( cls, i ) => exam.classList.toggle( cls, i === fsIdx ) );
	const setFs = ( i ) => {
		fsIdx = Math.min( FS_STEPS.length - 1, Math.max( 0, i ) );
		applyFs();
	};
	applyFs();
	document.getElementById( 'kegeFsUp' )?.addEventListener( 'click', () => setFs( fsIdx + 1 ) );
	document.getElementById( 'kegeFsDown' )?.addEventListener( 'click', () => setFs( fsIdx - 1 ) );
	document.getElementById( 'kegeFsReset' )?.addEventListener( 'click', () => setFs( FS_DEFAULT ) );

	/* ── «Скачать все файлы» на вкладке «i»: раскрывает сводный список ссылок ── */
	/* Кнопки и списка нет, когда у контрольной нет ни одного файла (см. exam.php). */
	const dlBtn  = document.getElementById( 'kegeDlAll' );
	const dlList = document.getElementById( 'kegeDlList' );
	if ( dlBtn && dlList ) {
		dlBtn.addEventListener( 'click', () => {
			const open = dlList.hidden;
			dlList.hidden = ! open;
			dlBtn.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
		} );
	}

	/* ── Досрочное завершение ── */
	let submitting = false;

	/**
	 * Лист ответов предпросмотра: ответы отправляются в том же виде, в каком
	 * они легли бы в answer_text реальной попытки (savedAnswers — уже готовые
	 * строки, см. persistAnswer/flushAnswer), сервер сличает их с эталоном тем
	 * же кодом, что и для настоящего листа ({@see KegeResultSheetService}).
	 *
	 * @returns {Promise<?object>} Данные листа либо null (экшен не опубликован
	 *                              модулем/выключен, сеть недоступна, доступ запрещён)
	 */
	async function fetchPreviewSheet() {
		const action = kegeVars?.actions?.previewResult;
		if ( ! action ) { return null; }

		try {
			const fd = new FormData();
			fd.append( 'action', action );
			fd.append( 'security', kegeVars.nonces.startAttempt );
			fd.append( 'assessment_id', String( assessmentId ) );
			savedAnswers.forEach( ( text, taskId ) => { fd.append( `answers[${ taskId }]`, text ); } );

			const res  = await fetch( kegeVars.ajax_url, { method: 'POST', body: fd } );
			const json = await res.json();
			return json.success ? json.data : null;
		} catch ( e ) {
			return null;
		}
	}

	async function submitAttempt( auto ) {
		// Повторные нажатия и авто-сдача по таймеру не должны накладываться друг
		// на друга: второй запрос всё равно упрётся в «Попытка уже завершена».
		if ( submitting ) { return; }
		submitting = true;

		if ( preview ) {
			// Досохраняем последний ввод — как и в реальной сдаче, иначе набранный,
			// но ещё не отправленный ответ не попадёт в лист.
			try { await flushAnswer(); } catch ( e ) { /* лист не блокируем */ }

			// Настоящей попытки нет — сервер посчитать лист по БД не может, поэтому
			// шлём ему накопленные в этой вкладке ответы напрямую (см.
			// PreviewResultCallbacks). Пусто — action не опубликован модулем
			// (выключен/удалён) либо запрос не удался: лист остаётся тем, что уже
			// отрисовал PHP при загрузке страницы (KegeSheetDTO::blank(), одни «—»).
			const sheet = await fetchPreviewSheet();
			if ( sheet ) { renderKegeSheet( sheet ); }

			// Стадию фиксируем, чтобы обновление страницы не вернуло экзамен.
			setKegeStage( 'done' );
			exam.hidden = true;
			document.getElementById( 'kegeFinish' )?.removeAttribute( 'hidden' );
			// Лист ответов инициализирован до ритуала (страница не перезагружается),
			// поэтому просим kege-entry.js обновить шапку номерами КИМ/бланка.
			document.dispatchEvent( new CustomEvent( 'fs-kege-preview-finish' ) );
			submitting = false;
			return;
		}
		if ( ! kegeVars ) {
			submitting = false;
			return;
		}

		// Досохраняем последний ввод: ученик мог набрать ответ и сразу нажать
		// «Завершить», не дождавшись автосохранения.
		try { await flushAnswer(); } catch ( e ) { /* сдачу не блокируем */ }

		try {
			const fd = new FormData();
			fd.append( 'action', kegeVars.actions.submitAttempt );
			fd.append( 'security', kegeVars.nonces.submitAttempt );
			fd.append( 'attempt_id', String( attemptId ) );
			const res  = await fetch( kegeVars.ajax_url, { method: 'POST', body: fd } );
			const json = await res.json();
			if ( json.success ) {
				window.location.reload();
				return;
			}
			submitting = false;
			if ( ! auto ) { toast( json.data || 'Не удалось завершить экзамен.' ); }
		} catch ( e ) {
			submitting = false;
			if ( ! auto ) { toast( 'Сетевая ошибка при отправке.' ); }
		}
	}

	async function confirmFinish() {
		// Сначала дописываем текущее задание — иначе в диалоге «Дано ответов»
		// не учитывался бы только что набранный, ещё не сохранённый ответ.
		try { await flushAnswer(); } catch ( e ) { /* счётчик не критичен */ }

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

	document.getElementById( 'kegeFinishEarly' )?.addEventListener( 'click', () => { void confirmFinish(); } );

	/* ── Предзагрузка сохранённых ответов (T15.10): переиспользуем getAttemptResult, ── */
	/* чтобы боковые «сохранено»-индикаторы и поля не были пустыми после перезагрузки. */
	( async () => {
		if ( preview || ! kegeVars?.actions?.getAttemptResult ) { return; }
		try {
			const fd = new FormData();
			fd.append( 'action', kegeVars.actions.getAttemptResult );
			fd.append( 'security', kegeVars.nonces.startAttempt );
			fd.append( 'attempt_id', String( attemptId ) );
			const res  = await fetch( kegeVars.ajax_url, { method: 'POST', body: fd } );
			const json = await res.json();
			if ( json.success && Array.isArray( json.data?.answers ) ) {
				// Признак «ученик уже что-то набрал» снимаем ДО заливки ответов:
				// после неё поле сравнивалось бы с только что пришедшей копией и
				// пустое поле выглядело бы изменённым.
				const untouched = ! isDirty();

				json.data.answers.forEach( ( a ) => {
					if ( a.answerText ) {
						savedAnswers.set( String( a.taskId ), a.answerText );
						markSaved( a.taskId, true );
					}
				} );
				syncCount();
				// Панель уже построена (showPanel идёт синхронно, а ответ приходит
				// позже) — перерисовываем её сохранённым значением. Но не поверх
				// свежего ввода: иначе предзагрузка затирала бы набранный ответ.
				if ( 'i' !== current && untouched ) { buildAnswerUi( current ); }
			}
		} catch ( e ) {
			// Тихо игнорируем — предзагрузка необязательна, autosave продолжит работать.
		}
	} )();

	// Обновление страницы не должно сбрасывать экзамен на вкладку инструкции:
	// открываем то задание, на котором пользователь остановился.
	const sameAttempt = String( ritual.taskAttempt ?? '' ) === String( attemptId );
	const savedTask   = String( ritual.task ?? '' );
	showPanel( sameAttempt && order.includes( savedTask ) ? savedTask : 'i' );
}
