/**
 * Assessment (attempt) service — timer, autosave, submit.
 * Pure-JS function pattern (no jQuery).
 */

import { escapeHtml, debounce as debounceUtil } from '../../common/utils.js';

const vars = window.fs_lms_assessment_vars;

/**
 * Общие примитивы (таймер/debounce/autosave/рендер результата) — переиспользуются
 * бандлом станции КЕГЭ (src/js/kege/, T15.10) через именованный импорт, чтобы не
 * дублировать логику попытки. `saveAnswer()`/`renderResultTask()` не зависят от
 * модульного `vars` — принимают его параметром, поэтому годятся для любого
 * бандла, локализующего свой собственный `fs_lms_*_vars` с теми же полями
 * (ajax_url, actions.saveAttemptAnswer, nonces.startAttempt).
 *
 * escHtml/debounce — реэкспорт канона из common/utils.js (своих копий не заводим).
 */
export { escapeHtml as escHtml };

/** Наивная дата сервера ('Y-m-d H:i:s') → timestamp. */
function parseAt( value ) {
	return new Date( String( value ).replace( ' ', 'T' ) ).getTime();
}

/**
 * Остаток до дедлайна словами таймера. Часы показываем только когда они есть:
 * у контрольной на 3 ч 55 мин формат MM:SS давал «235:00» вместо «03:55:00».
 */
function formatRemaining( seconds ) {
	const total = Math.max( 0, seconds );
	const mm    = String( Math.floor( total / 60 ) % 60 ).padStart( 2, '0' );
	const ss    = String( total % 60 ).padStart( 2, '0' );

	if ( total < 3600 ) { return `${ mm }:${ ss }`; }

	return `${ String( Math.floor( total / 3600 ) ).padStart( 2, '0' ) }:${ mm }:${ ss }`;
}

/**
 * Универсальный обратный отсчёт: обновляет displayEl каждую секунду, вызывает
 * onExpire() РОВНО ОДИН РАЗ по достижении нуля. Возвращает id интервала (для
 * очистки) либо null, если отсчёт закончился уже на первом тике.
 *
 * `nowAt` — серверное «сейчас» в том же наивном формате, что и дедлайн. Оба
 * значения приходят в часовом поясе сайта, а `Date` разбирает такую строку как
 * местное время браузера: у пользователя в другом поясе отсчёт уезжал на часы —
 * вплоть до мгновенной авто-сдачи попытки. Поэтому остаток считаем от серверного
 * времени плюс то, что прожила страница; без `nowAt` поведение прежнее.
 *
 * @param {HTMLElement} displayEl  Элемент таймера
 * @param {string}      deadlineAt Дедлайн попытки ('Y-m-d H:i:s')
 * @param {Object}      options    onExpire / warnAt / nowAt
 */
export function startCountdown( displayEl, deadlineAt, { onExpire, warnAt = 60, nowAt = '' } = {} ) {
	if ( ! displayEl || ! deadlineAt ) { return null; }

	const deadline  = parseAt( deadlineAt );
	const serverNow = nowAt ? parseAt( nowAt ) : Date.now();
	const openedAt  = Date.now();
	if ( Number.isNaN( deadline ) || Number.isNaN( serverNow ) ) { return null; }

	const remainingSec = () => Math.floor( ( deadline - serverNow - ( Date.now() - openedAt ) ) / 1000 );

	let timer   = null;
	let expired = false;

	const tick = () => {
		const remaining = remainingSec();
		if ( remaining <= 0 ) {
			displayEl.textContent = formatRemaining( 0 );
			displayEl.classList.add( 'fs-timer--expired' );
			// Без снятия интервала onExpire дёргался бы каждую секунду — на странице
			// экзамена это повторная сдача попытки раз в секунду.
			if ( null !== timer ) { clearInterval( timer ); timer = null; }
			if ( expired ) { return; }
			expired = true;
			if ( onExpire ) { onExpire(); }
			return;
		}
		displayEl.textContent = formatRemaining( remaining );
		if ( remaining < warnAt ) {
			displayEl.classList.add( 'fs-timer--warning' );
		}
	};

	tick();
	if ( expired ) { return null; }

	timer = setInterval( tick, 1000 );
	return timer;
}

/** Countdown timer that auto-submits when deadline is reached. */
function initTimer( form, deadlineAt, nowAt ) {
	const display = document.getElementById( 'fs-timer-display' );
	if ( ! display ) { return null; }
	return startCountdown( display, deadlineAt, {
		nowAt,
		onExpire: () => form.dispatchEvent( new Event( 'submit' ) ),
	} );
}

/** Debounce helper — канон из common/utils.js. */
export const debounce = debounceUtil;

/**
 * Autosave a single answer via AJAX. `vars` — localized bundle vars (ajax_url/actions/nonces).
 * `statusEl` необязателен — если передан, показывает статус; без него сохраняет молча.
 *
 * Возвращает признак «сервер подтвердил запись»: вызывающий по нему решает,
 * можно ли считать ответ сохранённым (отметить задание в навигаторе, обновить
 * счётчик). Без этого «✓» и подсветка появлялись даже на упавшем запросе.
 *
 * @returns {Promise<boolean>} true — ответ записан в БД
 */
export async function saveAnswer( vars, attemptId, taskId, answerText, statusEl ) {
	if ( ! vars ) { return false; }
	if ( statusEl ) { statusEl.textContent = 'Сохраняется…'; }
	try {
		const fd = new FormData();
		fd.append( 'action', vars.actions.saveAttemptAnswer );
		fd.append( 'security', vars.nonces.startAttempt );
		fd.append( 'attempt_id', String( attemptId ) );
		fd.append( 'task_id', String( taskId ) );
		fd.append( 'answer_text', answerText );
		const res = await fetch( vars.ajax_url, { method: 'POST', body: fd } );
		const json = await res.json();
		if ( statusEl ) { statusEl.textContent = json.success ? '✓' : ( json.data || 'Ошибка' ); }
		return true === json.success;
	} catch ( e ) {
		if ( statusEl ) { statusEl.textContent = 'Сетевая ошибка'; }
		return false;
	}
}

/**
 * Значение ответа блока:
 *  - «Развёрнутый ответ» (Эпик 13, D16) — JSON {"text","files":[attachment_ids]};
 *  - составное задание (Triple, задача 3) — JSON {"19":..,"20":..,"21":..} со всех
 *    под-полей, отправляется одним вызовом на родительский task_id;
 *  - остальные — просто текст.
 */
function answerValue( block ) {
	if ( 'triple' === block.dataset.template ) {
		const out = {};
		block.querySelectorAll( '.fs-attempt-answer[data-sub]' ).forEach( ( t ) => {
			out[ t.dataset.sub ] = t.value.trim();
		} );
		return JSON.stringify( out );
	}

	const textarea = block.querySelector( '.fs-attempt-answer' );
	if ( 'file_answer' !== block.dataset.template ) {
		return textarea ? textarea.value : '';
	}
	const files = Array.from( block.querySelectorAll( '.fs-attempt-files__chip' ) )
		.map( ( chip ) => parseInt( chip.dataset.id, 10 ) )
		.filter( ( id ) => id > 0 );
	return JSON.stringify( { text: textarea.value.trim(), files } );
}

/**
 * Значение блока, подтверждённое сервером. По нему решается, есть ли
 * несохранённый ввод: без этого маячок на уходе со страницы слал бы все ответы
 * подряд, в том числе нетронутые.
 */
const savedValues = new WeakMap();

/** Запись блока с отметкой подтверждённого значения. */
async function saveBlock( block, attemptId, statusEl ) {
	const value = answerValue( block );
	const ok    = await saveAnswer( vars, attemptId, block.dataset.taskId, value, statusEl );
	if ( ok ) { savedValues.set( block, value ); }
	return ok;
}

/** Bind autosave handlers to all answer textareas (кнопки «Сохранить» нет — сохраняется само). */
function bindAutosave( form, attemptId ) {
	form.querySelectorAll( '.fs-attempt-question' ).forEach( ( block ) => {
		// Составное задание (Triple) содержит несколько под-полей — вешаем на все;
		// сохраняем весь блок одним вызовом (answerValue собирает JSON).
		const textareas = block.querySelectorAll( '.fs-attempt-answer' );
		const statusEl  = block.querySelector( '.fs-save-status' ); // необязателен (индикатор убран)
		if ( ! textareas.length ) { return; }

		// Отправная точка — то, что отрисовал сервер: ответ из прошлой сессии
		// иначе считался бы несохранённым и уходил маячком при каждом уходе.
		savedValues.set( block, answerValue( block ) );

		const save = () => saveBlock( block, attemptId, statusEl );

		textareas.forEach( ( textarea ) => {
			// Дебаунс при вводе + немедленное сохранение при уходе из поля (blur).
			textarea.addEventListener( 'input', debounce( save, 1200 ) );
			textarea.addEventListener( 'blur', save );
		} );
	} );
}

/**
 * Уход со страницы (закрытие вкладки, обновление, переход по ссылке): всё, что
 * не успело уехать дебаунсом, дописываем маячком — обычный fetch браузер вправе
 * оборвать. Тот же экшен и нонс, что у автосохранения (как на станции КЕГЭ).
 */
function bindUnloadBeacon( form, attemptId ) {
	window.addEventListener( 'pagehide', () => {
		if ( ! vars || ! navigator.sendBeacon ) { return; }

		form.querySelectorAll( '.fs-attempt-question' ).forEach( ( block ) => {
			if ( ! block.querySelector( '.fs-attempt-answer' ) ) { return; }

			const value = answerValue( block );
			if ( value === savedValues.get( block ) ) { return; }

			const fd = new FormData();
			fd.append( 'action', vars.actions.saveAttemptAnswer );
			fd.append( 'security', vars.nonces.startAttempt );
			fd.append( 'attempt_id', String( attemptId ) );
			fd.append( 'task_id', String( block.dataset.taskId ) );
			fd.append( 'answer_text', value );
			navigator.sendBeacon( vars.ajax_url, fd );
		} );
	} );
}

/**
 * Сохраняет ВСЕ ответы (await) — вызывается перед сдачей, чтобы не потерять
 * несохранённое: кнопки «Сохранить» нет, а последний ввод мог не успеть
 * автосохраниться (дебаунс) до нажатия «Сдать».
 */
async function saveAll( form, attemptId ) {
	const jobs = [];
	form.querySelectorAll( '.fs-attempt-question' ).forEach( ( block ) => {
		const statusEl = block.querySelector( '.fs-save-status' ); // необязателен (индикатор убран)
		if ( ! block.querySelector( '.fs-attempt-answer' ) ) { return; }
		jobs.push( saveBlock( block, attemptId, statusEl ) );
	} );
	await Promise.all( jobs );
}

/**
 * Загрузка файлов ответа для задач «Развёрнутый ответ» (Эпик 13, D16):
 * двухшаговая — файл уходит на upload_answer_file (доступ по СВОЕЙ попытке),
 * attachment_id ложится чипом, ответ сохраняется как JSON через save_attempt_answer.
 */
function bindFileAnswers( form, attemptId ) {
	form.querySelectorAll( '.fs-attempt-question[data-template="file_answer"]' ).forEach( ( block ) => {
		const textarea = block.querySelector( '.fs-attempt-answer' );
		const saveEl   = block.querySelector( '.fs-save-status' );
		const chips    = block.querySelector( '.fs-attempt-files__chips' );
		const input    = block.querySelector( '.fs-attempt-files__input' );
		const addBtn   = block.querySelector( '.fs-attempt-files__add' );
		const statusEl = block.querySelector( '.fs-attempt-files__status' );
		if ( ! chips || ! input || ! addBtn || ! textarea ) { return; }

		const persist = () => saveBlock( block, attemptId, saveEl );

		const addChip = ( id, name ) => {
			const chip      = document.createElement( 'span' );
			chip.className  = 'fs-attempt-files__chip';
			chip.dataset.id = String( id );

			const nameEl       = document.createElement( 'span' );
			nameEl.textContent = name;

			const rm       = document.createElement( 'button' );
			rm.type        = 'button';
			rm.className   = 'fs-attempt-files__chip-remove';
			rm.textContent = '✕';
			rm.setAttribute( 'aria-label', 'Убрать файл' );
			rm.addEventListener( 'click', () => { chip.remove(); persist(); } );

			chip.append( nameEl, rm );
			chips.appendChild( chip );
		};

		const uploadOne = async ( file ) => {
			const fd = new FormData();
			fd.append( 'action',      vars.actions.uploadAnswerFile );
			fd.append( 'security',    vars.nonces.uploadAnswerFile );
			fd.append( 'attempt_id',  String( attemptId ) );
			fd.append( 'answer_file', file );
			const res  = await fetch( vars.ajax_url, { method: 'POST', body: fd } );
			const json = await res.json();
			if ( ! json?.success ) {
				throw new Error( json?.data?.message || json?.data || 'Не удалось загрузить файл' );
			}
			return json.data; // { attachment_id, name, … }
		};

		addBtn.addEventListener( 'click', () => input.click() );
		input.addEventListener( 'change', async () => {
			if ( ! vars?.actions?.uploadAnswerFile ) {
				statusEl.textContent = 'Загрузка файлов недоступна.';
				return;
			}
			addBtn.disabled = true;
			for ( const file of Array.from( input.files || [] ) ) {
				statusEl.textContent = `Загрузка: ${ file.name }…`;
				try {
					const up = await uploadOne( file );
					addChip( up.attachment_id, up.name || file.name );
					statusEl.textContent = '';
				} catch ( e ) {
					statusEl.textContent = `${ file.name }: ${ e.message }`;
				}
			}
			input.value     = '';
			addBtn.disabled = false;
			persist();
		} );
	} );
}

/**
 * Рендер одной строки per-task результата (T13.7): критерии + файлы.
 * @param {Object} task
 * @returns {HTMLElement}
 */
function renderResultTask( task ) {
	const div  = document.createElement( 'div' );
	div.className = 'fs-result-task';

	const nEl = document.createElement( 'div' );
	nEl.className   = 'fs-result-task__n';
	nEl.textContent = `${ task.n }.`;
	div.appendChild( nEl );

	const body = document.createElement( 'div' );
	body.className = 'fs-result-task__body';

	if ( task.criteria && task.criteria.length ) {
		const ul = document.createElement( 'ul' );
		ul.className = 'fs-result-criteria';
		for ( const c of task.criteria ) {
			const li   = document.createElement( 'li' );
			const val  = null !== c.awarded && undefined !== c.awarded ? c.awarded : '—';
			li.textContent = `${ c.label }: ${ val } / ${ c.max_points }`;
			ul.appendChild( li );
		}
		body.appendChild( ul );
	} else if ( null !== task.score && undefined !== task.score ) {
		const span = document.createElement( 'span' );
		span.className   = 'fs-result-task__score';
		span.textContent = `Баллов: ${ task.score } / ${ task.max_score ?? '?' }`;
		body.appendChild( span );
	}

	if ( task.files && task.files.length ) {
		const filesDiv = document.createElement( 'div' );
		filesDiv.className = 'fs-result-files';
		const title = document.createElement( 'div' );
		title.className   = 'fs-result-files__title';
		title.textContent = 'Ваши файлы:';
		filesDiv.appendChild( title );
		for ( const f of task.files ) {
			if ( f.mime && f.mime.startsWith( 'image/' ) ) {
				const a   = document.createElement( 'a' );
				a.href    = f.url;
				a.target  = '_blank';
				a.rel     = 'noopener noreferrer';
				const img = document.createElement( 'img' );
				img.className = 'fs-result-files__preview';
				img.src       = f.url;
				img.alt       = f.name;
				a.appendChild( img );
				filesDiv.appendChild( a );
			} else {
				const a         = document.createElement( 'a' );
				a.className     = 'fs-result-files__link';
				a.href          = f.url;
				a.target        = '_blank';
				a.rel           = 'noopener noreferrer';
				a.textContent   = f.name;
				filesDiv.appendChild( a );
			}
		}
		body.appendChild( filesDiv );
	}

	div.appendChild( body );
	return div;
}

/** Submit the whole attempt. */
async function submitAttempt( attemptId, form, resultEl, timerInterval ) {
	if ( ! vars ) { return; }
	try {
		const fd = new FormData();
		fd.append( 'action', vars.actions.submitAttempt );
		fd.append( 'security', vars.nonces.submitAttempt );
		fd.append( 'attempt_id', String( attemptId ) );
		const res = await fetch( vars.ajax_url, { method: 'POST', body: fd } );
		const json = await res.json();

		if ( timerInterval ) { clearInterval( timerInterval ); }

		if ( json.success ) {
			// Попытка завершена → контент разблокирован. Перезагружаем страницу,
			// чтобы сервер отрисовал экран результата (T13.7) уже с кнопкой
			// «Вернуться» (во время попытки её нет — выход только через сдачу).
			window.location.reload();
			return;
		}

		form.hidden = true;
		resultEl.removeAttribute( 'hidden' );
		resultEl.querySelector( '.fs-result-score' ).textContent = json.data || 'Ошибка при сдаче.';
	} catch ( e ) {
		resultEl.removeAttribute( 'hidden' );
		resultEl.querySelector( '.fs-result-score' ).textContent = 'Сетевая ошибка при отправке.';
	}
}

/** Initialize the running attempt form (timer + autosave + submit). */
function initRunningAttempt() {
	const wrapper = document.getElementById( 'fs-assessment-form' );
	if ( ! wrapper || wrapper.dataset.preview ) { return; } // предпросмотр — initPreviewAttempt()

	const attemptId    = wrapper.dataset.attemptId;
	const deadlineAt   = wrapper.dataset.deadline;
	const form         = wrapper.querySelector( '.fs-attempt-form' );
	const resultEl     = document.getElementById( 'fs-assessment-result' );
	if ( ! form || ! resultEl ) { return; }

	const timerInterval = initTimer( form, deadlineAt, wrapper.dataset.now );

	bindAutosave( form, attemptId );
	bindFileAnswers( form, attemptId );
	bindUnloadBeacon( form, attemptId );

	let submitting = false;
	form.addEventListener( 'submit', async ( e ) => {
		e.preventDefault();
		if ( submitting ) { return; }
		submitting = true;
		// Досохраняем всё перед сдачей (кнопки «Сохранить» нет).
		try { await saveAll( form, attemptId ); } catch ( err ) { /* сохранение не критично для сдачи */ }
		submitAttempt( attemptId, form, resultEl, timerInterval );
	} );
}

/**
 * Предпросмотр générique-контрольной (T-preview-4): попытки в БД нет и не
 * будет (AttemptPageService::buildPreview()), поэтому ни autosave, ни маячок
 * ухода, ни загрузка файлов ответа не работают — их не к чему привязать на
 * сервере (SubmissionCallbacks::ajaxUploadAnswerFile() требует свою попытку).
 * «Сдать» считает результат по накопленному в форме — тем же алгоритмом, что
 * и настоящая сдача (AutoGradeService::evaluate(), см. previewResult).
 */
function initPreviewAttempt() {
	const wrapper = document.getElementById( 'fs-assessment-form' );
	if ( ! wrapper || ! wrapper.dataset.preview ) { return; }

	const assessmentId = wrapper.dataset.assessmentId;
	const form         = wrapper.querySelector( '.fs-attempt-form' );
	const resultEl     = document.getElementById( 'fs-assessment-result' );
	if ( ! form || ! resultEl || ! vars ) { return; }

	// Файл ответа некуда сохранить без реальной попытки — прячем контрол и
	// объясняем, а не даём кликнуть в пустоту.
	form.querySelectorAll( '.fs-attempt-files' ).forEach( ( block ) => {
		block.querySelector( '.fs-attempt-files__controls' )?.setAttribute( 'hidden', '' );
		const hint = block.querySelector( '.fs-attempt-files__hint' );
		if ( hint ) { hint.textContent = 'Загрузка файлов недоступна в предпросмотре.'; }
	} );

	let submitting = false;
	form.addEventListener( 'submit', async ( e ) => {
		e.preventDefault();
		if ( submitting ) { return; }
		submitting = true;

		try {
			const fd = new FormData();
			fd.append( 'action', vars.actions.previewResult );
			fd.append( 'security', vars.nonces.startAttempt );
			fd.append( 'assessment_id', String( assessmentId ) );
			form.querySelectorAll( '.fs-attempt-question' ).forEach( ( block ) => {
				if ( ! block.querySelector( '.fs-attempt-answer' ) ) { return; }
				fd.append( `answers[${ block.dataset.taskId }]`, answerValue( block ) );
			} );

			const res  = await fetch( vars.ajax_url, { method: 'POST', body: fd } );
			const json = await res.json();

			form.hidden = true;
			resultEl.removeAttribute( 'hidden' );

			if ( ! json.success ) {
				resultEl.querySelector( '.fs-result-score' ).textContent = json.data || 'Ошибка при сдаче.';
				return;
			}

			resultEl.querySelector( '.fs-result-score' ).textContent =
				`Баллов: ${ json.data.total_score } / ${ json.data.max_score }`;
			const tasksEl = document.createElement( 'div' );
			tasksEl.className = 'fs-result-tasks';
			( json.data.per_task || [] ).forEach( ( task ) => tasksEl.appendChild( renderResultTask( task ) ) );
			resultEl.appendChild( tasksEl );
		} catch ( err ) {
			form.hidden = true;
			resultEl.removeAttribute( 'hidden' );
			resultEl.querySelector( '.fs-result-score' ).textContent = 'Сетевая ошибка при отправке.';
		} finally {
			submitting = false;
		}
	} );
}

/** Initialize the start-attempt button (pre-attempt state). */
function initStartButton() {
	const btn = document.getElementById( 'fs-start-attempt-btn' );
	if ( ! btn || ! vars ) { return; }

	const noticeEl = document.getElementById( 'fs-start-notice' );

	btn.addEventListener( 'click', async () => {
		btn.disabled = true;
		if ( noticeEl ) { noticeEl.textContent = 'Запуск…'; }
		try {
			const fd = new FormData();
			fd.append( 'action', vars.actions.startAttempt );
			fd.append( 'security', vars.nonces.startAttempt );
			fd.append( 'assessment_id', String( btn.dataset.assessmentId || '' ) );
			// Задача 5: контекст группы/занятия из URL (from_gid/from_gl, проставлены
			// плеером) — чтобы попытка привязалась к занятию и попала в сводку ученика.
			const qs = new URLSearchParams( window.location.search );
			const fromGid = qs.get( 'from_gid' );
			const fromGl  = qs.get( 'from_gl' );
			if ( fromGid ) { fd.append( 'group_id', fromGid ); }
			if ( fromGl ) { fd.append( 'group_lesson_id', fromGl ); }
			const res  = await fetch( vars.ajax_url, { method: 'POST', body: fd } );
			const json = await res.json();
			if ( json.success ) {
				window.location.reload();
			} else {
				if ( noticeEl ) { noticeEl.textContent = json.data || 'Ошибка запуска.'; }
				btn.disabled = false;
			}
		} catch ( e ) {
			if ( noticeEl ) { noticeEl.textContent = 'Сетевая ошибка.'; }
			btn.disabled = false;
		}
	} );
}

export function initAssessment() {
	if ( ! document.querySelector( '.fs-assessment-page' ) ) { return; }
	initRunningAttempt();
	initPreviewAttempt();
	initStartButton();
}
