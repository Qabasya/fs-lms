/**
 * Задача-шаг (T14.7): виджет ответа (task-widget.js), «Ответить» disabled без
 * выбора, автопроверка через SubmitTaskAnswer, вердикт-блоки vd-ok/vd-no,
 * счётчик попыток, показ эталона после исчерпания (D20).
 *
 * «Попробовать ещё раз» показывается ТОЛЬКО после данного ответа, когда пересдача
 * возможна:
 *   1. верно  + остались попытки → поля и «Ответить» заблокированы, есть пересдача;
 *   2. неверно + остались попытки → то же;
 *   3. не решалось ИЛИ попытки исчерпаны → пересдачи нет.
 * Пересдача снимает блокировку (пересоздаёт виджет заново — надёжнее unlock для
 * всех типов, включая drag-ordering) и прячется сама.
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

	wire( panel );
}

function wire( panel ) {
	const submitBtn = panel.querySelector( '.fs-task-submit' );
	const resultEl  = panel.querySelector( '.fs-task-result' );
	if ( ! submitBtn || ! resultEl ) { return; }

	// «Попробовать ещё раз» — создаётся рядом с «Ответить», показывается по правилам.
	const retryBtn        = document.createElement( 'button' );
	retryBtn.type         = 'button';
	// Пересдача заменяет собой «Ответить» (submitBtn.hidden = true) — визуально
	// это основное действие в этот момент, поэтому b-pri, как у «Ответить».
	retryBtn.className    = 'b b-pri fs-task-retry';
	retryBtn.textContent  = 'Попробовать ещё раз';
	retryBtn.hidden       = true;
	submitBtn.after( retryBtn );

	const ctx = { panel, submitBtn, retryBtn, resultEl };
	ctx.syncSubmit = () => syncSubmit( ctx );

	// Обработчики навешиваются один раз; актуальный виджет всегда берётся из Map
	// (пересдача пересоздаёт его).
	submitBtn.addEventListener( 'click', () => submit( ctx ) );
	retryBtn.addEventListener( 'click', () => retry( ctx ) );

	applyInitialState( ctx );
}

function syncSubmit( ctx ) {
	// #5: в preview кнопка «Ответить» активна (dry-run), как и в реальном плеере —
	// включаем/выключаем по наличию ответа в виджете.
	const w = widgets.get( ctx.panel );
	ctx.submitBtn.disabled = ! w || ! w.hasAnswer();
	ctx.submitBtn.classList.toggle( 'b-dis', ctx.submitBtn.disabled );
}

/**
 * Начальное состояние по данным сервера (перезагрузка / навигация между шагами).
 * Восстанавливает «решено/неверно/исчерпано» и кнопку пересдачи по правилам.
 */
function applyInitialState( ctx ) {
	const { panel, submitBtn, retryBtn, resultEl, syncSubmit } = ctx;
	const widget = widgets.get( panel );
	const status = panel.dataset.status;
	const used   = attemptsUsed( panel );

	// 1. Верно — шаг пройден. Пересдача, если остались попытки.
	if ( 'completed' === status ) {
		widget.lock();
		submitBtn.hidden = true;
		resultEl.innerHTML = vd( 'ok', 'Задание решено', 'Этот шаг уже пройден.' );
		retryBtn.hidden = ! canRetryPanel( panel );
		return;
	}

	// 3. Попытки исчерпаны провалом — терминально, без пересдачи (D20: эталон).
	if ( 'failed' === status ) {
		const reveal = revealData( panel );
		widget.lock();
		submitBtn.hidden = true;
		resultEl.innerHTML = vd( 'no', 'Попытки закончились', reveal.text ? `Правильный ответ: ${ reveal.text }` : 'Ответ больше изменить нельзя.' );
		if ( reveal.ids ) { widget.reveal( reveal.ids ); }
		return;
	}

	// 2. Были попытки, но не пройдено и не провалено ⇒ неверно, попытки остались.
	if ( used > 0 ) {
		widget.lock();
		submitBtn.hidden = true;
		resultEl.innerHTML = vd( 'no', 'Неверно', 'Проверьте решение и попробуйте ещё раз.', [ remainingMetaPanel( panel ) ] );
		retryBtn.hidden = false;
		return;
	}

	// 3. Ещё не решалось — обычный ввод, кнопки пересдачи нет.
	syncSubmit();
	widget.onChange( syncSubmit );
}

/** Пересдача: пересоздать виджет заново (снять блокировку) и вернуть ввод. */
function retry( ctx ) {
	const { panel, submitBtn, retryBtn, resultEl, syncSubmit } = ctx;

	const container = panel.querySelector( '.fs-task-widget' );
	if ( container ) {
		container.removeAttribute( 'data-done' );
		container.innerHTML = '';
	}
	const widget = initTaskWidget( panel );
	widgets.set( panel, widget );

	resultEl.innerHTML = '';
	retryBtn.hidden  = true;
	submitBtn.hidden = false;
	if ( widget ) { widget.onChange( syncSubmit ); }
	syncSubmit();
}

async function submit( ctx ) {
	const { panel, submitBtn, retryBtn, resultEl } = ctx;
	const widget = widgets.get( panel );
	if ( ! widget || submitBtn.disabled ) { return; }
	submitBtn.disabled = true;

	const core    = getCore();
	const preview = isPreview();
	const fd      = new FormData();
	if ( preview ) {
		// #5: dry-run проверка — по ref задания, без занятия и без сохранения.
		fd.append( 'action', vars.actions.previewCheckTask );
		fd.append( 'security', vars.nonces.previewSolve );
		fd.append( 'ref', submitBtn.dataset.previewRef || '' );
		fd.append( 'answer', widget.collectAnswer() );
	} else {
		fd.append( 'action', vars.actions.submitTask );
		fd.append( 'security', vars.nonces.submitTask );
		fd.append( 'group_lesson_id', core.groupLessonId );
		fd.append( 'step_key', panel.dataset.step );
		fd.append( 'answer', widget.collectAnswer() );
	}

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

	// 1. Верно — блокируем ввод; пересдача, если остались попытки.
	if ( d.is_correct ) {
		resultEl.innerHTML = vd( 'ok', 'Верно!', '', [
			`Балл: ${ d.score } из ${ d.max_score }`,
			attemptMeta( d ),
		] );
		widget.lock();
		submitBtn.hidden = true;
		core.setStatus( idx, 'completed' );
		core.unlockNext();
		retryBtn.hidden = ! canRetryData( d );
		return;
	}

	// 3. D20: сервер отдал эталон — исчерпание попыток, пересдач нет.
	if ( 'failed' === d.step_status ) {
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

	// 2. Неверно, попытки остались — блокируем поля, показываем пересдачу.
	resultEl.innerHTML = vd( 'no', 'Неверно', 'Проверьте решение и попробуйте ещё раз.', [ remainingMeta( d ) ] );
	widget.lock();
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

/** Осталось попыток по данным разметки (перезагрузка). */
function remainingMetaPanel( panel ) {
	const max = maxAttempts( panel );
	return max > 0 ? `Осталось попыток: ${ Math.max( 0, max - attemptsUsed( panel ) ) }` : '';
}

/** Возможна ли пересдача по ответу сервера: без лимита ИЛИ остались попытки. */
function canRetryData( d ) {
	const max  = Number( d.max_attempts ) || 0;
	const used = Number( d.attempts_used ) || 0;
	return max <= 0 || used < max;
}

/** Возможна ли пересдача по данным разметки (перезагрузка). */
function canRetryPanel( panel ) {
	const max = maxAttempts( panel );
	return max <= 0 || attemptsUsed( panel ) < max;
}

function footerInt( panel, key ) {
	const footer = panel.querySelector( '.fs-task-footer' );
	return footer ? ( parseInt( footer.dataset[ key ] || '0', 10 ) || 0 ) : 0;
}

function attemptsUsed( panel ) {
	return footerInt( panel, 'attemptsUsed' );
}

function maxAttempts( panel ) {
	return footerInt( panel, 'maxAttempts' );
}

function updateAttemptsIndicator( panel, d ) {
	const indicator = panel.querySelector( '.fs-attempt-indicator' );
	if ( ! indicator || ! ( d.max_attempts > 0 ) ) { return; }
	indicator.dataset.used = String( d.attempts_used );
	indicator.textContent  = `Попыток использовано: ${ d.attempts_used } из ${ d.max_attempts }`;
	// Держим разметку в актуальном состоянии для повторной пересдачи без перезагрузки.
	const footer = panel.querySelector( '.fs-task-footer' );
	if ( footer ) { footer.dataset.attemptsUsed = String( d.attempts_used ); }
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
