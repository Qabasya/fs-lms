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

/** Bind autosave handlers to all answer textareas. */
function bindAutosave( form, attemptId ) {
	form.querySelectorAll( '.fs-attempt-question' ).forEach( ( block ) => {
		const taskId   = block.dataset.taskId;
		const textarea = block.querySelector( '.fs-attempt-answer' );
		const statusEl = block.querySelector( '.fs-save-status' );
		const btn      = block.querySelector( '.fs-autosave-btn' );
		if ( ! textarea || ! statusEl ) { return; }

		const debouncedSave = debounce(
			() => saveAnswer( attemptId, taskId, textarea.value, statusEl ),
			3000
		);

		textarea.addEventListener( 'input', debouncedSave );
		if ( btn ) {
			btn.addEventListener( 'click', () => saveAnswer( attemptId, taskId, textarea.value, statusEl ) );
		}
	} );
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
