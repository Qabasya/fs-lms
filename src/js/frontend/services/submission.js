/**
 * Submission service — форма сдачи работ (ученик) + форма оценки/возврата (преподаватель).
 * Pure-JS function pattern; guard on element presence at the top.
 */

import { showToast } from '../../common/components/toast.js';

const vars = window.fs_lms_submission_vars;

// ── Helpers ──────────────────────────────────────────────────────────────────

function post( action, nonce, body ) {
	const fd = new FormData();
	fd.append( 'action',   action );
	fd.append( 'security', nonce );
	for ( const [ k, v ] of Object.entries( body ) ) {
		if ( v !== null && v !== undefined ) {
			fd.append( k, v );
		}
	}
	return fetch( vars.ajax_url, { method: 'POST', body: fd } )
		.then( r => r.json() );
}

function formatScore( score, maxScore ) {
	if ( score === null || score === undefined ) { return '—'; }
	return maxScore ? `${ score } / ${ maxScore }` : String( score );
}

// ── Student: submission form ──────────────────────────────────────────────────

function initSubmissionForms() {
	const forms = document.querySelectorAll( '.fs-submission-form' );
	if ( ! forms.length ) { return; }

	forms.forEach( form => {
		form.addEventListener( 'submit', async e => {
			e.preventDefault();

			const btn = form.querySelector( '.fs-submission-form__submit' );
			const status = form.querySelector( '.fs-submission-status' );

			btn.disabled  = true;
			status.textContent = 'Отправка…';

			const fileInput = form.querySelector( 'input[type="file"]' );
			const fd = new FormData();
			fd.append( 'action',         vars.actions.submitWork );
			fd.append( 'security',       vars.nonces.submitWork );
			fd.append( 'group_lesson_id', form.dataset.groupLessonId );
			fd.append( 'work_id',         form.dataset.workId );
			fd.append( 'answer_text',     form.querySelector( 'textarea' )?.value ?? '' );
			if ( form.dataset.taskId ) {
				fd.append( 'task_id', form.dataset.taskId );
			}
			if ( fileInput && fileInput.files[ 0 ] ) {
				fd.append( 'submission_file', fileInput.files[ 0 ] );
			}

			try {
				const res = await fetch( vars.ajax_url, { method: 'POST', body: fd } ).then( r => r.json() );
				if ( res.success ) {
					status.textContent = 'Работа сдана.';
					form.classList.add( 'fs-submission-form--sent' );
					btn.disabled = true;
					renderSubmissionResult( form, res.data );
				} else {
					status.textContent = res.data || 'Ошибка при сдаче.';
					btn.disabled = false;
				}
			} catch {
				status.textContent = 'Ошибка сети.';
				btn.disabled = false;
			}
		} );
	} );
}

function renderSubmissionResult( form, data ) {
	let el = form.parentElement.querySelector( '.fs-submission-result' );
	if ( ! el ) {
		el = document.createElement( 'div' );
		form.after( el );
	}
	el.className = `fs-submission-result fs-submission-result--${ data.status ?? 'submitted' }`;
	el.innerHTML = '';

	const score = document.createElement( 'div' );
	score.className = 'fs-submission-result__score';
	score.textContent = `Оценка: ${ formatScore( data.score, data.max_score ) }`;
	el.appendChild( score );

	if ( data.feedback ) {
		const fb = document.createElement( 'div' );
		fb.className = 'fs-submission-result__feedback';
		fb.textContent = data.feedback;
		el.appendChild( fb );
	}
}

// ── Teacher: grading queue ────────────────────────────────────────────────────

function initGradingQueue() {
	const queue = document.getElementById( 'fs-grading-queue' );
	if ( ! queue ) { return; }

	const groupId = queue.dataset.groupId;
	if ( ! groupId ) { return; }

	loadQueue( queue, groupId );
}

async function loadQueue( container, groupId ) {
	try {
		const res = await post(
			vars.actions.getGroupSubmissions,
			vars.nonces.gradeWork,
			{ group_id: groupId }
		);
		if ( ! res.success ) { return; }
		renderQueue( container, res.data, groupId );
	} catch { /* silent */ }
}

function renderQueue( container, items, groupId ) {
	container.innerHTML = '';
	if ( ! items.length ) {
		container.textContent = 'Очередь проверки пуста.';
		return;
	}

	items.forEach( item => {
		const card = document.createElement( 'div' );
		card.className = 'fs-grading-queue__item';
		card.dataset.id = item.id;

		const late = item.is_late ? ' <span class="fs-submission-late">(просрочено)</span>' : '';
		card.innerHTML = `
			<div class="fs-grading-queue__meta">
				Работа #${ item.work_id } · ${ item.work_type }${ late }
				· Сдано: ${ item.submitted_at ?? '—' }
			</div>
			<div class="fs-grading-answer">${ escHtml( item.answer_text ?? '' ) }</div>
			<form class="fs-grading-form" data-submission-id="${ item.id }">
				<input type="number" name="score" placeholder="Балл" min="0" step="0.5" required />
				<input type="number" name="max_score" placeholder="Макс." min="0" step="0.5" value="100" />
				<textarea name="feedback" placeholder="Комментарий"></textarea>
				<button type="submit">Оценить</button>
				<button type="button" class="fs-btn-return">Вернуть</button>
			</form>
		`;

		card.querySelector( '.fs-grading-form' ).addEventListener( 'submit', e => gradeSubmit( e, card ) );
		card.querySelector( '.fs-btn-return' ).addEventListener( 'click', () => returnSubmit( card ) );

		container.appendChild( card );
	} );
}

async function gradeSubmit( e, card ) {
	e.preventDefault();
	const form = e.currentTarget;
	const id   = form.dataset.submissionId;

	const res = await post( vars.actions.saveGrade, vars.nonces.gradeWork, {
		submission_id: id,
		score:         form.querySelector( '[name="score"]' ).value,
		max_score:     form.querySelector( '[name="max_score"]' ).value,
		feedback:      form.querySelector( '[name="feedback"]' ).value,
	} );

	if ( res.success ) {
		card.remove();
	} else {
		showToast( res.data || 'Ошибка оценки.', 'error' );
	}
}

async function returnSubmit( card ) {
	const form     = card.querySelector( '.fs-grading-form' );
	const id       = form.dataset.submissionId;
	const feedback = form.querySelector( '[name="feedback"]' ).value;

	if ( ! feedback.trim() ) {
		showToast( 'Укажите комментарий для возврата.', 'warning' );
		return;
	}

	const res = await post( vars.actions.returnSubmission, vars.nonces.gradeWork, {
		submission_id: id,
		feedback,
	} );

	if ( res.success ) {
		card.remove();
	} else {
		showToast( res.data || 'Ошибка возврата.', 'error' );
	}
}

// ── Teacher: gradebook ────────────────────────────────────────────────────────

function initGradebook() {
	const table = document.getElementById( 'fs-gradebook-container' );
	if ( ! table ) { return; }

	const groupId = table.dataset.groupId;
	if ( ! groupId ) { return; }

	loadGradebook( table, groupId );
}

async function loadGradebook( container, groupId ) {
	try {
		const res = await post(
			vars.actions.getGradebook,
			vars.nonces.gradeWork,
			{ group_id: groupId }
		);
		if ( ! res.success ) { return; }
		renderGradebook( container, res.data );
	} catch { /* silent */ }
}

function renderGradebook( container, entries ) {
	if ( ! entries.length ) {
		container.textContent = 'Оценок пока нет.';
		return;
	}

	const table = document.createElement( 'table' );
	table.className = 'fs-gradebook-table';
	table.innerHTML = `
		<thead><tr>
			<th>Ученик (ID)</th><th>Работа</th><th>Оценка</th><th>Дата</th>
		</tr></thead>
	`;

	const tbody = document.createElement( 'tbody' );
	entries.forEach( e => {
		const tr = document.createElement( 'tr' );
		tr.innerHTML = `
			<td>${ e.student_person_id }</td>
			<td>${ escHtml( e.title ) }</td>
			<td class="fs-gradebook-score">${ formatScore( e.score, e.max_score ) }</td>
			<td>${ e.graded_at ?? '—' }</td>
		`;
		tbody.appendChild( tr );
	} );
	table.appendChild( tbody );
	container.innerHTML = '';
	container.appendChild( table );
}

// ── Utils ─────────────────────────────────────────────────────────────────────

function escHtml( str ) {
	return str
		.replace( /&/g, '&amp;' )
		.replace( /</g, '&lt;' )
		.replace( />/g, '&gt;' )
		.replace( /"/g, '&quot;' );
}

// ── Entry point ───────────────────────────────────────────────────────────────

export function initSubmissions() {
	if ( ! vars ) { return; }
	initSubmissionForms();
	initGradingQueue();
	initGradebook();
}
