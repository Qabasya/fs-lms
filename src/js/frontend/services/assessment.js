/**
 * Assessment (attempt) service — timer, autosave, submit.
 * Pure-JS function pattern (no jQuery).
 */

const vars = window.fs_lms_assessment_vars;

function escHtml( str ) {
	const d = document.createElement( 'div' );
	d.textContent = str;
	return d.innerHTML;
}

/** Countdown timer that auto-submits when deadline is reached. */
function initTimer( form, deadlineAt ) {
	const display = document.getElementById( 'fs-timer-display' );
	if ( ! display || ! deadlineAt ) { return null; }

	const deadline = new Date( deadlineAt.replace( ' ', 'T' ) ).getTime();

	const tick = () => {
		const remaining = Math.floor( ( deadline - Date.now() ) / 1000 );
		if ( remaining <= 0 ) {
			display.textContent = '00:00';
			display.classList.add( 'fs-timer--expired' );
			form.dispatchEvent( new Event( 'submit' ) );
			return;
		}
		const m = String( Math.floor( remaining / 60 ) ).padStart( 2, '0' );
		const s = String( remaining % 60 ).padStart( 2, '0' );
		display.textContent = `${ m }:${ s }`;
		if ( remaining < 60 ) {
			display.classList.add( 'fs-timer--warning' );
		}
	};

	tick();
	return setInterval( tick, 1000 );
}

/** Debounce helper. */
function debounce( fn, ms ) {
	let t;
	return ( ...args ) => { clearTimeout( t ); t = setTimeout( () => fn( ...args ), ms ); };
}

/** Autosave a single answer via AJAX. */
async function saveAnswer( attemptId, taskId, answerText, statusEl ) {
	if ( ! vars ) { return; }
	statusEl.textContent = 'Сохраняется…';
	try {
		const fd = new FormData();
		fd.append( 'action', vars.actions.saveAttemptAnswer );
		fd.append( 'security', vars.nonces.startAttempt );
		fd.append( 'attempt_id', String( attemptId ) );
		fd.append( 'task_id', String( taskId ) );
		fd.append( 'answer_text', answerText );
		const res = await fetch( vars.ajax_url, { method: 'POST', body: fd } );
		const json = await res.json();
		statusEl.textContent = json.success ? '✓' : ( json.data || 'Ошибка' );
	} catch ( e ) {
		statusEl.textContent = 'Сетевая ошибка';
	}
}

/**
 * Значение ответа блока: для «Развёрнутого ответа» (Эпик 13, D16) — JSON
 * {"text","files":[attachment_ids]}, для остальных — как раньше, текст.
 */
function answerValue( block, textarea ) {
	if ( 'file_answer' !== block.dataset.template ) {
		return textarea.value;
	}
	const files = Array.from( block.querySelectorAll( '.fs-attempt-files__chip' ) )
		.map( ( chip ) => parseInt( chip.dataset.id, 10 ) )
		.filter( ( id ) => id > 0 );
	return JSON.stringify( { text: textarea.value.trim(), files } );
}

/** Bind autosave handlers to all answer textareas. */
function bindAutosave( form, attemptId ) {
	form.querySelectorAll( '.fs-attempt-question' ).forEach( ( block ) => {
		const taskId   = block.dataset.taskId;
		const textarea = block.querySelector( '.fs-attempt-answer' );
		const statusEl = block.querySelector( '.fs-save-status' );
		const btn      = block.querySelector( '.fs-autosave-btn' );
		if ( ! textarea || ! statusEl ) { return; }

		const debouncedSave = debounce(
			() => saveAnswer( attemptId, taskId, answerValue( block, textarea ), statusEl ),
			3000
		);

		textarea.addEventListener( 'input', debouncedSave );
		if ( btn ) {
			btn.addEventListener( 'click', () => saveAnswer( attemptId, taskId, answerValue( block, textarea ), statusEl ) );
		}
	} );
}

/**
 * Загрузка файлов ответа для задач «Развёрнутый ответ» (Эпик 13, D16):
 * двухшаговая — файл уходит на upload_answer_file (доступ по СВОЕЙ попытке),
 * attachment_id ложится чипом, ответ сохраняется как JSON через save_attempt_answer.
 */
function bindFileAnswers( form, attemptId ) {
	form.querySelectorAll( '.fs-attempt-question[data-template="file_answer"]' ).forEach( ( block ) => {
		const taskId   = block.dataset.taskId;
		const textarea = block.querySelector( '.fs-attempt-answer' );
		const saveEl   = block.querySelector( '.fs-save-status' );
		const chips    = block.querySelector( '.fs-attempt-files__chips' );
		const input    = block.querySelector( '.fs-attempt-files__input' );
		const addBtn   = block.querySelector( '.fs-attempt-files__add' );
		const statusEl = block.querySelector( '.fs-attempt-files__status' );
		if ( ! chips || ! input || ! addBtn || ! textarea ) { return; }

		const persist = () => saveAnswer( attemptId, taskId, answerValue( block, textarea ), saveEl );

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

		form.hidden = true;
		resultEl.removeAttribute( 'hidden' );

		if ( json.success ) {
			const d = json.data;
			resultEl.querySelector( '.fs-result-score' ).textContent =
				`Баллов: ${ d.total_score ?? '—' } / ${ d.max_score ?? '—' } • Статус: ${ escHtml( d.status ) }`;

			if ( d.per_task && d.per_task.length ) {
				const tasksEl = document.createElement( 'div' );
				tasksEl.className = 'fs-result-tasks';
				for ( const task of d.per_task ) {
					tasksEl.appendChild( renderResultTask( task ) );
				}
				resultEl.appendChild( tasksEl );
			}
		} else {
			resultEl.querySelector( '.fs-result-score' ).textContent = json.data || 'Ошибка при сдаче.';
		}
	} catch ( e ) {
		resultEl.removeAttribute( 'hidden' );
		resultEl.querySelector( '.fs-result-score' ).textContent = 'Сетевая ошибка при отправке.';
	}
}

/** Initialize the running attempt form (timer + autosave + submit). */
function initRunningAttempt() {
	const wrapper = document.getElementById( 'fs-assessment-form' );
	if ( ! wrapper ) { return; }

	const attemptId    = wrapper.dataset.attemptId;
	const deadlineAt   = wrapper.dataset.deadline;
	const form         = wrapper.querySelector( '.fs-attempt-form' );
	const resultEl     = document.getElementById( 'fs-assessment-result' );
	if ( ! form || ! resultEl ) { return; }

	const timerInterval = initTimer( form, deadlineAt );

	bindAutosave( form, attemptId );
	bindFileAnswers( form, attemptId );

	form.addEventListener( 'submit', ( e ) => {
		e.preventDefault();
		submitAttempt( attemptId, form, resultEl, timerInterval );
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
	initStartButton();
}
