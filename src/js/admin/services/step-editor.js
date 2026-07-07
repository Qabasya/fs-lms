import '../_types.js';
import { stepIcon, icoPlus, icoDuplicate, icoX, icoReplace } from '../../common/icons.js';
import { showToast } from '../modules/toast.js';
import { TaskEditor } from './task-editor.js';
import { ConfirmModal } from '../modals/confirm-modal.js';

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

// SVG-глифы типов шага — единый источник `common/icons.js` (STEP_GLYPHS/stepIcon),
// общий с плеером (player/icons.js → typeIco).

/**
 * Наш StepType → UI-метаданные. Пять типов шага урока: Текст, Видео,
 * Задача (один ссылочный тип — подстраивается под любую задачу: вопрос с
 * выбором, ЕГЭ с сайта, приватная задача с интерпретатором), Работа, Контрольная.
 */
export const TYPE_UI = {
	text:       { ui: 'lecture',  name: 'Текст',       inline: true },
	video:      { ui: 'video',    name: 'Видео',       inline: true },
	task:       { ui: 'task',       name: 'Задача',      inline: false, candKind: 'task' },
	work:       { ui: 'practice',  name: 'Работа',      inline: false, candKind: 'work' },
	assessment: { ui: 'assessment', name: 'Контрольная', inline: false, candKind: 'assessment' },
};

/** Опции поповера «Добавить шаг» (плоский type-first). */
const ADD_TYPES = [
	{ type: 'text',       desc: 'Текст, формулы, картинки' },
	{ type: 'video',      desc: 'YouTube, Vimeo, файл' },
	{ type: 'task',       desc: 'Задача из предмета или банка — любого типа' },
	{ type: 'work',       desc: 'Работа из библиотеки' },
	{ type: 'assessment', desc: 'Контрольная из библиотеки' },
];

export const uiMeta = ( ourType ) => TYPE_UI[ ourType ] || TYPE_UI.text;
export const icon   = ( ourType ) => stepIcon( uiMeta( ourType ).ui );
const acts          = () => fs_lms_vars.ajax_actions;

/** UI-меты шага по его типу (Задача сама подстраивается под любую задачу). */
const stepMeta = ( step ) => uiMeta( step ? step.type : 'text' );
const iconForStep = ( step ) => stepIcon( stepMeta( step ).ui );

let _idc = 5000;
export const tmpKey = ( p ) => `${ p }_tmp_${ Date.now() }_${ ++_idc }`;
export const esc = ( s ) => String( s == null ? '' : s )
	.replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' ).replace( /"/g, '&quot;' );

// ── AJAX (нонс по экшену; оба нонса в fs_lms_vars глобально) ────
export function nonceFor( action ) {
	const a = acts();
	const lessonScoped     = [ a.saveLessonSteps, a.getStepCandidates ];
	const assessmentScoped = [ a.getTaskPreview, a.getRefPreview ];
	if ( lessonScoped.includes( action ) )     { return fs_lms_vars.nonces.authorLesson; }
	if ( assessmentScoped.includes( action ) ) { return fs_lms_vars.nonces.authorAssessment; }
	return fs_lms_vars.nonces.authorCourse;
}

export function ajax( action, data ) {
	return new Promise( ( resolve, reject ) => {
		$.post( fs_lms_vars.ajaxurl, Object.assign( { action, security: nonceFor( action ) }, data ) )
			.done( ( resp ) => ( resp && resp.success ) ? resolve( resp.data ) : reject( ( resp && resp.data ) || 'Ошибка' ) )
			.fail( () => reject( 'Ошибка сети' ) );
	} );
}

function buildAnswerSection( data ) {
	const lbl = ( t ) => '<div class="fs-cb-tp-label">' + t + '</div>';

	if ( data.options && Array.isArray( data.options.options ) && data.options.options.length ) {
		const html = data.options.options.map( ( o ) =>
			'<div class="fs-cb-tp-option' + ( o.correct ? ' is-correct' : '' ) + '">' +
			'<span class="fs-cb-tp-opt-mark">' + ( o.correct ? '✓' : '·' ) + '</span>' +
			'<span>' + esc( String( o.text || '' ) ) + '</span>' +
			'</div>'
		).join( '' );
		return lbl( 'Варианты ответа' ) + '<div class="fs-cb-tp-options">' + html + '</div>';
	}

	if ( data.pairs && Array.isArray( data.pairs.pairs ) && data.pairs.pairs.length ) {
		const html = data.pairs.pairs.map( ( p ) =>
			'<div class="fs-cb-tp-pair">' +
			'<span class="fs-cb-tp-pair-l">' + esc( String( p.left || '' ) ) + '</span>' +
			'<span class="fs-cb-tp-pair-arrow">→</span>' +
			'<span class="fs-cb-tp-pair-r">' + esc( String( p.right || '' ) ) + '</span>' +
			'</div>'
		).join( '' );
		return lbl( 'Сопоставление' ) + '<div class="fs-cb-tp-pairs">' + html + '</div>';
	}

	if ( data.order_items && Array.isArray( data.order_items.items ) && data.order_items.items.length ) {
		const html = data.order_items.items.map( ( item ) => '<li>' + esc( String( item ) ) + '</li>' ).join( '' );
		return lbl( 'Порядок элементов' ) + '<ol class="fs-cb-tp-order">' + html + '</ol>';
	}

	if ( data.gap_text ) {
		const processed = esc( data.gap_text ).replace( /\[\[([^\]]+)\]\]/g, '<span class="fs-cb-tp-gap-fill">$1</span>' );
		return lbl( 'Текст с пропусками' ) + '<div class="fs-cb-tp-gap">' + processed + '</div>';
	}

	if ( Array.isArray( data.three_in_one ) && data.three_in_one.length ) {
		const html = data.three_in_one.map( ( sub, i ) =>
			'<div class="fs-cb-tp-subtask">' +
			'<div class="fs-cb-tp-subtask-num">Подзадание ' + ( i + 1 ) + '</div>' +
			( sub.condition ? '<div class="fs-cb-tp-subtask-cond">' + sub.condition + '</div>' : '' ) +
			( sub.answer ? '<div class="fs-cb-tp-subtask-ans">' + esc( sub.answer ) + '</div>' : '' ) +
			'</div>'
		).join( '' );
		return lbl( 'Подзадания' ) + '<div class="fs-cb-tp-subtasks">' + html + '</div>';
	}

	if ( data.answer_html ) {
		return lbl( 'Ответ' ) + '<div class="fs-cb-tp-body">' + data.answer_html + '</div>';
	}

	return '';
}

function buildRefTaskBody( task ) {
	let html = '';
	if ( task.condition_html ) {
		html += '<div class="fs-cb-tp-section"><div class="fs-cb-tp-label">Условие</div><div class="fs-cb-tp-body">' + task.condition_html + '</div></div>';
	}
	const ans = buildAnswerSection( task );
	if ( ans ) { html += '<div class="fs-cb-tp-section">' + ans + '</div>'; }
	return html || '<div class="fs-cb-tp-section"><div class="fs-cb-tp-loading">Нет содержимого</div></div>';
}

function loadRefPreview( container, refId, type ) {
	container.innerHTML = '<div class="fs-cb-tp-loading">Загрузка задач…</div>';
	ajax( acts().getRefPreview, { ref_id: refId, ref_type: type } )
		.then( ( data ) => {
			if ( ! data.tasks || ! data.tasks.length ) {
				container.innerHTML = '<div class="fs-cb-tp-loading">Задачи не добавлены</div>';
				return;
			}
			let html = '<div class="fs-modal-accordion">';
			data.tasks.forEach( ( task, i ) => {
				html +=
					'<div class="fs-modal-accordion__item">' +
					'<button type="button" class="fs-modal-accordion__header" aria-expanded="false">' +
					'<h3>' + ( i + 1 ) + '. ' + esc( task.title ) + '</h3>' +
					'<span class="dashicons dashicons-arrow-down-alt2"></span>' +
					'</button>' +
					'<div class="fs-modal-accordion__body" hidden>' + buildRefTaskBody( task ) + '</div>' +
					'</div>';
			} );
			html += '</div>';
			container.innerHTML = html;
			container.querySelectorAll( '.fs-modal-accordion__header' ).forEach( ( btn ) => {
				btn.addEventListener( 'click', () => {
					const expanded = btn.getAttribute( 'aria-expanded' ) === 'true';
					btn.setAttribute( 'aria-expanded', String( ! expanded ) );
					btn.nextElementSibling.hidden = expanded;
				} );
			} );
		} )
		.catch( () => {
			container.innerHTML = '<div class="fs-cb-tp-loading">Ошибка загрузки</div>';
		} );
}

function loadTaskPreview( container, taskId ) {
	const box = container.querySelector( '[data-task-preview]' );
	if ( ! box ) { return; }
	box.innerHTML = '<div class="fs-cb-tp-loading">…</div>';
	ajax( acts().getTaskPreview, { task_id: taskId } )
		.then( ( data ) => renderTaskPreview( box, data ) )
		.catch( () => { box.innerHTML = ''; } );
}

function renderTaskPreview( box, data ) {
	const parts = [];

	if ( data.condition_html ) {
		parts.push(
			'<div class="fs-cb-tp-section">' +
			'<div class="fs-cb-tp-label">Условие</div>' +
			'<div class="fs-cb-tp-body">' + data.condition_html + '</div>' +
			'</div>'
		);
	}

	if ( data.audio_url ) {
		parts.push(
			'<div class="fs-cb-tp-section">' +
			'<audio controls class="fs-cb-tp-audio" src="' + esc( data.audio_url ) + '"></audio>' +
			'</div>'
		);
	}

	const answerSec = buildAnswerSection( data );
	if ( answerSec ) { parts.push( '<div class="fs-cb-tp-section">' + answerSec + '</div>' ); }

	if ( data.solution_html ) {
		parts.push(
			'<div class="fs-cb-tp-section">' +
			'<div class="fs-cb-tp-label">Решение</div>' +
			'<div class="fs-cb-tp-body">' + data.solution_html + '</div>' +
			'</div>'
		);
	}

	box.innerHTML = parts.join( '' );
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
 * @param {string[]}   [opts.allowedTypes] фильтр пунктов меню «Добавить шаг» (напр. ['task'] — только задачи)
 * @param {Function}   [opts.persist]      (steps) => Promise — своё сохранение; иначе дефолтный saveLessonSteps
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
	const allowed         = Array.isArray( opts.allowedTypes ) ? opts.allowedTypes : null;
	const persist         = typeof opts.persist === 'function' ? opts.persist : null;
	const showStepSettings = opts.showStepSettings !== false;

	let activeKey = lesson.steps.length ? lesson.steps[ 0 ].key : null;
	let saveTimer = null;
	let tinyId    = null;
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

		const add = document.createElement( 'div' );
		add.className = 'step-chip step-add';
		add.innerHTML = '<div class="step-chip-box">' + icoPlus( 22 ) + '</div><span class="sc-type">Добавить</span>';
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
			inlineEditor( ed, step );
		} else {
			refEditor( ed, step );
		}
	}

	function inlineEditor( ed, step ) {
		if ( 'text' === step.type ) {
			const tid = `fs-se-rte-${ Date.now() }`;
			tinyId = tid;
			ed.innerHTML ='<textarea id="' + tid + '" class="fs-cb-rte-target"></textarea>';
			ed.querySelector( '#' + tid ).value = step.payload.content || '';
			ed.classList.add( 'fs-rte-loading' ); // анти-флэш: снимется по событию init редактора

			function onEditorChange() {
				const mc = window.tinymce?.get( tid );
				step.payload.content = mc ? mc.getContent() : ( ed.querySelector( '#' + tid )?.value ?? '' );
				scheduleSave();
			}

			// Добавляет кнопки LaTeX в тулбар TinyMCE 4.
			// Кнопки оборачивают выделение (или вставляют placeholder) в \(...\) / \[...\].
			function setupLatexButtons( editor ) {
				editor.addButton( 'code_inline', {
					text   : '</>',
					tooltip: 'Инлайн-код',
					onclick() {
						editor.formatter.toggle( 'code_inline' );
					},
					onPostRender() {
						const btn = this;
						editor.on( 'NodeChange', () => btn.active( editor.formatter.match( 'code_inline' ) ) );
					},
				} );
				editor.on( 'init', () => {
					editor.formatter.register( 'code_inline', { inline: 'code' } );
					ed.classList.remove( 'fs-rte-loading' );
				} );
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
				editor.addButton( 'fs_media', {
					icon   : 'image',
					tooltip: 'Добавить медиафайл',
					onclick() {
						window.wp?.media?.editor?.open( editor.id );
					},
				} );
				editor.on( 'NodeChange change', onEditorChange );
				editor.on( 'keyup paste cut', () => clearReviewFlag( step ) );
			}

			const cdnBase = 'https://cdn.jsdelivr.net/npm/tinymce@4.9.11/plugins';
			const externalPlugins = {
				table        : cdnBase + '/table/plugin.min.js',
				searchreplace: cdnBase + '/searchreplace/plugin.min.js',
				anchor       : cdnBase + '/anchor/plugin.min.js',
			};

			if ( window.wp?.editor ) {
				window.wp.editor.initialize( tid, {
					tinymce: {
						wpautop          : true,
						plugins          : 'charmap colorpicker fullscreen hr lists paste tabfocus textcolor wordpress wpautoresize wpeditimage wplink wptextpattern',
						external_plugins : externalPlugins,
						toolbar1         : 'bold italic underline strikethrough code_inline | formatselect | forecolor | bullist numlist | blockquote hr | alignleft aligncenter alignright | link unlink | fs_media | table | removeformat | undo redo | fullscreen',
						toolbar2         : 'charmap | anchor searchreplace | latex_inline latex_block',
						height           : 400,
						setup            : setupLatexButtons,
					},
					quicktags   : { buttons: 'strong,em,link,ul,ol,li,code,close' },
					mediaButtons: false,
				} );
			} else if ( window.tinymce ) {
				window.tinymce.init( {
					selector         : '#' + tid,
					external_plugins : externalPlugins,
					toolbar          : 'bold italic underline strikethrough code_inline | formatselect | bullist numlist | blockquote hr | alignleft aligncenter alignright | link | charmap | table | anchor searchreplace | removeformat | undo redo | fullscreen | latex_inline latex_block',
					menubar          : false,
					statusbar        : false,
					plugins          : 'link lists hr charmap fullscreen',
					height           : 400,
					skin_url         : window.tinymce?.baseURL + '/skins/lightgray',
					setup            : setupLatexButtons,
				} );
			} else {
				const area = ed.querySelector( '#' + tid );
				area.setAttribute( 'style', 'display:none' );
				const div = document.createElement( 'div' );
				div.className = 'rte-area';
				div.contentEditable = 'true';
				div.innerHTML = step.payload.content || '';
				div.addEventListener( 'input', () => { step.payload.content = div.innerHTML; clearReviewFlag( step ); scheduleSave(); } );
				ed.appendChild( div );
			}
		} else if ( 'video' === step.type ) {
			ed.innerHTML = `
				<div class="field-row"><label>Ссылка на видео</label><input class="field-input" data-url placeholder="https://…mp4 (нативный плеер) или YouTube/VK/Rutube (встраивание)"></div>
				<div class="field-row"><label>Описание под видео</label><textarea class="field-input" data-desc placeholder="Краткое описание…"></textarea></div>
				<div class="field-row"><label>Таймкоды с главами</label>
					<div class="fs-cb-chapters" data-chapters></div>
					<button type="button" class="button" data-chapter-add>+ Глава</button>
				</div>
				<div class="field-row"><label>Вложения-конспекты (скачивание под плеером)</label>
					<div class="fs-cb-attachments" data-attach-list></div>
					<button type="button" class="button" data-attach-add>+ Файл из медиабиблиотеки</button>
				</div>`;
			const url  = ed.querySelector( '[data-url]' );
			const desc = ed.querySelector( '[data-desc]' );
			url.value  = step.payload.url || '';
			desc.value = step.payload.description || '';
			url.addEventListener( 'input', () => { step.payload.url = url.value; clearReviewFlag( step ); scheduleSave(); } );
			desc.addEventListener( 'input', () => { step.payload.description = desc.value; clearReviewFlag( step ); scheduleSave(); } );

			renderChapterRows( ed.querySelector( '[data-chapters]' ), step );
			renderAttachmentRows( ed.querySelector( '[data-attach-list]' ), step );

			ed.querySelector( '[data-chapter-add]' ).addEventListener( 'click', () => {
				step.payload.chapters = step.payload.chapters || [];
				step.payload.chapters.push( { t: 0, title: '' } );
				renderChapterRows( ed.querySelector( '[data-chapters]' ), step );
				scheduleSave();
			} );

			ed.querySelector( '[data-attach-add]' ).addEventListener( 'click', () => {
				if ( ! window.wp?.media ) { return; }
				const frame = window.wp.media( { title: 'Вложения к видео', multiple: true } );
				frame.on( 'select', () => {
					const picked = frame.state().get( 'selection' ).toJSON().map( ( a ) => a.id );
					const ids    = ( step.payload.attachments || [] ).concat( picked );
					step.payload.attachments = ids.filter( ( v, i ) => ids.indexOf( v ) === i );
					renderAttachmentRows( ed.querySelector( '[data-attach-list]' ), step );
					scheduleSave();
				} );
				frame.open();
			} );
		}
	}

	// ── Видео-шаг: главы и вложения (D21, T14.12) ─────────────────────────

	function fmtChapterTime( sec ) {
		sec = Math.max( 0, parseInt( sec, 10 ) || 0 );
		return `${ Math.floor( sec / 60 ) }:${ String( sec % 60 ).padStart( 2, '0' ) }`;
	}

	function parseChapterTime( raw ) {
		const parts = String( raw ).trim().split( ':' ).map( ( p ) => parseInt( p, 10 ) || 0 );
		if ( 1 === parts.length ) { return parts[ 0 ]; }
		if ( 2 === parts.length ) { return parts[ 0 ] * 60 + parts[ 1 ]; }
		return parts[ 0 ] * 3600 + parts[ 1 ] * 60 + parts[ 2 ];
	}

	function renderChapterRows( box, step ) {
		const chapters = step.payload.chapters || [];
		box.innerHTML  = chapters.map( ( ch, i ) => `
			<div class="fs-cb-chapter-row">
				<input class="field-input fs-cb-ch-time" data-ch-time="${ i }" value="${ fmtChapterTime( ch.t ) }" placeholder="мм:сс">
				<input class="field-input fs-cb-ch-title" data-ch-title="${ i }" value="${ esc( ch.title || '' ) }" placeholder="Название главы">
				<button type="button" class="button fs-sb-btn-danger" data-ch-del="${ i }">✕</button>
			</div>` ).join( '' );

		box.querySelectorAll( '[data-ch-time]' ).forEach( ( input ) => {
			input.addEventListener( 'change', () => {
				chapters[ parseInt( input.dataset.chTime, 10 ) ].t = parseChapterTime( input.value );
				input.value = fmtChapterTime( chapters[ parseInt( input.dataset.chTime, 10 ) ].t );
				scheduleSave();
			} );
		} );
		box.querySelectorAll( '[data-ch-title]' ).forEach( ( input ) => {
			input.addEventListener( 'input', () => {
				chapters[ parseInt( input.dataset.chTitle, 10 ) ].title = input.value;
				scheduleSave();
			} );
		} );
		box.querySelectorAll( '[data-ch-del]' ).forEach( ( btn ) => {
			btn.addEventListener( 'click', () => {
				chapters.splice( parseInt( btn.dataset.chDel, 10 ), 1 );
				renderChapterRows( box, step );
				scheduleSave();
			} );
		} );
	}

	function renderAttachmentRows( box, step ) {
		const ids     = step.payload.attachments || [];
		box.innerHTML = ids.map( ( id, i ) => `
			<div class="fs-cb-attach-row">
				<span class="fs-cb-attach-title" data-att-title="${ id }">#${ id }</span>
				<button type="button" class="button fs-sb-btn-danger" data-att-del="${ i }">✕</button>
			</div>` ).join( '' );

		// Название файла — лениво из медиабиблиотеки (в payload храним только id).
		if ( window.wp?.media ) {
			ids.forEach( ( id ) => {
				const model = window.wp.media.attachment( id );
				model.fetch().then( () => {
					const el = box.querySelector( `[data-att-title="${ id }"]` );
					if ( el ) { el.textContent = model.get( 'title' ) || model.get( 'filename' ) || `#${ id }`; }
				} ).catch( () => {} );
			} );
		}

		box.querySelectorAll( '[data-att-del]' ).forEach( ( btn ) => {
			btn.addEventListener( 'click', () => {
				ids.splice( parseInt( btn.dataset.attDel, 10 ), 1 );
				renderAttachmentRows( box, step );
				scheduleSave();
			} );
		} );
	}

	function refEditor( ed, step ) {
		const meta     = stepMeta( step );
		const candKind = meta.candKind; // task | work | assessment
		const refId    = parseInt( step.payload.ref || 0, 10 );
		const isTask   = 'task' === candKind;
		const isWork   = 'work' === candKind;

		// ── Задача: отдельный UI ──────────────────────────────────────
		if ( isTask ) {
			if ( ! refId ) {
				ed.innerHTML =
					'<div class="fs-cb-task-pick">' +
					'<button type="button" class="button" data-pick>Выбрать существующую</button>' +
					'<button type="button" class="button button-primary" data-create>Добавить новую</button>' +
					'</div>';
			} else {
				const attVal  = parseInt( ( step.payload.settings || {} ).max_attempts ?? 0, 10 );
				const hintVal = parseInt( ( step.payload.settings || {} ).hint_after_errors ?? 0, 10 );
				ed.innerHTML =
					'<div class="fs-cb-ref">' +
					'<span class="fs-cb-ref-title">' + esc( step._title || step.title ) + '</span>' +
					'<a class="button" href="post.php?post=' + refId + '&action=edit" target="_blank" rel="noopener">Редактировать ↗</a>' +
					'<button type="button" class="button fs-sb-btn-danger" data-pick>' + icoReplace( 13 ) + ' Заменить</button>' +
					'</div>' +
					'<div class="fs-cb-task-preview" data-task-preview></div>' +
					'<div class="fs-cb-step-attempts">' +
					'<div class="fs-cb-ss-row">' +
					'<label class="fs-cb-ss-label">Попыток (0 = ∞)' +
					'<input type="number" min="0" class="fs-cb-ss-num" data-attempts value="' + attVal + '">' +
					'</label>' +
					'<label class="fs-cb-ss-label">Отображать подсказку после ошибок (0 = сразу)' +
					'<input type="number" min="0" class="fs-cb-ss-num" data-hint value="' + hintVal + '">' +
					'</label>' +
					'</div>' +
					'</div>';

				const attInput  = ed.querySelector( '[data-attempts]' );
				const hintInput = ed.querySelector( '[data-hint]' );
				// Число ошибок для показа подсказки всегда меньше числа попыток
				// (max_attempts = 0 = ∞ — ограничения нет).
				const clampHint = () => {
					const mx = parseInt( attInput.value, 10 ) || 0;
					let h    = parseInt( hintInput.value, 10 ) || 0;
					if ( h < 0 ) { h = 0; }
					if ( mx > 0 && h >= mx ) { h = mx - 1; }
					hintInput.value = h;
					return h;
				};
				attInput.addEventListener( 'change', () => {
					step.payload.settings                   = step.payload.settings || {};
					step.payload.settings.max_attempts      = parseInt( attInput.value, 10 ) || 0;
					step.payload.settings.hint_after_errors = clampHint();
					scheduleSave();
				} );
				hintInput.addEventListener( 'change', () => {
					step.payload.settings                   = step.payload.settings || {};
					step.payload.settings.hint_after_errors = clampHint();
					scheduleSave();
				} );

				loadTaskPreview( ed, refId );
			}

			const pickBtn = ed.querySelector( '[data-pick]' );
			if ( pickBtn ) {
				pickBtn.addEventListener( 'click', ( e ) => openLibraryPicker( e, candKind, ( id, title, source ) => {
					step.payload.ref    = id;
					step._title         = title;
					step.title          = title;
					step.payload.source = 'bank' === source ? 'bank' : 'subject';
					delete step.payload.needs_review;
					renderStepsRow(); renderStepBody(); saveSteps();
				} ) );
			}

			const createBtn = ed.querySelector( '[data-create]' );
			if ( createBtn ) {
				createBtn.addEventListener( 'click', () => {
					const adminBase = fs_lms_vars.ajaxurl.replace( 'admin-ajax.php', '' );
					const newWin    = window.open( adminBase + 'post-new.php?post_type=fs_lms_problems', '_blank' );
					let lastHref    = '';
					const poll = setInterval( () => {
						if ( newWin && ! newWin.closed ) {
							try { lastHref = newWin.location.href; } catch ( e ) { /* навигация — ждём */ }
						}
						const urlSearch = lastHref.includes( '?' ) ? lastHref.split( '?' )[ 1 ] : '';
						const params    = new URLSearchParams( urlSearch );
						const postId    = params.get( 'post' );
						if ( postId && params.get( 'action' ) === 'edit' ) {
							clearInterval( poll );
							ajax( acts().getTaskPreview, { task_id: postId } )
								.then( ( data ) => {
									step.payload.ref    = parseInt( postId, 10 );
									step.payload.source = 'bank';
									step._title         = data.title || ( 'Задача #' + postId );
									step.title          = data.title || ( 'Задача #' + postId );
									renderStepsRow(); renderStepBody(); saveSteps();
									showToast( 'Задача добавлена в шаг', 'success' );
								} )
								.catch( () => {
									step.payload.ref    = parseInt( postId, 10 );
									step.payload.source = 'bank';
									step._title         = 'Задача #' + postId;
									step.title          = 'Задача #' + postId;
									renderStepsRow(); renderStepBody(); saveSteps();
									showToast( 'Задача добавлена в шаг', 'success' );
								} );
							return;
						}
						if ( newWin && newWin.closed ) { clearInterval( poll ); }
					}, 800 );
				} );
			}
			return;
		}

		// ── Работа / Контрольная: 2-кнопочный UI (аналог Задачи) ────
		if ( ! refId ) {
			ed.innerHTML =
				'<div class="fs-cb-task-pick">' +
				'<button type="button" class="button" data-pick>Выбрать существующую</button>' +
				'<button type="button" class="button button-primary" data-create>Добавить новую</button>' +
				'</div>';
		} else {
			ed.innerHTML =
				'<div class="fs-cb-ref">' +
				'<span class="fs-cb-ref-title">' + esc( step._title || step.title ) + '</span>' +
				'<a class="button" href="post.php?post=' + refId + '&action=edit" target="_blank" rel="noopener">Редактировать ↗</a>' +
				'<button type="button" class="button fs-sb-btn-danger" data-pick>' + icoReplace( 13 ) + ' Заменить</button>' +
				'</div>' +
				'<div class="fs-cb-ref-tasks"></div>';
			loadRefPreview( ed.querySelector( '.fs-cb-ref-tasks' ), refId, isWork ? 'work' : 'assessment' );
		}

		const pickBtn = ed.querySelector( '[data-pick]' );
		if ( pickBtn ) {
			pickBtn.addEventListener( 'click', ( e ) => openLibraryPicker( e, candKind, ( id, title ) => {
				step.payload.ref = id; step._title = title; step.title = title; delete step.payload.needs_review;
				renderStepsRow(); renderStepBody(); saveSteps();
			} ) );
		}

		const createBtn = ed.querySelector( '[data-create]' );
		if ( createBtn ) {
			createBtn.addEventListener( 'click', () => {
				const adminBase = fs_lms_vars.ajaxurl.replace( 'admin-ajax.php', '' );
				const postType  = isWork
					? subjectKey + '_works'
					: subjectKey + '_assessments';
				const newWin = window.open( adminBase + 'post-new.php?post_type=' + encodeURIComponent( postType ), '_blank' );
				let lastHref = '';
				const poll = setInterval( () => {
					if ( newWin && ! newWin.closed ) {
						try { lastHref = newWin.location.href; } catch ( e ) { /* навигация — ждём */ }
					}
					const urlSearch = lastHref.includes( '?' ) ? lastHref.split( '?' )[ 1 ] : '';
					const params    = new URLSearchParams( urlSearch );
					const postId    = params.get( 'post' );
					if ( postId && params.get( 'action' ) === 'edit' ) {
						clearInterval( poll );
						step.payload.ref = parseInt( postId, 10 );
						step._title      = meta.name + ' #' + postId;
						step.title       = step._title;
						renderStepsRow(); renderStepBody(); saveSteps();
						showToast( meta.name + ' добавлена в шаг', 'success' );
						return;
					}
					if ( newWin && newWin.closed ) { clearInterval( poll ); }
				}, 800 );
			} );
		}
	}

	function renderStepSettings( body, step ) {
		const settings = step.payload.settings || {};
		const $ss      = document.createElement( 'div' );
		$ss.className  = 'fs-cb-step-settings';
		$ss.innerHTML  = `
			<h4 class="fs-cb-ss-title">Настройки шага</h4>
			<div class="fs-cb-ss-row">
				<label class="fs-cb-ss-label">Попыток (0 = ∞)
					<input type="number" min="0" class="field-input fs-cb-ss-num" data-ss="max_attempts"
						value="${ parseInt( settings.max_attempts ?? 0, 10 ) }">
				</label>
				<label class="fs-cb-ss-label">
					<input type="checkbox" data-ss="shuffle" ${ settings.shuffle ? 'checked' : '' }>
					Перемешать варианты
				</label>
			</div>`;

		body.appendChild( $ss );

		$ss.querySelectorAll( '[data-ss]' ).forEach( ( el ) => {
			el.addEventListener( 'change', () => {
				step.payload.settings = step.payload.settings || {};
				if ( 'checkbox' === el.type ) {
					step.payload.settings[ el.dataset.ss ] = el.checked;
				} else {
					step.payload.settings[ el.dataset.ss ] = parseInt( el.value, 10 ) || 0;
				}
				scheduleSave();
			} );
		} );
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

	function addStep( menuType ) {
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
			fetchFn:     ( search ) => ajax( acts().getStepCandidates, { subject_key: subjectKey, kind, source, search } ),
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
export function openPicker( anchor, { placeholder = 'Поиск…', emptyText = 'Ничего не найдено', fetchFn, onPick, placement = 'below' } ) {
	const pop = document.createElement( 'div' );
	pop.className = 'fs-cb-popover fs-cb-picker';
	pop.innerHTML = `<input type="text" class="field-input" data-search placeholder="${ esc( placeholder ) }"><div class="fs-cb-pick-results" data-results></div>`;
	document.body.appendChild( pop );
	const r = anchor.getBoundingClientRect();
	if ( 'above' === placement ) {
		pop.style.top       = `${ window.scrollY + r.top }px`;
		pop.style.transform = 'translateY(calc(-100% - 6px))';
	} else {
		pop.style.top = `${ window.scrollY + r.bottom + 6 }px`;
	}
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
				const titleSpan = document.createElement( 'span' );
				titleSpan.className = 'fs-cb-pick-title';
				titleSpan.textContent = it.title;
				opt.appendChild( titleSpan );
				if ( it.source ) {
					const badge = document.createElement( 'span' );
					badge.className = 'fs-cb-pick-origin';
					badge.textContent = 'bank' === it.source ? 'Банк' : 'Предмет';
					opt.appendChild( badge );
				}
				opt.addEventListener( 'click', () => { onPick( parseInt( it.id, 10 ), it.title, it.source || '' ); pop.remove(); } );
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