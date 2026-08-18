import '../_types.js';
import { stepIcon, icoPlus, icoDuplicate, icoX } from '../../common/icons.js';
import { escapeHtml as esc } from '../../common/utils.js';
import { showToast } from '../modules/toast.js';
import { ConfirmModal } from '../modals/confirm-modal.js';
import { openPicker } from '../modules/picker.js';
import { ajax, acts, tmpKey } from './step-ajax.js';
import { inlineEditor, destroyTiny } from './step-editors/inline-editor.js';
import { refEditor } from './step-editors/ref-editor.js';

/* global fs_lms_vars */

/**
 * step-editor.js — единый редактор шагов урока (эталон — курс-билдер).
 *
 * Один и тот же UI «шаги урока» (горизонтальная лента чипов + редактор шага +
 * поповер добавления + автосейв) на всех поверхностях: внутри курс-билдера и в
 * нативном метабоксе урока. Никакого «перенести шаг» — шаги только добавляются/
 * удаляются/переупорядочиваются в пределах урока.
 *
 * Бэкенд: `saveLessonSteps`, `getStepCandidates`, `createWorkDraft`,
 * `createAssessmentDraft` (нонсы `authorLesson`/`authorCourse` локализуются
 * глобально в `fs_lms_vars`). Контент шага — модель `LessonDTO.steps[]`.
 *
 * Сателлиты (вынесены без изменения поведения): AJAX-транспорт — `step-ajax.js`,
 * превью ссылочного контента — `step-preview.js`, тела шагов —
 * `step-editors/{inline,video,ref}-editor.js`, попап-пикер — `modules/picker.js`.
 */

// SVG-глифы типов шага — единый источник `common/icons.js` (STEP_GLYPHS/stepIcon),
// общий с плеером (player/icons.js → typeIco).

/**
 * Наш StepType → UI-метаданные. Пять типов шага урока: Текст, Видео,
 * Задача (один ссылочный тип — подстраивается под любую задачу: вопрос с
 * выбором, ЕГЭ с сайта, приватная задача с интерпретатором), Работа, Контрольная.
 */
const TYPE_UI = {
	text:       { ui: 'lecture',  name: 'Текст',       inline: true },
	video:      { ui: 'video',    name: 'Видео',       inline: true },
	broadcast:  { ui: 'broadcast', name: 'Трансляция',  inline: true },
	task:       { ui: 'task',       name: 'Задача',      inline: false, candKind: 'task' },
	work:       { ui: 'practice',  name: 'Работа',      inline: false, candKind: 'work' },
	assessment: { ui: 'assessment', name: 'Экзамен', inline: false, candKind: 'assessment' },
};

/** Опции поповера «Добавить шаг» (плоский type-first). */
const ADD_TYPES = [
	{ type: 'text',       desc: 'Текст, формулы, картинки' },
	{ type: 'video',      desc: 'YouTube, Vimeo, файл' },
	{ type: 'broadcast',  desc: 'Ссылка на трансляцию, после занятия — запись' },
	{ type: 'task',       desc: 'Задача из предмета или банка — любого типа' },
	{ type: 'work',       desc: 'Работа из библиотеки' },
	{ type: 'assessment', desc: 'Экзамен из библиотеки' },
];

/** Максимум шагов в одном уроке (зеркалит серверный LessonAuthoringService::MAX_STEPS_PER_LESSON). */
const MAX_STEPS = 20;

const uiMeta = ( ourType ) => TYPE_UI[ ourType ] || TYPE_UI.text;
const icon   = ( ourType ) => stepIcon( uiMeta( ourType ).ui );

/** UI-меты шага по его типу (Задача сама подстраивается под любую задачу). */
const stepMeta = ( step ) => uiMeta( step ? step.type : 'text' );
const iconForStep = ( step ) => stepIcon( stepMeta( step ).ui );

/**
 * Монтирует редактор шагов одного урока в `mount`.
 *
 * @param {Object}      opts
 * @param {HTMLElement} opts.mount      контейнер
 * @param {Object}      opts.lesson     { id, steps:[{key,type,payload,title?,_title?}] } — мутируется на месте
 * @param {string}      opts.subjectKey
 * @param {Function}   [opts.onChange]     () => void — после любой правки шагов (хост обновляет дерево/счётчики)
 * @param {Function}   [opts.setStatus]    (text) => void — внешний индикатор; иначе модуль рисует свой
 * @param {string[]}   [opts.allowedTypes] фильтр пунктов меню «Добавить шаг» (напр. ['task'] — только задачи)
 * @param {number}     [opts.initialStepRef] deep-link на ссылочный шаг (task/work/assessment) по ref (post id)
 * @param {string}     [opts.initialStepKey] deep-link на text/video-шаг по стабильному step.key (#15-E)
 * @returns {{ destroy: Function }}
 */
export function createStepEditor( opts ) {
	const mount      = opts.mount;
	const lesson     = opts.lesson;
	const subjectKey = String( opts.subjectKey || '' );
	const onChange   = typeof opts.onChange === 'function' ? opts.onChange : () => {};
	const setStatusE = typeof opts.setStatus === 'function' ? opts.setStatus : null;
	const allowed    = Array.isArray( opts.allowedTypes ) ? opts.allowedTypes : null;
	// База wp-admin для ссылок «Редактировать ↗»/«Добавить новую» — из fs_lms_vars.ajaxurl
	// (редактор шагов живёт только в админке: курс-билдер + метабокс урока).
	const adminBase  = ( typeof fs_lms_vars !== 'undefined' && fs_lms_vars )
		? fs_lms_vars.ajaxurl.replace( 'admin-ajax.php', '' )
		: '';

	let activeKey = lesson.steps.length ? lesson.steps[ 0 ].key : null;
	let saveTimer = null;
	// Держатель id активного TinyMCE — общий с inlineEditor/destroyTiny (step-editors/inline-editor.js).
	const tinyState = { id: null };
	let dragKey   = null;

	if ( opts.initialStepRef ) {
		const refStep = lesson.steps.find( ( s ) => Number( s.payload?.ref ) === Number( opts.initialStepRef ) );
		if ( refStep ) { activeKey = refStep.key; }
	}

	// #15-E: deep-link на text/video-шаг (нет payload.ref — адресуем по стабильному step.key).
	if ( opts.initialStepKey ) {
		const keyStep = lesson.steps.find( ( s ) => s.key === opts.initialStepKey );
		if ( keyStep ) { activeKey = keyStep.key; }
	}

	render();

	return { destroy: () => destroyTiny( tinyState ) };

	// ── статус ──
	function setStatus( text ) {
		if ( setStatusE ) { setStatusE( text ); return; }
		const s = mount.querySelector( '[data-status]' );
		if ( s ) { s.innerHTML = `<span class="saved-dot"></span> ${ esc( text ) }`; }
	}

	function current() {
		return lesson.steps.find( ( s ) => s.key === activeKey ) || lesson.steps[ 0 ] || null;
	}

	// ── рендер каркаса ──
	function render() {
		destroyTiny( tinyState );
		mount.innerHTML = `
			<div class="fs-se">
				<div class="steps-label">Шаги</div>
				<div class="steps-row" data-steps></div>
				<div class="step-editor-body" data-body></div>
				${ setStatusE ? '' : '<div class="se-footer"><span class="ef-status" data-status><span class="saved-dot"></span> Все изменения сохранены</span></div>' }
			</div>`;
		renderStepsRow();
		renderStepBody();
	}

	function renderStepsRow() {
		const row  = mount.querySelector( '[data-steps]' );
		const step = current();
		row.innerHTML = '';
		lesson.steps.forEach( ( s, i ) => {
			const chip = document.createElement( 'div' );
			chip.className = 'step-chip' + ( step && s.key === step.key ? ' active' : '' );
			chip.dataset.type = stepMeta( s ).ui;
			chip.draggable = true;
			chip.innerHTML = `
				<div class="step-chip-box"><span class="sc-num">${ i + 1 }</span>${ iconForStep( s ) }${ s.payload && s.payload.needs_review ? '<span class="dashicons dashicons-warning fs-dashicon fs-dashicon--danger sc-warn" title="Дублированный шаг — измените контент"></span>' : '' }</div>
				<span class="sc-type">${ esc( stepMeta( s ).name ) }</span>`;
			chip.addEventListener( 'click', () => { activeKey = s.key; renderStepsRow(); renderStepBody(); } );
			attachStepDrag( chip, s );
			row.appendChild( chip );
		} );

		// Кнопка «Добавить» скрывается при достижении лимита шагов.
		if ( lesson.steps.length < MAX_STEPS ) {
			const add = document.createElement( 'div' );
			add.className = 'step-chip step-add';
			add.innerHTML = '<div class="step-chip-box">' + icoPlus( 22 ) + '</div><span class="sc-type">Добавить</span>';
			add.addEventListener( 'click', openPopover );
			row.appendChild( add );
		}
	}

	// ── drag шагов (в пределах урока) ──
	function attachStepDrag( chip, step ) {
		chip.addEventListener( 'dragstart', ( e ) => { dragKey = step.key; chip.classList.add( 'dragging' ); e.dataTransfer.effectAllowed = 'move'; } );
		chip.addEventListener( 'dragend', () => { dragKey = null; chip.classList.remove( 'dragging' ); } );
		chip.addEventListener( 'dragover', ( e ) => e.preventDefault() );
		chip.addEventListener( 'drop', ( e ) => {
			e.preventDefault();
			if ( ! dragKey || dragKey === step.key ) { return; }
			const from = lesson.steps.findIndex( ( s ) => s.key === dragKey );
			const to   = lesson.steps.findIndex( ( s ) => s.key === step.key );
			const [ m ] = lesson.steps.splice( from, 1 );
			lesson.steps.splice( to, 0, m );
			renderStepsRow();
			saveSteps();
			showToast( 'Шаг перемещён', 'success' );
		} );
	}

	// ══════════ STEP BODY ══════════
	function renderStepBody() {
		destroyTiny( tinyState );
		const body = mount.querySelector( '[data-body]' );
		const step = current();
		if ( ! step ) {
			body.innerHTML = '<div class="editor-empty">В этом уроке пока нет шагов. Нажмите «Добавить».</div>';
			return;
		}
		const meta  = stepMeta( step );
		const index = lesson.steps.indexOf( step ) + 1;

		body.innerHTML = `
			<div class="step-head" data-type="${ meta.ui }">
				<span class="sh-badge">${ iconForStep( step ) } Шаг ${ index }: ${ esc( meta.name ) }</span>
				${ meta.inline ? `<input class="field-input field-input--title" data-step-title value="${ esc( step.payload.title || step.title || '' ) }" placeholder="Название шага">` : '' }
				<div class="sh-controls">
					<button type="button" class="sh-btn sh-btn-dup" data-dup>${ icoDuplicate( 13 ) } Дублировать шаг</button>
					<button type="button" class="sh-btn sh-btn-del" data-del>${ icoX( 13 ) } Удалить шаг</button>
				</div>
			</div>
			<div class="step-editor" data-step-editor></div>`;

		const titleInput = body.querySelector( '[data-step-title]' );
		if ( meta.inline ) {
			titleInput.addEventListener( 'input', () => {
				step.payload.title = titleInput.value; clearReviewFlag( step );
				renderStepsRow();
				scheduleSave();
			} );
		}
		body.querySelector( '[data-dup]' ).addEventListener( 'click', () => dupStep( step ) );
		body.querySelector( '[data-del]' ).addEventListener( 'click', () => delStep( step ) );

		const ed = body.querySelector( '[data-step-editor]' );
		if ( meta.inline ) {
			inlineEditor( ed, step, { tinyState, scheduleSave, clearReviewFlag } );
		} else {
			refEditor( ed, step, {
				stepMeta,
				adminBase,
				subjectKey,
				openLibraryPicker,
				renderStepsRow,
				renderStepBody,
				saveSteps,
				scheduleSave,
				expandStepToBundle,
			} );
		}
	}

	// ══════════ STEP actions ══════════
	// Снимает метку «дубликат — не изменён» при правке контента шага и убирает значок-напоминание.
	// Вызывать ТОЛЬКО из обработчиков реального пользовательского ввода (keyup/input/paste), НЕ из
	// scheduleSave: TinyMCE дёргает NodeChange на init, и автосейв снял бы значок у первого шага при открытии.
	function clearReviewFlag( step ) {
		if ( step && step.payload && step.payload.needs_review ) {
			delete step.payload.needs_review;
			renderStepsRow();
		}
	}
	function dupStep( step ) {
		if ( lesson.steps.length >= MAX_STEPS ) {
			showToast( `В уроке не может быть больше ${ MAX_STEPS } шагов`, 'error' );
			return;
		}
		const i = lesson.steps.indexOf( step );
		const copy = { key: tmpKey( 's' ), type: step.type, title: step.title, payload: Object.assign( {}, step.payload ) };
		if ( copy.payload.title ) { copy.payload.title += ' (копия)'; }
		copy.payload.needs_review = true;
		lesson.steps.splice( i + 1, 0, copy );
		activeKey = copy.key;
		renderStepsRow(); renderStepBody(); onChange();
		saveSteps();
		showToast( 'Шаг дублирован', 'success' );
	}

	// Есть ли в шаге содержимое (для подтверждения удаления).
	function stepHasContent( step ) {
		const p = step.payload || {};
		if ( 'text' === step.type ) { return !! String( p.content || '' ).trim(); }
		if ( 'video' === step.type ) { return !! String( p.url || '' ).trim(); }
		if ( 'broadcast' === step.type ) { return !! String( p.stream_url || '' ).trim(); }
		return parseInt( p.ref || 0, 10 ) > 0; // task / work / assessment — прикреплена сущность
	}

	function delStep( step ) {
		if ( lesson.steps.length <= 1 ) { showToast( 'Нельзя удалить единственный шаг', 'error' ); return; }
		if ( ! stepHasContent( step ) ) { removeStep( step ); return; }
		ConfirmModal.confirm( {
			title:       'Удалить шаг?',
			message:     'В шаге есть содержимое. Удалить его?',
			confirmText: 'Удалить',
			isDanger:    true,
		} ).then( () => removeStep( step ) ).catch( () => {} );
	}

	function removeStep( step ) {
		const i = lesson.steps.indexOf( step );
		if ( i < 0 ) { return; }
		lesson.steps.splice( i, 1 );
		activeKey = lesson.steps[ Math.max( 0, i - 1 ) ].key;
		renderStepsRow(); renderStepBody(); onChange();
		saveSteps();
		showToast( 'Шаг удалён', 'success' );
	}

	/**
	 * Связка 19-21: вместо ref = parent разворачивает ТЕКУЩИЙ шаг «Задача» в 3
	 * отдельных шага подряд (ref = каждый child), см. .docs/Tasks.md, §3.4.
	 *
	 * @param {Object} step     Текущий шаг (заменяется).
	 * @param {Array<{id:number,title:string}>} children Дети связки, порядок 19/20/21.
	 */
	function expandStepToBundle( step, children ) {
		const i = lesson.steps.indexOf( step );
		if ( i < 0 || ! children.length ) { return; }
		if ( lesson.steps.length - 1 + children.length > MAX_STEPS ) {
			showToast( `В уроке не может быть больше ${ MAX_STEPS } шагов`, 'error' );
			return;
		}

		const newSteps = children.map( ( c ) => ( {
			key:     tmpKey( 's' ),
			type:    step.type,
			title:   c.title,
			payload: { ref: c.id },
		} ) );

		lesson.steps.splice( i, 1, ...newSteps );
		activeKey = newSteps[ 0 ].key;
		renderStepsRow(); renderStepBody(); onChange();
		saveSteps();
		showToast( 'Связка разложена на ' + newSteps.length + ' отдельных шага', 'success' );
	}

	function addStep( menuType ) {
		if ( lesson.steps.length >= MAX_STEPS ) {
			showToast( `В уроке не может быть больше ${ MAX_STEPS } шагов`, 'error' );
			return;
		}
		const meta = uiMeta( menuType );
		const step = meta.inline
			? { key: tmpKey( 's' ), type: menuType, title: meta.name, payload: { title: '' } }
			: { key: tmpKey( 's' ), type: menuType, title: meta.name, payload: { ref: 0 } };
		lesson.steps.push( step );
		activeKey = step.key;
		renderStepsRow(); renderStepBody(); onChange();
		saveSteps();
		showToast( meta.name + ' добавлен', 'success' );
	}

	// ══════════ POPOVER ══════════
	function openPopover( e ) {
		e.stopPropagation();
		closePopover();
		const pop = document.createElement( 'div' );
		pop.className = 'fs-cb-popover';
		const addList = allowed ? ADD_TYPES.filter( ( o ) => allowed.includes( o.type ) ) : ADD_TYPES;
		pop.innerHTML = '<div class="sp-title">Добавить шаг</div>' + addList.map( ( o ) => `
			<div class="sp-option" data-type="${ o.type }">
				<span class="spo-ico" data-type="${ uiMeta( o.type ).ui }">${ icon( o.type ) }</span>
				<div><div class="spo-name">${ esc( uiMeta( o.type ).name ) }</div><div class="spo-desc">${ esc( o.desc ) }</div></div>
			</div>` ).join( '' );
		document.body.appendChild( pop );
		const r = e.currentTarget.getBoundingClientRect();
		pop.style.top  = `${ window.scrollY + r.bottom + 6 }px`;
		pop.style.left = `${ Math.min( r.left, window.innerWidth - 260 ) }px`;
		pop.querySelectorAll( '.sp-option' ).forEach( ( opt ) => opt.addEventListener( 'click', () => {
			addStep( opt.dataset.type );
			closePopover();
		} ) );
		setTimeout( () => document.addEventListener( 'click', closePopover, { once: true } ), 0 );
	}
	function closePopover() {
		document.querySelectorAll( '.fs-cb-popover' ).forEach( ( n ) => n.remove() );
	}

	// ── library picker (reuse GetStepCandidates) ──
	function openLibraryPicker( e, kind, onPick ) {
		e.stopPropagation();
		closePopover();
		// Задача тянется сразу из предмета и банка (вариант А); остальные виды — из предмета.
		const source = 'task' === kind ? 'all' : 'subject';
		openPicker( e.currentTarget, {
			placeholder: 'Поиск в библиотеке…',
			fetchFn:     ( search ) => ajax(
				acts().getStepCandidates,
				{ subject_key: subjectKey, kind, source, search }
			),
			onPick,
		} );
	}

	// ══════════ PERSISTENCE ══════════
	function payloadForSave() {
		return lesson.steps.map( ( s ) => ( { key: s.key, type: s.type, payload: s.payload } ) );
	}
	function saveSteps() {
		if ( ! lesson.id ) { return; }
		setStatus( 'Сохранение…' );
		ajax( acts().saveLessonSteps, { lesson_id: lesson.id, subject_key: subjectKey, steps: payloadForSave() } )
			.then( () => setStatus( 'Все изменения сохранены' ) )
			.catch( ( msg ) => { setStatus( 'Ошибка сохранения' ); showToast( msg || 'Ошибка', 'error' ); } );
	}
	function scheduleSave() {
		setStatus( 'Изменения…' );
		clearTimeout( saveTimer );
		saveTimer = setTimeout( saveSteps, 800 );
	}
}

/**
 * Читает сериализованные шаги из скрытого `.fs-sb-data` внутри `el`.
 *
 * @param {HTMLElement} el
 * @returns {Array<{key:string,type:string,payload:object,title:string,_title:string}>}
 */
export function readSteps( el ) {
	const node = el.querySelector( '.fs-sb-data' );
	const raw  = node ? node.textContent : '';
	if ( ! raw ) { return []; }
	try {
		const parsed = JSON.parse( raw );
		return Array.isArray( parsed )
			? parsed.map( ( s ) => ( {
				key:     String( s.key || '' ),
				type:    String( s.type || '' ),
				payload: ( s.payload && typeof s.payload === 'object' ) ? s.payload : {},
				title:   s.title || '',
				_title:  s._title || '',
			} ) ).filter( ( s ) => TYPE_UI[ s.type ] )
			: [];
	} catch ( e ) {
		return [];
	}
}
