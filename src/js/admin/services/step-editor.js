import '../_types.js';
import { showToast } from '../modules/toast.js';

/* global jQuery, fs_lms_vars */
const $ = jQuery;

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
 */

// ── SVG-иконки типов (keyed by UI-тип) ─────────────────────────
const ICON = {
	lecture:  '<svg viewBox="0 0 24 24" width="22" height="22"><path d="M6 3h9l5 5v13a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1zm8 1.5V8h3.5L14 4.5zM8 12h8v1.6H8V12zm0 3.4h8V17H8v-1.6zM8 8.6h4v1.6H8V8.6z"/></svg>',
	video:    '<svg viewBox="0 0 24 24" width="22" height="22"><path d="M4 5h16a1 1 0 0 1 1 1v12a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1zm6 3.2v7.6l6-3.8-6-3.8z"/></svg>',
	practice: '<svg viewBox="0 0 24 24" width="22" height="22"><path d="M9.4 16.6 4.8 12l4.6-4.6L8 6l-6 6 6 6 1.4-1.4zm5.2 0L19.2 12l-4.6-4.6L16 6l6 6-6 6-1.4-1.4z"/></svg>',
	quiz:     '<svg viewBox="0 0 24 24" width="22" height="22"><path d="M4 5h7v2H4V5zm0 6h7v2H4v-2zm0 6h7v2H4v-2zm14.3-9.3 1.4 1.4-5 5-3-3 1.4-1.4 1.6 1.6 3.6-3.6zm0 6 1.4 1.4-5 5-3-3 1.4-1.4 1.6 1.6 3.6-3.6z"/></svg>',
	file:     '<svg viewBox="0 0 24 24" width="22" height="22"><path d="M16.5 6.5 9.6 13.4a2 2 0 1 0 2.8 2.8l6.9-6.9a4 4 0 1 0-5.6-5.6L6.7 10.6a6 6 0 0 0 8.5 8.5L21 13.3l-1.4-1.4-5.8 5.8a4 4 0 0 1-5.7-5.7l7-7a2 2 0 0 1 2.8 2.8l-6.9 6.9-.7-.7 6.9-6.9-1.6-1.6z"/></svg>',
};

/** Наш StepType → UI-метаданные. */
export const TYPE_UI = {
	text:       { ui: 'lecture',  name: 'Текст',            inline: true },
	video:      { ui: 'video',    name: 'Видео',            inline: true },
	material:   { ui: 'file',     name: 'Файл',             inline: true },   // только рендер legacy-шагов
	// Вопрос/Задание-с-кодом — это task-шаг с категорией шаблона (payload.category).
	question:   { ui: 'quiz',     name: 'Вопрос',           inline: false, candKind: 'task', category: 'question' },
	code:       { ui: 'practice', name: 'Задание с кодом',  inline: false, candKind: 'task', category: 'code' },
	work:       { ui: 'practice', name: 'Практика',         inline: false, candKind: 'work' },
	assessment: { ui: 'quiz',     name: 'Тест',             inline: false, candKind: 'assessment' },
	task:       { ui: 'practice', name: 'Задача',           inline: false, candKind: 'task' },   // fallback
};

/** Опции поповера «Добавить шаг» (плоский type-first). */
const ADD_TYPES = [
	{ type: 'text',       desc: 'Текст, формулы, картинки' },
	{ type: 'video',      desc: 'YouTube, Vimeo, файл' },
	{ type: 'question',   desc: 'Вписать ответ / выбрать вариант' },
	{ type: 'code',       desc: 'Редактор кода, интерпретатор' },
	{ type: 'work',       desc: 'Практика: набор задач (из библиотеки)' },
	{ type: 'assessment', desc: 'Тест/контрольная (из библиотеки)' },
];

export const uiMeta = ( ourType ) => TYPE_UI[ ourType ] || TYPE_UI.text;
export const icon   = ( ourType ) => ICON[ uiMeta( ourType ).ui ] || ICON.lecture;
const acts          = () => fs_lms_vars.ajax_actions;

/** UI-меты шага с учётом категории task-шага (Вопрос / Задание с кодом). */
const stepMeta = ( step ) => ( step && 'task' === step.type && step.payload && TYPE_UI[ step.payload.category ] )
	? TYPE_UI[ step.payload.category ]
	: uiMeta( step ? step.type : 'text' );
const iconForStep = ( step ) => ICON[ stepMeta( step ).ui ] || ICON.lecture;

let _idc = 5000;
export const tmpKey = ( p ) => `${ p }_tmp_${ Date.now() }_${ ++_idc }`;
export const esc = ( s ) => String( s == null ? '' : s )
	.replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' ).replace( /"/g, '&quot;' );

// ── AJAX (нонс по экшену; оба нонса в fs_lms_vars глобально) ────
export function nonceFor( action ) {
	const a = acts();
	const lessonScoped = [ a.saveLessonSteps, a.getStepCandidates ];
	return lessonScoped.includes( action )
		? fs_lms_vars.nonces.authorLesson
		: fs_lms_vars.nonces.authorCourse;
}

export function ajax( action, data ) {
	return new Promise( ( resolve, reject ) => {
		$.post( fs_lms_vars.ajaxurl, Object.assign( { action, security: nonceFor( action ) }, data ) )
			.done( ( resp ) => ( resp && resp.success ) ? resolve( resp.data ) : reject( ( resp && resp.data ) || 'Ошибка' ) )
			.fail( () => reject( 'Ошибка сети' ) );
	} );
}

/**
 * Монтирует редактор шагов одного урока в `mount`.
 *
 * @param {Object}      opts
 * @param {HTMLElement} opts.mount      контейнер
 * @param {Object}      opts.lesson     { id, steps:[{key,type,payload,title?,_title?}] } — мутируется на месте
 * @param {string}      opts.subjectKey
 * @param {Function}   [opts.onChange]     () => void — после любой правки шагов (хост обновляет дерево/счётчики)
 * @param {Function}   [opts.setStatus]    (text) => void — внешний индикатор; иначе модуль рисует свой
 * @param {string[]}   [opts.allowedTypes] фильтр пунктов меню «Добавить шаг» (напр. ['question','code'] для работы)
 * @param {Function}   [opts.persist]      (steps) => Promise — своё сохранение; иначе дефолтный saveLessonSteps
 * @returns {{ destroy: Function }}
 */
export function createStepEditor( opts ) {
	const mount      = opts.mount;
	const lesson     = opts.lesson;
	const subjectKey = String( opts.subjectKey || '' );
	const onChange   = typeof opts.onChange === 'function' ? opts.onChange : () => {};
	const setStatusE = typeof opts.setStatus === 'function' ? opts.setStatus : null;
	const allowed    = Array.isArray( opts.allowedTypes ) ? opts.allowedTypes : null;
	const persist    = typeof opts.persist === 'function' ? opts.persist : null;

	let activeKey = lesson.steps.length ? lesson.steps[ 0 ].key : null;
	let saveTimer = null;
	let tinyId    = null;
	let dragKey   = null;

	render();

	return { destroy: destroyTiny };

	// ── статус ──
	function setStatus( text ) {
		if ( setStatusE ) { setStatusE( text ); return; }
		const s = mount.querySelector( '[data-status]' );
		if ( s ) { s.innerHTML = `<span class="saved-dot"></span> ${ esc( text ) }`; }
	}

	function destroyTiny() {
		if ( tinyId ) {
			if ( window.wp?.editor ) {
				window.wp.editor.remove( tinyId );
			} else if ( window.tinymce?.get( tinyId ) ) {
				window.tinymce.get( tinyId ).remove();
			}
			tinyId = null;
		}
	}

	function current() {
		return lesson.steps.find( ( s ) => s.key === activeKey ) || lesson.steps[ 0 ] || null;
	}

	// ── рендер каркаса ──
	function render() {
		destroyTiny();
		mount.innerHTML = `
			<div class="fs-se">
				<div class="steps-label">Шаги урока</div>
				<div class="steps-row" data-steps></div>
				<div class="step-editor-body" data-body></div>
				<div class="se-footer"><span class="ef-status" data-status><span class="saved-dot"></span> Все изменения сохранены</span></div>
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
				<div class="step-chip-box"><span class="sc-num">${ i + 1 }</span>${ iconForStep( s ) }</div>
				<span class="sc-type">${ esc( stepMeta( s ).name ) }</span>`;
			chip.addEventListener( 'click', () => { activeKey = s.key; renderStepsRow(); renderStepBody(); } );
			attachStepDrag( chip, s );
			row.appendChild( chip );
		} );

		const add = document.createElement( 'div' );
		add.className = 'step-chip step-add';
		add.innerHTML = '<div class="step-chip-box"><svg width="22" height="22" viewBox="0 0 24 24"><path fill="currentColor" d="M11 5h2v6h6v2h-6v6h-2v-6H5v-2h6z"/></svg></div><span class="sc-type">Добавить</span>';
		add.addEventListener( 'click', openPopover );
		row.appendChild( add );
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
		destroyTiny();
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
				<input class="field-input field-input--title" data-step-title value="${ esc( step.payload.title || step.title || '' ) }" placeholder="Название шага"${ meta.inline ? '' : ' disabled' }>
				<div class="sh-controls">
					<button type="button" class="icon-btn" title="Дублировать" data-dup>⧉</button>
					<button type="button" class="icon-btn danger" title="Удалить шаг" data-del>✕</button>
				</div>
			</div>
			<div class="step-editor" data-step-editor></div>`;

		const titleInput = body.querySelector( '[data-step-title]' );
		if ( meta.inline ) {
			titleInput.addEventListener( 'input', () => {
				step.payload.title = titleInput.value;
				renderStepsRow();
				scheduleSave();
			} );
		}
		body.querySelector( '[data-dup]' ).addEventListener( 'click', () => dupStep( step ) );
		body.querySelector( '[data-del]' ).addEventListener( 'click', () => delStep( step ) );

		const ed = body.querySelector( '[data-step-editor]' );
		if ( meta.inline ) {
			inlineEditor( ed, step );
		} else {
			refEditor( ed, step );
		}
	}

	function inlineEditor( ed, step ) {
		if ( 'text' === step.type ) {
			const tid = `fs-se-rte-${ Date.now() }`;
			tinyId = tid;
			ed.innerHTML =
				'<div class="sb-label">Содержание лекции</div>' +
				'<textarea id="' + tid + '" class="fs-cb-rte-target"></textarea>';
			ed.querySelector( '#' + tid ).value = step.payload.content || '';

			function onEditorChange() {
				const mc = window.tinymce?.get( tid );
				step.payload.content = mc ? mc.getContent() : ( ed.querySelector( '#' + tid )?.value ?? '' );
				scheduleSave();
			}

			// Добавляет кнопки LaTeX в тулбар TinyMCE 4.
			// Кнопки оборачивают выделение (или вставляют placeholder) в \(...\) / \[...\].
			function setupLatexButtons( editor ) {
				editor.addButton( 'latex_inline', {
					text    : '\\(…\\)',
					tooltip : 'Инлайн-формула LaTeX',
					onclick() {
						const sel = editor.selection.getContent( { format: 'text' } ).trim();
						editor.selection.setContent( '\\(' + ( sel || '  ' ) + '\\)' );
					},
				} );
				editor.addButton( 'latex_block', {
					text    : '\\[…\\]',
					tooltip : 'Блочная формула LaTeX',
					onclick() {
						const sel = editor.selection.getContent( { format: 'text' } ).trim();
						editor.selection.setContent( '\\[' + ( sel || '  ' ) + '\\]' );
					},
				} );
				editor.on( 'NodeChange change', onEditorChange );
			}

			if ( window.wp?.editor ) {
				// wp.editor.initialize() — стандартный WP API.
				// WP Bakery (если установлен) перехватывает этот вызов и добавляет
				// свою кнопку «Backend Editor» автоматически.
				window.wp.editor.initialize( tid, {
					tinymce: {
						wpautop  : true,
						plugins  : 'charmap colorpicker hr lists paste tabfocus textcolor wordpress wpautoresize wpeditimage wplink wptextpattern',
						toolbar1 : 'bold italic underline strikethrough | formatselect | forecolor | bullist numlist | blockquote hr | alignleft aligncenter alignright | link unlink | removeformat | undo redo',
						toolbar2 : 'charmap | latex_inline latex_block',
						height   : 400,
						setup    : setupLatexButtons,
					},
					quicktags   : { buttons: 'strong,em,link,ul,ol,li,code,close' },
					mediaButtons: true,
				} );
			} else if ( window.tinymce ) {
				window.tinymce.init( {
					selector  : '#' + tid,
					toolbar   : 'bold italic underline strikethrough | formatselect | bullist numlist | blockquote hr | alignleft aligncenter alignright | link | charmap | removeformat | undo redo | latex_inline latex_block',
					menubar   : false,
					statusbar : false,
					plugins   : 'link lists hr charmap',
					height    : 400,
					skin_url  : window.tinymce?.baseURL + '/skins/lightgray',
					setup     : setupLatexButtons,
				} );
			} else {
				const area = ed.querySelector( '#' + tid );
				area.setAttribute( 'style', 'display:none' );
				const div = document.createElement( 'div' );
				div.className = 'rte-area';
				div.contentEditable = 'true';
				div.innerHTML = step.payload.content || '';
				div.addEventListener( 'input', () => { step.payload.content = div.innerHTML; scheduleSave(); } );
				ed.appendChild( div );
			}
		} else if ( 'video' === step.type ) {
			ed.innerHTML = `
				<div class="field-row"><label>Ссылка на видео</label><input class="field-input" data-url placeholder="https://youtube.com/watch?v=…"></div>
				<div class="field-row"><label>Описание под видео</label><textarea class="field-input" data-desc placeholder="Краткое описание…"></textarea></div>`;
			const url  = ed.querySelector( '[data-url]' );
			const desc = ed.querySelector( '[data-desc]' );
			url.value  = step.payload.url || '';
			desc.value = step.payload.description || '';
			url.addEventListener( 'input', () => { step.payload.url = url.value; scheduleSave(); } );
			desc.addEventListener( 'input', () => { step.payload.description = desc.value; scheduleSave(); } );
		} else { // material
			const refId  = parseInt( step.payload.article_id || 0, 10 );
			const attId  = parseInt( step.payload.attachment_id || 0, 10 );
			const attUrl = step.payload.attachment_url || '';
			const label  = step._title || ( attId ? attUrl.split( '/' ).pop() : refId ? `Статья #${ refId }` : 'не выбрано' );
			ed.innerHTML = `
				<div class="sb-label">Файл / материал</div>
				<div class="fs-cb-ref" data-ref-area>
					<span class="fs-cb-ref-title">${ esc( label ) }</span>
					${ attUrl ? `<a href="${ esc( attUrl ) }" target="_blank" rel="noopener" class="fs-cb-ref-edit">открыть ↗</a>` : '' }
				</div>
				<div class="fs-cb-mat-actions">
					<button type="button" class="button" data-pick-media>Медиатека</button>
					<button type="button" class="button" data-pick-article>Из библиотеки статей</button>
				</div>`;
			ed.querySelector( '[data-pick-media]' ).addEventListener( 'click', () => {
				openWpMedia( ( id, url, filename ) => {
					step.payload.attachment_id  = id;
					step.payload.attachment_url = url;
					step.payload.article_id     = 0;
					step._title = filename;
					renderStepsRow(); renderStepBody(); saveSteps();
				} );
			} );
			ed.querySelector( '[data-pick-article]' ).addEventListener( 'click', ( e ) => openLibraryPicker( e, 'article', ( id, title ) => {
				step.payload.article_id     = id;
				step.payload.attachment_id  = 0;
				step.payload.attachment_url = '';
				step._title = title;
				renderStepsRow(); renderStepBody(); saveSteps();
			} ) );
		}
	}

	function refEditor( ed, step ) {
		const meta     = stepMeta( step );
		const candKind = meta.candKind; // task | work | assessment
		const refId    = parseInt( step.payload.ref || 0, 10 );
		const isWork   = 'work' === candKind;
		ed.innerHTML = `
			<div class="sb-label">${ esc( meta.name ) } из библиотеки</div>
			<div class="fs-cb-ref">
				<span class="fs-cb-ref-title">${ refId ? esc( step._title || step.title ) : 'не выбрано' }</span>
				${ refId ? `<a class="fs-cb-ref-edit" href="post.php?post=${ refId }&action=edit" target="_blank" rel="noopener">редактировать ↗</a>` : '' }
				<button type="button" class="button" data-pick>${ refId ? 'Заменить' : 'Выбрать из библиотеки' }</button>
			</div>
			<div class="fs-cb-or-divider"><span>или</span></div>
			<div class="fs-cb-inline-create">
				<input type="text" class="field-input" data-new-title placeholder="Название новой ${ esc( meta.name.toLowerCase() ) }">
				${ isWork ? `<select class="field-input" data-work-type>
					<option value="homework">Домашнее задание</option>
					<option value="classwork">Классная работа</option>
					<option value="project">Проект</option>
				</select>` : '' }
				<button type="button" class="button button-primary" data-create>Создать и прикрепить</button>
			</div>`;

		ed.querySelector( '[data-pick]' ).addEventListener( 'click', ( e ) => openLibraryPicker( e, candKind, ( id, title ) => {
			step.payload.ref = id; step._title = title; step.title = title;
			renderStepsRow(); renderStepBody(); saveSteps();
		} ) );

		ed.querySelector( '[data-create]' ).addEventListener( 'click', () => {
			const title = ed.querySelector( '[data-new-title]' ).value.trim();
			if ( ! title ) { return; }
			const btn = ed.querySelector( '[data-create]' );
			btn.disabled = true;
			const params = { subject_key: subjectKey, title };
			let action;
			if ( 'work' === candKind ) {
				action = acts().createWorkDraft;
				params.work_type = ed.querySelector( '[data-work-type]' ).value;
			} else if ( 'assessment' === candKind ) {
				action = acts().createAssessmentDraft;
			} else {
				// task: Вопрос / Задание с кодом — создаём задачу с шаблоном категории
				action = acts().createTaskDraft;
				params.category = ( step.payload && step.payload.category ) || 'question';
			}
			ajax( action, params )
				.then( ( item ) => {
					step.payload.ref = parseInt( item.id, 10 );
					step._title      = item.title;
					step.title       = item.title;
					renderStepsRow(); renderStepBody(); saveSteps();
					showToast( meta.name + ' создан', 'success' );
				} )
				.catch( ( msg ) => { showToast( msg, 'error' ); btn.disabled = false; } );
		} );
	}

	// ══════════ STEP actions ══════════
	function dupStep( step ) {
		const i = lesson.steps.indexOf( step );
		const copy = { key: tmpKey( 's' ), type: step.type, title: step.title, payload: Object.assign( {}, step.payload ) };
		if ( copy.payload.title ) { copy.payload.title += ' (копия)'; }
		lesson.steps.splice( i + 1, 0, copy );
		activeKey = copy.key;
		renderStepsRow(); renderStepBody(); onChange();
		saveSteps();
		showToast( 'Шаг дублирован', 'success' );
	}

	function delStep( step ) {
		if ( lesson.steps.length <= 1 ) { showToast( 'Нельзя удалить единственный шаг', 'error' ); return; }
		const i = lesson.steps.indexOf( step );
		lesson.steps.splice( i, 1 );
		activeKey = lesson.steps[ Math.max( 0, i - 1 ) ].key;
		renderStepsRow(); renderStepBody(); onChange();
		saveSteps();
		showToast( 'Шаг удалён', 'success' );
	}

	function addStep( menuType ) {
		const meta = uiMeta( menuType );
		let step;
		if ( 'question' === menuType || 'code' === menuType ) {
			// Атом-задача: один ref-шаг `task` с категорией шаблона.
			step = { key: tmpKey( 's' ), type: 'task', title: meta.name, payload: { ref: 0, category: menuType } };
		} else if ( meta.inline ) {
			step = { key: tmpKey( 's' ), type: menuType, title: meta.name, payload: { title: '' } };
		} else {
			step = { key: tmpKey( 's' ), type: menuType, title: meta.name, payload: { ref: 0 } };
		}
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
		openPicker( e.currentTarget, {
			placeholder: 'Поиск в библиотеке…',
			fetchFn:     ( search ) => ajax( acts().getStepCandidates, { subject_key: subjectKey, kind, source: 'subject', search } ),
			onPick,
		} );
	}

	// ── WP Media picker ──
	function openWpMedia( onPick ) {
		if ( ! window.wp || ! window.wp.media ) { return; }
		const frame = window.wp.media( { title: 'Выберите файл', button: { text: 'Выбрать' }, multiple: false } );
		frame.on( 'select', () => {
			const att = frame.state().get( 'selection' ).first().toJSON();
			onPick( att.id, att.url, att.filename || att.title || att.url.split( '/' ).pop() );
		} );
		frame.open();
	}

	// ══════════ PERSISTENCE ══════════
	function payloadForSave() {
		return lesson.steps.map( ( s ) => ( { key: s.key, type: s.type, payload: s.payload } ) );
	}
	function saveSteps() {
		if ( ! lesson.id ) { return; }
		setStatus( 'Сохранение…' );
		const p = persist
			? persist( payloadForSave() )
			: ajax( acts().saveLessonSteps, { lesson_id: lesson.id, subject_key: subjectKey, steps: payloadForSave() } );
		Promise.resolve( p )
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
 * Открывает универсальный попап-пикер (поиск + список элементов).
 *
 * @param {HTMLElement} anchor      Элемент-якорь для позиционирования.
 * @param {Object}      opts
 * @param {string}     [opts.placeholder='Поиск…']
 * @param {string}     [opts.emptyText='Ничего не найдено']
 * @param {Function}    opts.fetchFn   (search: string) => Promise<{id, title}[]>
 * @param {Function}    opts.onPick    (id: number, title: string) => void
 */
export function openPicker( anchor, { placeholder = 'Поиск…', emptyText = 'Ничего не найдено', fetchFn, onPick } ) {
	const pop = document.createElement( 'div' );
	pop.className = 'fs-cb-popover fs-cb-picker';
	pop.innerHTML = `<input type="text" class="field-input" data-search placeholder="${ esc( placeholder ) }"><div class="fs-cb-pick-results" data-results></div>`;
	document.body.appendChild( pop );
	const r = anchor.getBoundingClientRect();
	pop.style.top  = `${ window.scrollY + r.bottom + 6 }px`;
	pop.style.left = `${ Math.min( r.left, window.innerWidth - 320 ) }px`;
	const results = pop.querySelector( '[data-results]' );
	const search  = pop.querySelector( '[data-search]' );
	let t = null;
	const run = () => Promise.resolve( fetchFn( search.value.trim() ) )
		.then( ( items ) => {
			results.innerHTML = '';
			if ( ! items.length ) { results.innerHTML = `<div class="fs-cb-pick-empty">${ esc( emptyText ) }</div>`; return; }
			items.forEach( ( it ) => {
				const opt = document.createElement( 'div' );
				opt.className = 'fs-cb-pick-opt';
				opt.textContent = it.title;
				opt.addEventListener( 'click', () => { onPick( parseInt( it.id, 10 ), it.title ); pop.remove(); } );
				results.appendChild( opt );
			} );
		} )
		.catch( () => { results.innerHTML = '<div class="fs-cb-pick-empty">Ошибка</div>'; } );
	search.addEventListener( 'input', () => { clearTimeout( t ); t = setTimeout( run, 300 ); } );
	run();
	setTimeout( () => document.addEventListener( 'click', function once( ev ) {
		if ( ! pop.contains( ev.target ) ) { pop.remove(); } else { document.addEventListener( 'click', once, { once: true } ); }
	}, { once: true } ), 0 );
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