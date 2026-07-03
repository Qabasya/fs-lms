/**
 * Задача-шаг (T14.7): виджет ответа (task-widget.js), «Ответить» disabled без
 * выбора, мгновенная проверка через SubmitTaskAnswer, вердикт-блоки vd-ok/vd-no,
 * счётчик попыток, «Попробовать ещё раз», показ эталона после исчерпания (D20).
 */
import { initTaskWidget } from '../frontend/components/task-widget.js';
import { getCore, onPanelShow, isPreview } from './core.js';
import { esc, ICO } from './icons.js';

const vars = window.fs_lms_player_vars;

const widgets = new Map(); // panel → widget API | null

export function initStepTask() {
	if ( ! vars ) { return; }
	onPanelShow( setup );
	const core = getCore();
	if ( core ) { setup( core.panels[ core.activeIndex() ] ); }
}

function setup( panel ) {
	if ( 'task' !== panel.dataset.stepType || widgets.has( panel ) ) { return; }

	const container = panel.querySelector( '.fs-task-widget' );
	if ( ! container ) {
		widgets.set( panel, null ); // ручное задание — проходится кнопкой «Далее»
		return;
	}

	const widget = initTaskWidget( panel );
	widgets.set( panel, widget );
	if ( ! widget ) { return; }

	wire( panel, widget );
}

function wire( panel, widget ) {
	const submitBtn = panel.querySelector( '.fs-task-submit' );
	const resultEl  = panel.querySelector( '.fs-task-result' );
	if ( ! submitBtn || ! resultEl ) { return; }

	// «Попробовать ещё раз» — создаётся рядом с «Ответить», показывается после ошибки.
	const retryBtn       = document.createElement( 'button' );
	retryBtn.type        = 'button';
	retryBtn.className   = 'b fs-task-retry';
	retryBtn.textContent = 'Попробовать ещё раз';
	retryBtn.hidden      = true;
	submitBtn.after( retryBtn );

	const syncSubmit = () => {
		// Preview (Фаза 5): кнопка «Ответить» отрисована сервером disabled — виджет
		// остаётся активным, но syncSubmit не должен её снова включать при вводе.
		if ( isPreview() ) { return; }
		submitBtn.disabled = ! widget.hasAnswer();
		submitBtn.classList.toggle( 'b-dis', submitBtn.disabled );
	};

	// Шаг уже закрыт (пройден/провален) — статичный вердикт, ввод заблокирован.
	const status = panel.dataset.status;
	if ( 'completed' === status || 'failed' === status ) {
		widget.lock();
		submitBtn.hidden = true;
		if ( 'completed' === status ) {
			resultEl.innerHTML = vd( 'ok', 'Задание решено', 'Этот шаг уже пройден.' );
		} else {
			const reveal = revealData( panel ); // D20: эталон после исчерпания
			resultEl.innerHTML = vd( 'no', 'Попытки закончились', reveal.text ? `Правильный ответ: ${ reveal.text }` : 'Ответ больше изменить нельзя.' );
			if ( reveal.ids ) { widget.reveal( reveal.ids ); }
		}
		return;
	}

	syncSubmit();
	widget.onChange( syncSubmit );

	submitBtn.addEventListener( 'click', () => submit( panel, widget, submitBtn, retryBtn, resultEl ) );

	retryBtn.addEventListener( 'click', () => {
		widget.resetAttempt();
		resultEl.innerHTML = '';
		retryBtn.hidden  = true;
		submitBtn.hidden = false;
		syncSubmit();
	} );
}

async function submit( panel, widget, submitBtn, retryBtn, resultEl ) {
	if ( isPreview() || submitBtn.disabled ) { return; }
	submitBtn.disabled = true;

	const core = getCore();
	const fd   = new FormData();
	fd.append( 'action', vars.actions.submitTask );
	fd.append( 'security', vars.nonces.submitTask );
	fd.append( 'group_lesson_id', core.groupLessonId );
	fd.append( 'step_key', panel.dataset.step );
	fd.append( 'answer', widget.collectAnswer() );

	let res;
	try {
		const r = await fetch( vars.ajax_url, { method: 'POST', body: fd } );
		res     = await r.json();
	} catch {
		submitBtn.disabled = false;
		return;
	}

	if ( ! res?.success ) {
		resultEl.innerHTML = vd( 'no', 'Ошибка', res?.data?.message || 'Не удалось отправить ответ. Попробуйте ещё раз.' );
		submitBtn.disabled = false;
		return;
	}

	const d   = res.data;
	const idx = core.panels.indexOf( panel );

	updateAttemptsIndicator( panel, d );

	if ( d.reveal_hint ) {
		const hint = panel.querySelector( '.fs-hint' );
		if ( hint ) { hint.open = true; }
	}

	widget.applyVerdict( !! d.is_correct );

	if ( d.is_correct ) {
		resultEl.innerHTML = vd( 'ok', 'Верно!', '', [
			`Балл: ${ d.score } из ${ d.max_score }`,
			attemptMeta( d ),
			'Проверено мгновенно',
		] );
		widget.lock();
		submitBtn.hidden = true;
		core.setStatus( idx, 'completed' );
		core.unlockNext();
		return;
	}

	if ( 'failed' === d.step_status ) {
		// D20: сервер отдал эталон — исчерпание попыток, пересдач нет.
		resultEl.innerHTML = vd(
			'no',
			'Попытки закончились',
			d.correct_answer ? `Правильный ответ: ${ d.correct_answer }` : '',
			[ `Балл: ${ d.score } из ${ d.max_score }` ]
		);
		if ( Array.isArray( d.correct_answer_ids ) ) {
			widget.reveal( d.correct_answer_ids );
		}
		widget.lock();
		submitBtn.hidden = true;
		core.setStatus( idx, 'failed' );
		return;
	}

	// Неверно, попытки остались.
	resultEl.innerHTML = vd( 'no', 'Неверно', 'Проверьте решение и попробуйте ещё раз.', [ remainingMeta( d ) ] );
	submitBtn.hidden = true;
	retryBtn.hidden  = false;
}

/* ── Вспомогательные ──────────────────────────────────────────────────── */

function vd( kind, title, body = '', meta = [] ) {
	const icon  = 'ok' === kind ? ICO.check( 13 ) : ICO.cross( 11 );
	const metas = meta.filter( Boolean );
	return `<div class="vd vd-${ kind }"><span class="vi">${ icon }</span><div>` +
		`<b>${ esc( title ) }</b>` +
		( body ? `<span>${ esc( body ) }</span>` : '' ) +
		( metas.length ? `<div class="vmeta">${ metas.map( ( m ) => `<span>${ esc( m ) }</span>` ).join( '' ) }</div>` : '' ) +
		'</div></div>';
}

function attemptMeta( d ) {
	return d.max_attempts > 0 ? `Попытка ${ d.attempts_used } из ${ d.max_attempts }` : '';
}

function remainingMeta( d ) {
	return d.max_attempts > 0 ? `Осталось попыток: ${ Math.max( 0, d.max_attempts - d.attempts_used ) }` : '';
}

function updateAttemptsIndicator( panel, d ) {
	const indicator = panel.querySelector( '.fs-attempt-indicator' );
	if ( ! indicator || ! ( d.max_attempts > 0 ) ) { return; }
	indicator.dataset.used = String( d.attempts_used );
	indicator.textContent  = `Попыток использовано: ${ d.attempts_used } из ${ d.max_attempts }`;
}

/** Эталон, отданный сервером в разметке шага (перезагрузка после исчерпания, D20). */
function revealData( panel ) {
	const container = panel.querySelector( '.fs-task-widget' );
	const text      = container?.dataset.correctText || '';
	let ids         = null;
	try {
		const raw = container?.dataset.correctIds;
		if ( raw ) { ids = JSON.parse( raw ); }
	} catch { ids = null; }
	return { text, ids: Array.isArray( ids ) ? ids : null };
}
