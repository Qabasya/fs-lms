/**
 * Работа-шаг (D19, T14.10/T14.11): прохождение стеком карточек задач с
 * виджетами, чипы «Ответ сохранён / Нет ответа», черновики в localStorage,
 * модалка подтверждения → SubmitBatchWork; экран результатов с per-task
 * вердиктами (без эталонов — D19) и кнопкой «Пройти заново».
 */
import { initTaskWidget } from '../frontend/components/task-widget.js';
import { getCore, onPanelShow, isPreview } from './core.js';
import { esc, ICO } from './icons.js';
import { toast } from './shell.js';

const vars = window.fs_lms_player_vars;
const mounted = new WeakSet();

export function initStepWork() {
	if ( ! vars ) { return; }
	onPanelShow( setup );
	const core = getCore();
	if ( core ) { setup( core.panels[ core.activeIndex() ] ); }
}

function setup( panel ) {
	const st = panel.dataset.stepType;
	// #5: step-work.js драйвит и работу, и контрольную-в-предпросмотре — у обеих
	// одинаковый инлайн-UI (.work-root). У реальной контрольной .work-root нет:
	// она уходит на отдельный attempt-флоу, поэтому просто пропускаем панель.
	if ( ( 'work' !== st && 'assessment' !== st ) || mounted.has( panel ) ) { return; }
	const root = panel.querySelector( '.work-root' );
	if ( ! root ) { return; } // .work-root нет — статичная карточка / attempt-флоу
	mounted.add( panel );
	mountWork( panel, root );
}

function mountWork( panel, root ) {
	const core   = getCore();
	const workId = root.dataset.workId;

	let state;
	try { state = JSON.parse( root.dataset.state || '{}' ); } catch { state = {}; }
	state.task_results = state.task_results || {};

	const progressRoot = root.querySelector( '[data-work-progress-root]' );
	const resultsRoot  = root.querySelector( '[data-work-results-root]' );
	const cards        = Array.from( root.querySelectorAll( '.a-task[data-task-id]' ) );
	const draftKey     = `fsWorkDraft:${ core.groupLessonId }:${ workId }`;

	// ── Виджеты + восстановление ответов (черновик > прошлая сдача) ──────
	const drafts  = readDrafts( draftKey );
	const widgets = new Map(); // taskId(string) → widget API

	cards.forEach( ( card ) => {
		const taskId = card.dataset.taskId;
		const widget = initTaskWidget( card );
		if ( ! widget ) { return; }
		widgets.set( taskId, widget );

		const saved = drafts[ taskId ] !== undefined
			? drafts[ taskId ]
			: parseAnswer( state.task_results[ taskId ]?.answer );
		if ( saved !== undefined && saved !== null ) { widget.setAnswer( saved ); }

		widget.onChange( () => {
			drafts[ taskId ] = parseAnswer( widget.collectAnswer() );
			writeDrafts( draftKey, drafts );
			updateChip( card, widget );
			updateProgress();
		} );
		updateChip( card, widget );
	} );

	const answeredCount = () =>
		Array.from( widgets.values() ).filter( ( w ) => w.hasAnswer() ).length;

	function updateChip( card, widget ) {
		const chip = card.querySelector( '[data-task-chip]' );
		if ( ! chip ) { return; }
		const has = widget.hasAnswer();
		chip.className = `stc ${ has ? 'stc-saved' : 'stc-none' }`;
		chip.innerHTML = has ? `${ ICO.check( 11 ) }<span>Ответ сохранён</span>` : 'Нет ответа';
	}

	function updateProgress() {
		const n   = answeredCount();
		const txt = root.querySelector( '[data-work-prog-txt]' );
		const bar = root.querySelector( '[data-work-prog-bar]' );
		if ( txt ) { txt.textContent = `Отвечено ${ n } из ${ cards.length }`; }
		if ( bar ) { bar.style.width = cards.length ? `${ ( n / cards.length ) * 100 }%` : '0%'; }
	}

	updateProgress();

	root.querySelector( '[data-work-finish]' )?.addEventListener( 'click', openConfirm );

	// ── Модалка подтверждения ─────────────────────────────────────────────
	function openConfirm() {
		const n     = answeredCount();
		const total = cards.length;
		const dim   = document.createElement( 'div' );
		dim.className = 'cp-dim';
		dim.innerHTML = '<div class="cp-modal">' +
			`<div class="mi">${ ICO.flag( 20 ) }</div>` +
			'<h3>Завершить работу?</h3>' +
			'<p>После завершения изменить ответы будет нельзя. Задачи с автопроверкой оценятся сразу, развёрнутые ответы проверит преподаватель.</p>' +
			'<div class="mm">' +
				`<span class="chip">${ ICO.check( 12 ) }<span>Отвечено ${ n } из ${ total }</span></span>` +
				( n < total ? `<span class="chip warn">Без ответа: ${ total - n }</span>` : '' ) +
			'</div>' +
			'<div class="macts">' +
				'<button type="button" class="b" data-m-back>Вернуться к работе</button>' +
				`<button type="button" class="b b-pri" data-m-go>${ ICO.flag( 14 ) }<span>Завершить</span></button>` +
			'</div></div>';
		document.body.appendChild( dim );

		dim.addEventListener( 'click', ( e ) => { if ( e.target === dim ) { dim.remove(); } } );
		dim.querySelector( '[data-m-back]' ).addEventListener( 'click', () => dim.remove() );
		dim.querySelector( '[data-m-go]' ).addEventListener( 'click', () => {
			dim.remove();
			submit();
		} );
	}

	// ── Сдача одной кнопкой (SubmitBatchWork) ─────────────────────────────
	async function submit() {
		const preview = isPreview();
		const answers = {};
		widgets.forEach( ( widget, taskId ) => {
			answers[ taskId ] = parseAnswer( widget.collectAnswer() ) ?? '';
		} );

		const fd = new FormData();
		if ( preview ) {
			// #5: dry-run проверка — работа или контрольная (по типу шага), без сохранения.
			const isAssessment = 'assessment' === panel.dataset.stepType;
			fd.append( 'action', isAssessment ? vars.actions.previewCheckAssessment : vars.actions.previewCheckWork );
			fd.append( 'security', vars.nonces.previewSolve );
			fd.append( 'ref', workId );
			fd.append( 'answers', JSON.stringify( answers ) );
		} else {
			fd.append( 'action', vars.actions.submitBatchWork );
			fd.append( 'security', vars.nonces.submitBatchWork );
			fd.append( 'group_lesson_id', core.groupLessonId );
			fd.append( 'work_id', workId );
			fd.append( 'answers', JSON.stringify( answers ) );
		}

		let res;
		try {
			const r = await fetch( vars.ajax_url, { method: 'POST', body: fd } );
			res     = await r.json();
		} catch {
			toast( 'Не удалось отправить работу. Попробуйте ещё раз.' );
			return;
		}

		if ( ! res?.success ) {
			toast( res?.data?.message || 'Не удалось отправить работу.' );
			return;
		}

		const d = res.data;
		state.submission = {
			status      : d.status,
			status_label: d.status_label,
			score       : d.correct,
			max_score   : d.total,
			verdicts    : d.per_task || {},
			submitted_at: d.submitted_at,
		};
		// Ответы последней сдачи — источник для показа в результатах и повторной сдачи.
		widgets.forEach( ( widget, taskId ) => {
			state.task_results[ taskId ] = Object.assign(
				{}, state.task_results[ taskId ], { answer: widget.collectAnswer() }
			);
		} );
		clearDrafts( draftKey );

		const idx = core.panels.indexOf( panel );
		core.setStatus( idx, 'completed' );
		core.unlockNext();

		toast( 'Работа завершена. Автопроверка выполнена' );
		renderResults();
	}

	// ── Экран результатов (T14.11): вердикты без эталонов (D19) ──────────
	function renderResults() {
		const sub      = state.submission;
		const verdicts = sub?.verdicts || {};

		const okCount     = countVerdicts( verdicts, 'correct' );
		const manualCount = countVerdicts( verdicts, 'pending' );
		const autoTotal   = Object.keys( verdicts ).length - manualCount;

		let h = '<div class="res">' +
			`<span class="ri">${ ICO.check( 22 ) }</span>` +
			'<span class="rt"><b>Работа завершена</b>' +
				`<span>${ okCount } из ${ autoTotal } задач решено верно` +
				( manualCount ? ' · развёрнутые ответы на проверке у преподавателя' : '' ) + '</span></span>' +
			'<span class="rstats">' +
				`<span class="rs"><b>${ sub?.score ?? 0 } / ${ sub?.max_score ?? 0 }</b><span>баллов</span></span>` +
				( manualCount ? `<span class="rs"><b>до ${ manualCount }</b><span>за ручную проверку</span></span>` : '' ) +
			'</span></div>';

		h += '<div class="wstack">';
		cards.forEach( ( card, i ) => {
			const taskId  = card.dataset.taskId;
			const verdict = verdicts[ taskId ] || null;
			const result  = state.task_results[ taskId ] || null;
			h += resultCard( i + 1, card.dataset.title, verdict, result );
		} );
		h += '</div>';

		h += '<div class="work-resfoot">' +
			'<button type="button" class="b" data-work-retry>Пройти заново</button>' +
			'</div>';

		resultsRoot.innerHTML = h;
		progressRoot.hidden   = true;
		resultsRoot.hidden    = false;

		resultsRoot.querySelector( '[data-work-retry]' )?.addEventListener( 'click', retry );
	}

	function resultCard( n, title, verdict, result ) {
		const answerText = displayAnswer( result?.answer );
		let vd = '';

		if ( ! verdict ) {
			vd = vdBlock( 'wait', 'Нет данных проверки', 'Результат появится после проверки.' );
		} else if ( 'pending' === verdict.verdict ) {
			// Ручная проверка: балл может быть уже выставлен преподавателем (task_results).
			vd = 'graded' === result?.status
				? vdBlock( 'ok', `Проверено · ${ result.score ?? 0 } из ${ result.max_score ?? 1 }`, result?.feedback || '' )
				: vdBlock( 'wait', 'На проверке у преподавателя', 'Оценка появится после проверки — обычно в течение пары дней.' );
		} else if ( 'correct' === verdict.verdict ) {
			vd = vdBlock( 'ok', `Верно · +${ verdict.score ?? 1 } балл`, '' );
		} else {
			vd = vdBlock( 'no', 'Неверно · 0 баллов', 'Правильный ответ станет доступен после разбора с преподавателем.' );
		}

		return '<div class="a-task">' +
			`<div class="th"><span class="tkn">${ n }</span><b>${ esc( title ) }</b></div>` +
			( answerText ? `<div class="ansbox txt lock">${ esc( answerText ) }</div>` : '<p class="wnote">Ответ не был дан</p>' ) +
			vd +
			'</div>';
	}

	// ── «Пройти заново» (повторная сдача поверх существующей) ────────────
	function retry() {
		resultsRoot.hidden  = true;
		progressRoot.hidden = false;
		widgets.forEach( ( widget, taskId ) => {
			const prev = parseAnswer( state.task_results[ taskId ]?.answer );
			if ( prev !== undefined && prev !== null ) { widget.setAnswer( prev ); }
		} );
		cards.forEach( ( card ) => updateChip( card, widgets.get( card.dataset.taskId ) ) );
		updateProgress();
		toast( 'Ответы можно изменить и сдать работу заново' );
	}


	// Уже сдана (перезагрузка) — сразу результаты.
	if ( state.submission ) { renderResults(); }
}

/* ── Вспомогательные ──────────────────────────────────────────────────── */

function vdBlock( kind, title, body ) {
	const icon = 'ok' === kind ? ICO.check( 13 ) : ( 'no' === kind ? ICO.cross( 11 ) : ICO.clock( 13 ) );
	return `<div class="vd vd-${ kind }"><span class="vi">${ icon }</span><div>` +
		`<b>${ esc( title ) }</b>` + ( body ? `<span>${ esc( body ) }</span>` : '' ) +
		'</div></div>';
}

function countVerdicts( verdicts, kind ) {
	return Object.values( verdicts ).filter( ( v ) => v && v.verdict === kind ).length;
}

/** Ответ хранится JSON-строкой (или сырой строкой) — вернуть значение. */
function parseAnswer( raw ) {
	if ( undefined === raw || null === raw ) { return undefined; }
	if ( 'string' !== typeof raw ) { return raw; }
	try { return JSON.parse( raw ); } catch { return raw; }
}

/** Человекочитаемый ответ для экрана результатов. */
function displayAnswer( raw ) {
	const v = parseAnswer( raw );
	if ( undefined === v || null === v || '' === v ) { return ''; }
	if ( 'string' === typeof v ) { return v; }
	if ( Array.isArray( v ) ) {
		return v.map( ( item ) =>
			item && 'object' === typeof item ? `${ item.left } → ${ item.right }` : String( item )
		).join( ', ' );
	}
	if ( 'object' === typeof v ) {
		return Object.entries( v ).map( ( [ k, val ] ) => `${ k }: ${ val }` ).join( ', ' );
	}
	return String( v );
}

function readDrafts( key ) {
	try { return JSON.parse( localStorage.getItem( key ) || '{}' ) || {}; } catch { return {}; }
}

function writeDrafts( key, drafts ) {
	try { localStorage.setItem( key, JSON.stringify( drafts ) ); } catch {}
}

function clearDrafts( key ) {
	try { localStorage.removeItem( key ); } catch {}
}
