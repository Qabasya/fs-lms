import '../_types.js';
import { showToast } from '../modules/toast.js';

/* global jQuery, fs_lms_vars */
const $ = jQuery;

/**
 * CourseBuilder — Stepik-style конструктор курса (канон: design_handoff_course_builder/).
 * Дерево «модули → уроки» слева + редактор шагов выбранного урока справа.
 * Монтируется на #fs-lms-course-builder. Сохранение — AJAX (см. CourseBuilderCallbacks
 * + reuse SaveLessonSteps). Контент шага хранится в нашей модели LessonDTO.steps[].
 *
 * Маппинг типов шага (дизайн → наш StepType): Лекция→text, Видео→video, Файл→material,
 * Практика→work (ссылка), Тест→assessment (ссылка).
 */

// ── SVG-иконки типов (канон, keyed by UI-тип) ──────────────────
const ICON = {
	lecture:  '<svg viewBox="0 0 24 24" width="22" height="22"><path d="M6 3h9l5 5v13a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1zm8 1.5V8h3.5L14 4.5zM8 12h8v1.6H8V12zm0 3.4h8V17H8v-1.6zM8 8.6h4v1.6H8V8.6z"/></svg>',
	video:    '<svg viewBox="0 0 24 24" width="22" height="22"><path d="M4 5h16a1 1 0 0 1 1 1v12a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1zm6 3.2v7.6l6-3.8-6-3.8z"/></svg>',
	practice: '<svg viewBox="0 0 24 24" width="22" height="22"><path d="M9.4 16.6 4.8 12l4.6-4.6L8 6l-6 6 6 6 1.4-1.4zm5.2 0L19.2 12l-4.6-4.6L16 6l6 6-6 6-1.4-1.4z"/></svg>',
	quiz:     '<svg viewBox="0 0 24 24" width="22" height="22"><path d="M4 5h7v2H4V5zm0 6h7v2H4v-2zm0 6h7v2H4v-2zm14.3-9.3 1.4 1.4-5 5-3-3 1.4-1.4 1.6 1.6 3.6-3.6zm0 6 1.4 1.4-5 5-3-3 1.4-1.4 1.6 1.6 3.6-3.6z"/></svg>',
	file:     '<svg viewBox="0 0 24 24" width="22" height="22"><path d="M16.5 6.5 9.6 13.4a2 2 0 1 0 2.8 2.8l6.9-6.9a4 4 0 1 0-5.6-5.6L6.7 10.6a6 6 0 0 0 8.5 8.5L21 13.3l-1.4-1.4-5.8 5.8a4 4 0 0 1-5.7-5.7l7-7a2 2 0 0 1 2.8 2.8l-6.9 6.9-.7-.7 6.9-6.9-1.6-1.6z"/></svg>',
};

/** Наш StepType → UI-метаданные. */
const TYPE_UI = {
	text:       { ui: 'lecture',  name: 'Лекция',     inline: true },
	video:      { ui: 'video',    name: 'Видео',      inline: true },
	material:   { ui: 'file',     name: 'Файл',       inline: true },
	work:       { ui: 'practice', name: 'Практика',   inline: false, candKind: 'work' },
	assessment: { ui: 'quiz',     name: 'Тест',       inline: false, candKind: 'assessment' },
	task:       { ui: 'practice', name: 'Задача',     inline: false, candKind: 'task' },
};

/** Опции поповера «Добавить шаг» (порядок как в дизайне). */
const ADD_TYPES = [
	{ type: 'text',       desc: 'Текст, формулы, картинки' },
	{ type: 'video',      desc: 'YouTube, Vimeo, файл' },
	{ type: 'work',       desc: 'Задача с решением (из библиотеки)' },
	{ type: 'assessment', desc: 'Тест/контрольная (из библиотеки)' },
	{ type: 'material',   desc: 'Материалы для скачивания' },
];

const uiMeta = ( ourType ) => TYPE_UI[ ourType ] || TYPE_UI.text;
const icon   = ( ourType ) => ICON[ uiMeta( ourType ).ui ] || ICON.lecture;
const acts   = () => fs_lms_vars.ajax_actions;

let _idc = 1000;
const tmpKey = ( p ) => `${ p }_tmp_${ Date.now() }_${ ++_idc }`;
const esc = ( s ) => String( s == null ? '' : s )
	.replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' ).replace( /"/g, '&quot;' );

export const CourseBuilder = {
	init() {
		const mount = document.getElementById( 'fs-lms-course-builder' );
		if ( mount ) {
			createApp( mount );
		}
	},
};

// ── AJAX ───────────────────────────────────────────────────────
function nonceFor( action ) {
	const a = acts();
	const lessonScoped = [ a.saveLessonSteps, a.moveLessonStep, a.getStepCandidates ];
	return lessonScoped.includes( action )
		? fs_lms_vars.nonces.authorLesson
		: fs_lms_vars.nonces.authorCourse;
}

function ajax( action, data ) {
	return new Promise( ( resolve, reject ) => {
		$.post( fs_lms_vars.ajaxurl, Object.assign( { action, security: nonceFor( action ) }, data ) )
			.done( ( resp ) => ( resp && resp.success ) ? resolve( resp.data ) : reject( ( resp && resp.data ) || 'Ошибка' ) )
			.fail( () => reject( 'Ошибка сети' ) );
	} );
}

// ══════════════════════════════════════════════════════════════
function createApp( mount ) {
	const courseId = parseInt( mount.dataset.courseId, 10 ) || 0;
	const subject  = String( mount.dataset.subject || '' );

	const state = { course: null, activeLessonId: null, activeStepId: null };
	let saveTimer = null;
	let popoverModuleId = null;

	if ( courseId > 0 ) {
		mount.innerHTML = '<p class="fs-cb-loading">Загрузка курса…</p>';
		ajax( acts().getCourseBuilder, { course_id: courseId } )
			.then( ( tree ) => { state.course = tree; bootstrap(); } )
			.catch( ( msg ) => { mount.innerHTML = `<div class="notice notice-error"><p>${ esc( msg ) }</p></div>`; } );
	} else if ( subject ) {
		renderCreateCourse();
	} else {
		mount.innerHTML = '<div class="notice notice-warning"><p>Откройте конструктор из библиотеки «Курсы» или укажите предмет.</p></div>';
	}

	// ── создание курса ──
	function renderCreateCourse() {
		mount.innerHTML = `
			<div class="fs-cb-create">
				<h2>Новый курс</h2>
				<input type="text" class="fs-cb-create-title" placeholder="Название курса">
				<button type="button" class="button button-primary fs-cb-create-btn">Создать курс</button>
			</div>`;
		mount.querySelector( '.fs-cb-create-btn' ).addEventListener( 'click', () => {
			const title = mount.querySelector( '.fs-cb-create-title' ).value.trim();
			ajax( acts().createCourseDraft, { subject_key: subject, title } )
				.then( ( data ) => {
					const url = new URL( window.location.href );
					url.searchParams.set( 'course', data.id );
					url.searchParams.delete( 'subject' );
					window.location.href = url.toString();
				} )
				.catch( ( msg ) => showToast( msg, 'error' ) );
		} );
	}

	// ── каркас приложения ──
	function bootstrap() {
		const first = firstLesson();
		state.activeLessonId = first ? first.id : null;
		state.activeStepId   = first && first.steps[ 0 ] ? first.steps[ 0 ].key : null;
		renderShell();
		renderTree();
		renderEditor();
	}

	function renderShell() {
		const c = state.course;
		mount.innerHTML = `
			<div class="course-strip">
				<div class="course-thumb" data-thumb>${ esc( ( c.title || '?' ).slice( 0, 2 ) ) }</div>
				<div class="course-strip-main">
					<input class="cs-title-input" value="${ esc( c.title ) }" placeholder="Название курса">
					<div class="cs-meta">
						<span><b data-module-count>${ c.modules.length }</b> модулей</span>
						<span><b data-lesson-count>${ totalLessons() }</b> уроков</span>
						<button type="button" class="course-flag${ 'publish' === c.status ? ' published' : '' }" data-toggle-course>
							${ 'publish' === c.status ? 'Опубликован' : 'Черновик' }
						</button>
					</div>
				</div>
			</div>
			<div class="postbox fs-cb-postbox">
				<div class="postbox-header"><h2>Конструктор курса</h2></div>
				<div class="builder">
					<div class="tree-pane">
						<div class="tree-head">
							<span class="th-title">Структура курса</span>
							<span class="th-count" data-tree-count></span>
						</div>
						<div class="tree-scroll" data-tree></div>
						<div class="tree-add">
							<button type="button" class="button" data-add-lesson>+ Урок</button>
							<button type="button" class="button" data-add-module>+ Модуль</button>
						</div>
					</div>
					<div class="editor-pane" data-editor></div>
				</div>
			</div>`;
		mount.querySelector( '[data-add-lesson]' ).addEventListener( 'click', addLesson );
		mount.querySelector( '[data-add-module]' ).addEventListener( 'click', addModule );

		const titleInput = mount.querySelector( '.cs-title-input' );
		const thumb      = mount.querySelector( '[data-thumb]' );
		titleInput.addEventListener( 'input', () => {
			c.title = titleInput.value;
			thumb.textContent = ( c.title || '?' ).slice( 0, 2 );
			scheduleCourseMeta();
		} );
		mount.querySelector( '[data-toggle-course]' ).addEventListener( 'click', function() {
			c.status = 'publish' === c.status ? 'draft' : 'publish';
			const pub = 'publish' === c.status;
			this.textContent = pub ? 'Опубликован' : 'Черновик';
			this.classList.toggle( 'published', pub );
			saveCourseMeta();
		} );
	}

	// ── helpers ──
	function totalLessons() {
		return state.course.modules.reduce( ( n, m ) => n + m.lessons.length, 0 );
	}
	function firstLesson() {
		for ( const m of state.course.modules ) {
			if ( m.lessons.length ) { return m.lessons[ 0 ]; }
		}
		return null;
	}
	function findLesson( id ) {
		for ( const m of state.course.modules ) {
			const l = m.lessons.find( ( x ) => x.id === id );
			if ( l ) { return { lesson: l, module: m }; }
		}
		return null;
	}

	// ══════════ TREE ══════════
	function renderTree() {
		const root = mount.querySelector( '[data-tree]' );
		root.innerHTML = '';

		state.course.modules.forEach( ( mod, mi ) => {
			if ( typeof mod.collapsed === 'undefined' ) { mod.collapsed = false; }
			const modEl = document.createElement( 'div' );
			modEl.className = 'module' + ( mod.collapsed ? ' collapsed' : '' );

			const head = document.createElement( 'div' );
			head.className = 'module-head';
			head.innerHTML = `
				<span class="mod-grip"><svg width="12" height="12" viewBox="0 0 12 12"><path fill="currentColor" d="M4 2.5h1v1H4zm3 0h1v1H7zM4 5.5h1v1H4zm3 0h1v1H7zM4 8.5h1v1H4zm3 0h1v1H7z"/></svg></span>
				<span class="mod-caret"><svg width="12" height="12" viewBox="0 0 12 12"><path fill="currentColor" d="M3 4.5 6 8l3-3.5z"/></svg></span>
				<span class="mod-num">${ mi + 1 }</span>
				<span class="mod-title"></span>`;
			head.querySelector( '.mod-title' ).textContent = mod.title;
			head.addEventListener( 'click', () => { mod.collapsed = ! mod.collapsed; renderTree(); } );
			modEl.appendChild( head );
			modEl.draggable = true;
			attachModuleDrag( modEl, mod );

			const wrap = document.createElement( 'div' );
			wrap.className = 'module-lessons';
			mod.lessons.forEach( ( les ) => {
				const el = document.createElement( 'div' );
				el.className = 'lesson' + ( les.id === state.activeLessonId ? ' active' : '' );
				el.draggable = true;
				el.innerHTML = `
					<span class="les-grip"><svg width="12" height="12" viewBox="0 0 12 12"><path fill="currentColor" d="M4 2.5h1v1H4zm3 0h1v1H7zM4 5.5h1v1H4zm3 0h1v1H7zM4 8.5h1v1H4zm3 0h1v1H7z"/></svg></span>
					<span class="les-num">${ mi + 1 }.${ mod.lessons.indexOf( les ) + 1 }</span>
					<span class="les-title"></span>
					<span class="les-steps">${ les.steps.length }</span>`;
				el.querySelector( '.les-title' ).textContent = les.title;
				el.addEventListener( 'click', () => selectLesson( les.id ) );
				attachLessonDrag( el, les, mod );
				wrap.appendChild( el );
			} );
			modEl.appendChild( wrap );
			root.appendChild( modEl );
		} );

		mount.querySelector( '[data-tree-count]' ).textContent =
			`${ state.course.modules.length } модулей · ${ totalLessons() } уроков`;
	}

	// ── drag&drop уроков ──
	let dragLessonId = null;
	let dragModuleId = null;
	function attachLessonDrag( el, les, mod ) {
		el.addEventListener( 'dragstart', ( e ) => {
			dragLessonId = les.id; dragModuleId = null;
			el.classList.add( 'dragging' );
			e.dataTransfer.effectAllowed = 'move';
			e.stopPropagation();
		} );
		el.addEventListener( 'dragend', () => {
			dragLessonId = null; el.classList.remove( 'dragging' );
			mount.querySelectorAll( '.drop-before,.drop-after' ).forEach( ( n ) => n.classList.remove( 'drop-before', 'drop-after' ) );
		} );
		el.addEventListener( 'dragover', ( e ) => {
			if ( dragModuleId || dragLessonId === les.id ) { return; }
			e.preventDefault(); e.stopPropagation();
			const r = el.getBoundingClientRect();
			const after = ( e.clientY - r.top ) > r.height / 2;
			el.classList.toggle( 'drop-after', after );
			el.classList.toggle( 'drop-before', ! after );
		} );
		el.addEventListener( 'dragleave', () => el.classList.remove( 'drop-before', 'drop-after' ) );
		el.addEventListener( 'drop', ( e ) => {
			if ( dragModuleId ) { return; }
			e.preventDefault(); e.stopPropagation();
			const r = el.getBoundingClientRect();
			const after = ( e.clientY - r.top ) > r.height / 2;
			el.classList.remove( 'drop-before', 'drop-after' );
			moveLesson( dragLessonId, mod.id, les.id, after );
		} );
	}

	function attachModuleDrag( el, mod ) {
		el.addEventListener( 'dragstart', ( e ) => {
			dragModuleId = mod.id; dragLessonId = null;
			el.classList.add( 'dragging' );
			e.dataTransfer.effectAllowed = 'move';
			e.stopPropagation();
		} );
		el.addEventListener( 'dragend', () => {
			dragModuleId = null; el.classList.remove( 'dragging' );
			mount.querySelectorAll( '.drop-before,.drop-after' ).forEach( ( n ) => n.classList.remove( 'drop-before', 'drop-after' ) );
		} );
		el.addEventListener( 'dragover', ( e ) => {
			if ( ! dragModuleId || dragModuleId === mod.id ) { return; }
			e.preventDefault(); e.stopPropagation();
			const r = el.getBoundingClientRect();
			const after = ( e.clientY - r.top ) > r.height / 2;
			el.classList.toggle( 'drop-after', after );
			el.classList.toggle( 'drop-before', ! after );
		} );
		el.addEventListener( 'dragleave', ( ev ) => {
			if ( ! el.contains( ev.relatedTarget ) ) {
				el.classList.remove( 'drop-before', 'drop-after' );
			}
		} );
		el.addEventListener( 'drop', ( e ) => {
			if ( ! dragModuleId ) { return; }
			e.preventDefault(); e.stopPropagation();
			const r = el.getBoundingClientRect();
			const after = ( e.clientY - r.top ) > r.height / 2;
			el.classList.remove( 'drop-before', 'drop-after' );
			moveModule( dragModuleId, mod.id, after );
		} );
	}

	function moveModule( fromId, toId, after ) {
		if ( fromId === toId ) { return; }
		const fromIdx = state.course.modules.findIndex( ( m ) => m.id === fromId );
		if ( fromIdx < 0 ) { return; }
		const [ moved ] = state.course.modules.splice( fromIdx, 1 );
		const toIdx = state.course.modules.findIndex( ( m ) => m.id === toId );
		state.course.modules.splice( toIdx >= 0 && after ? toIdx + 1 : Math.max( 0, toIdx ), 0, moved );
		renderTree();
		saveStructure( 'Модуль перемещён' );
	}

	function moveLesson( lessonId, targetModuleId, targetLessonId, after ) {
		if ( ! lessonId || lessonId === targetLessonId ) { return; }
		let moved = null;
		for ( const m of state.course.modules ) {
			const i = m.lessons.findIndex( ( l ) => l.id === lessonId );
			if ( i > -1 ) { moved = m.lessons.splice( i, 1 )[ 0 ]; break; }
		}
		if ( ! moved ) { return; }
		const tm = state.course.modules.find( ( m ) => m.id === targetModuleId );
		let ti = tm.lessons.findIndex( ( l ) => l.id === targetLessonId );
		if ( ti < 0 ) { ti = tm.lessons.length - 1; }
		tm.lessons.splice( after ? ti + 1 : ti, 0, moved );
		renderTree();
		saveStructure( 'Урок перемещён' );
	}

	// ══════════ EDITOR ══════════
	function selectLesson( id ) {
		state.activeLessonId = id;
		const f = findLesson( id );
		state.activeStepId = f && f.lesson.steps[ 0 ] ? f.lesson.steps[ 0 ].key : null;
		renderTree();
		renderEditor();
	}
	function selectStep( key ) { state.activeStepId = key; renderEditor(); }

	function renderEditor() {
		const pane = mount.querySelector( '[data-editor]' );
		const f = findLesson( state.activeLessonId );
		if ( ! f ) {
			pane.innerHTML = '<div class="editor-empty">Выберите урок слева, чтобы редактировать его шаги</div>';
			return;
		}
		const { lesson, module } = f;
		const mi = state.course.modules.indexOf( module ) + 1;
		const li = module.lessons.indexOf( lesson ) + 1;
		const step = lesson.steps.find( ( s ) => s.key === state.activeStepId ) || lesson.steps[ 0 ];

		pane.innerHTML = `
			<div class="editor-top">
				<div class="editor-breadcrumb">
					<span>${ esc( state.course.title ) }</span><span>›</span>
					<span>Модуль ${ mi }: ${ esc( module.title ) }</span><span>›</span>
					<b>Урок ${ mi }.${ li }</b>
				</div>
				<div class="lesson-title-row">
					<input class="lesson-title-input" data-lesson-title value="${ esc( lesson.title ) }" placeholder="Название урока">
					<button type="button" class="lesson-flag ${ lesson.published ? 'published' : '' }" data-toggle-publish>
						${ lesson.published ? 'Опубликован' : 'Черновик' }
					</button>
				</div>
				<div class="steps-label">Шаги урока</div>
				<div class="steps-row" data-steps></div>
			</div>
			<div class="editor-body" data-body></div>
			<div class="editor-footer">
				<span class="ef-status" data-status><span class="saved-dot"></span> Все изменения сохранены</span>
				<span class="ef-spacer"></span>
				<button type="button" class="button button-green" data-save-lesson>Сохранить</button>
			</div>`;

		const titleInput = pane.querySelector( '[data-lesson-title]' );
		titleInput.addEventListener( 'input', () => {
			lesson.title = titleInput.value;
			mount.querySelectorAll( '.lesson.active .les-title' ).forEach( ( n ) => { n.textContent = lesson.title; } );
			scheduleLessonMeta( lesson );
		} );
		pane.querySelector( '[data-toggle-publish]' ).addEventListener( 'click', () => togglePublish( lesson ) );
		pane.querySelector( '[data-save-lesson]' ).addEventListener( 'click', () => saveLessonSteps( lesson, true ) );

		renderStepsRow( lesson, step );
		renderStepBody( lesson, step );
	}

	function renderStepsRow( lesson, activeStep ) {
		const row = mount.querySelector( '[data-steps]' );
		row.innerHTML = '';
		lesson.steps.forEach( ( s, i ) => {
			const chip = document.createElement( 'div' );
			chip.className = 'step-chip' + ( activeStep && s.key === activeStep.key ? ' active' : '' );
			chip.dataset.type = uiMeta( s.type ).ui;
			chip.draggable = true;
			chip.innerHTML = `
				<div class="step-chip-box"><span class="sc-num">${ i + 1 }</span>${ icon( s.type ) }</div>
				<span class="sc-type">${ esc( uiMeta( s.type ).name ) }</span>`;
			chip.addEventListener( 'click', () => selectStep( s.key ) );
			attachStepDrag( chip, lesson, s );
			row.appendChild( chip );
		} );

		const add = document.createElement( 'div' );
		add.className = 'step-chip step-add';
		add.innerHTML = '<div class="step-chip-box"><svg width="22" height="22" viewBox="0 0 24 24"><path fill="currentColor" d="M11 5h2v6h6v2h-6v6h-2v-6H5v-2h6z"/></svg></div><span class="sc-type">Добавить</span>';
		add.addEventListener( 'click', ( e ) => openPopover( e, lesson ) );
		row.appendChild( add );
	}

	// ── drag шагов ──
	let dragStepKey = null;
	function attachStepDrag( chip, lesson, step ) {
		chip.addEventListener( 'dragstart', ( e ) => { dragStepKey = step.key; chip.classList.add( 'dragging' ); e.dataTransfer.effectAllowed = 'move'; } );
		chip.addEventListener( 'dragend', () => { dragStepKey = null; chip.classList.remove( 'dragging' ); } );
		chip.addEventListener( 'dragover', ( e ) => e.preventDefault() );
		chip.addEventListener( 'drop', ( e ) => {
			e.preventDefault();
			if ( ! dragStepKey || dragStepKey === step.key ) { return; }
			const from = lesson.steps.findIndex( ( s ) => s.key === dragStepKey );
			const to   = lesson.steps.findIndex( ( s ) => s.key === step.key );
			const [ m ] = lesson.steps.splice( from, 1 );
			lesson.steps.splice( to, 0, m );
			renderStepsRow( lesson, lesson.steps.find( ( s ) => s.key === state.activeStepId ) );
			saveLessonSteps( lesson );
			showToast( 'Шаг перемещён', 'success' );
		} );
	}

	// ══════════ STEP BODY ══════════
	function renderStepBody( lesson, step ) {
		const body = mount.querySelector( '[data-body]' );
		if ( ! step ) {
			body.innerHTML = '<div class="editor-empty">В этом уроке пока нет шагов. Нажмите «Добавить».</div>';
			return;
		}
		const meta  = uiMeta( step.type );
		const index = lesson.steps.indexOf( step ) + 1;

		body.innerHTML = `
			<div class="step-head" data-type="${ meta.ui }">
				<span class="sh-badge">${ icon( step.type ) } Шаг ${ index }: ${ esc( meta.name ) }</span>
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
				renderStepsRow( lesson, step );
				scheduleLessonSteps( lesson );
			} );
		}
		body.querySelector( '[data-dup]' ).addEventListener( 'click', () => dupStep( lesson, step ) );
		body.querySelector( '[data-del]' ).addEventListener( 'click', () => delStep( lesson, step ) );

		const ed = body.querySelector( '[data-step-editor]' );
		if ( meta.inline ) {
			inlineEditor( ed, lesson, step );
		} else {
			refEditor( ed, lesson, step );
		}
	}

	function inlineEditor( ed, lesson, step ) {
		if ( 'text' === step.type ) {
			ed.innerHTML = '<div class="sb-label">Содержание лекции</div><div class="rte-area" contenteditable="true" data-content></div>';
			const area = ed.querySelector( '[data-content]' );
			area.innerHTML = step.payload.content || '';
			area.addEventListener( 'input', () => { step.payload.content = area.innerHTML; scheduleLessonSteps( lesson ); } );
		} else if ( 'video' === step.type ) {
			ed.innerHTML = `
				<div class="field-row"><label>Ссылка на видео</label><input class="field-input" data-url placeholder="https://youtube.com/watch?v=…"></div>
				<div class="field-row"><label>Описание под видео</label><textarea class="field-input" data-desc placeholder="Краткое описание…"></textarea></div>`;
			const url  = ed.querySelector( '[data-url]' );
			const desc = ed.querySelector( '[data-desc]' );
			url.value  = step.payload.url || '';
			desc.value = step.payload.description || '';
			url.addEventListener( 'input', () => { step.payload.url = url.value; scheduleLessonSteps( lesson ); } );
			desc.addEventListener( 'input', () => { step.payload.description = desc.value; scheduleLessonSteps( lesson ); } );
		} else { // material
			const refId = parseInt( step.payload.article_id || 0, 10 );
			ed.innerHTML = `
				<div class="sb-label">Материал (статья предмета)</div>
				<div class="fs-cb-ref"><span class="fs-cb-ref-title">${ refId ? `Статья #${ refId }` : 'не выбрано' }</span>
				<button type="button" class="button" data-pick>Выбрать материал</button></div>`;
			ed.querySelector( '[data-pick]' ).addEventListener( 'click', ( e ) => openLibraryPicker( e, 'article', ( id, title ) => {
				step.payload.article_id = id; step.payload.title = step.payload.title || title;
				renderStepsRow( lesson, step ); renderStepBody( lesson, step ); saveLessonSteps( lesson );
			} ) );
		}
	}

	function refEditor( ed, lesson, step ) {
		const meta  = uiMeta( step.type );
		const refId = parseInt( step.payload.ref || 0, 10 );
		ed.innerHTML = `
			<div class="sb-label">${ esc( meta.name ) } из библиотеки</div>
			<div class="fs-cb-ref">
				<span class="fs-cb-ref-title">${ refId ? esc( step.title ) : 'не выбрано' }</span>
				${ refId ? `<a class="fs-cb-ref-edit" href="post.php?post=${ refId }&action=edit" target="_blank" rel="noopener">редактировать ↗</a>` : '' }
				<button type="button" class="button" data-pick>${ refId ? 'Заменить' : 'Выбрать из библиотеки' }</button>
			</div>
			<p class="fs-cb-hint">Инлайн-создание/редактирование появится в следующей фазе.</p>`;
		ed.querySelector( '[data-pick]' ).addEventListener( 'click', ( e ) => openLibraryPicker( e, meta.candKind, ( id, title ) => {
			step.payload.ref = id; step.title = title;
			renderStepsRow( lesson, step ); renderStepBody( lesson, step ); saveLessonSteps( lesson );
		} ) );
	}

	// ══════════ STEP actions ══════════
	function dupStep( lesson, step ) {
		const i = lesson.steps.indexOf( step );
		const copy = { key: tmpKey( 's' ), type: step.type, title: step.title, payload: Object.assign( {}, step.payload ) };
		if ( copy.payload.title ) { copy.payload.title += ' (копия)'; }
		lesson.steps.splice( i + 1, 0, copy );
		state.activeStepId = copy.key;
		renderTree(); renderEditor();
		saveLessonSteps( lesson );
		showToast( 'Шаг дублирован', 'success' );
	}
	function delStep( lesson, step ) {
		if ( lesson.steps.length <= 1 ) { showToast( 'Нельзя удалить единственный шаг', 'error' ); return; }
		const i = lesson.steps.indexOf( step );
		lesson.steps.splice( i, 1 );
		state.activeStepId = lesson.steps[ Math.max( 0, i - 1 ) ].key;
		renderTree(); renderEditor();
		saveLessonSteps( lesson );
		showToast( 'Шаг удалён', 'success' );
	}

	function addStep( lesson, ourType ) {
		const step = { key: tmpKey( 's' ), type: ourType, title: uiMeta( ourType ).name, payload: uiMeta( ourType ).inline ? { title: '' } : { ref: 0 } };
		lesson.steps.push( step );
		state.activeStepId = step.key;
		renderTree(); renderEditor();
		saveLessonSteps( lesson );
		showToast( uiMeta( ourType ).name + ' добавлен', 'success' );
	}

	// ══════════ ADD lesson / module ══════════
	function addLesson() {
		const f = findLesson( state.activeLessonId );
		const mod = f ? f.module : state.course.modules[ 0 ];
		if ( ! mod ) { showToast( 'Сначала добавьте модуль', 'error' ); return; }
		ajax( acts().createLessonInModule, { course_id: courseId, module_id: mod.id, title: 'Новый урок' } )
			.then( ( node ) => {
				mod.lessons.push( node );
				mod.collapsed = false;
				selectLesson( node.id );
				showToast( 'Урок добавлен', 'success' );
			} )
			.catch( ( msg ) => showToast( msg, 'error' ) );
	}
	function addModule() {
		state.course.modules.push( { id: tmpKey( 'm' ), title: 'Новый модуль', collapsed: false, lessons: [] } );
		renderTree();
		saveStructure( 'Модуль добавлен' );
	}

	// ══════════ POPOVER ══════════
	function openPopover( e, lesson ) {
		e.stopPropagation();
		closePopover();
		popoverModuleId = lesson.id;
		const pop = document.createElement( 'div' );
		pop.className = 'fs-cb-popover';
		pop.innerHTML = '<div class="sp-title">Добавить шаг</div>' + ADD_TYPES.map( ( o ) => `
			<div class="sp-option" data-type="${ o.type }">
				<span class="spo-ico" data-type="${ uiMeta( o.type ).ui }">${ icon( o.type ) }</span>
				<div><div class="spo-name">${ esc( uiMeta( o.type ).name ) }</div><div class="spo-desc">${ esc( o.desc ) }</div></div>
			</div>` ).join( '' );
		document.body.appendChild( pop );
		const r = e.currentTarget.getBoundingClientRect();
		pop.style.top  = `${ window.scrollY + r.bottom + 6 }px`;
		pop.style.left = `${ Math.min( r.left, window.innerWidth - 260 ) }px`;
		pop.querySelectorAll( '.sp-option' ).forEach( ( opt ) => opt.addEventListener( 'click', () => {
			const f = findLesson( popoverModuleId );
			if ( f ) { addStep( f.lesson, opt.dataset.type ); }
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
		const pop = document.createElement( 'div' );
		pop.className = 'fs-cb-popover fs-cb-picker';
		pop.innerHTML = '<input type="text" class="field-input" data-search placeholder="Поиск в библиотеке…"><div class="fs-cb-pick-results" data-results></div>';
		document.body.appendChild( pop );
		const r = e.currentTarget.getBoundingClientRect();
		pop.style.top  = `${ window.scrollY + r.bottom + 6 }px`;
		pop.style.left = `${ Math.min( r.left, window.innerWidth - 320 ) }px`;
		const results = pop.querySelector( '[data-results]' );
		const search  = pop.querySelector( '[data-search]' );
		let t = null;
		const run = () => ajax( acts().getStepCandidates, { subject_key: state.course.subject_key, kind, source: 'subject', search: search.value.trim() } )
			.then( ( items ) => {
				results.innerHTML = '';
				if ( ! items.length ) { results.innerHTML = '<div class="fs-cb-pick-empty">Ничего не найдено</div>'; return; }
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

	// ══════════ PERSISTENCE ══════════
	function setStatus( text ) {
		const s = mount.querySelector( '[data-status]' );
		if ( s ) { s.innerHTML = `<span class="saved-dot"></span> ${ esc( text ) }`; }
	}

	function payloadForSave( lesson ) {
		return lesson.steps.map( ( s ) => ( { key: s.key, type: s.type, payload: s.payload } ) );
	}

	function saveLessonSteps( lesson, manual ) {
		setStatus( 'Сохранение…' );
		ajax( acts().saveLessonSteps, { lesson_id: lesson.id, subject_key: state.course.subject_key, steps: payloadForSave( lesson ) } )
			.then( () => { setStatus( 'Все изменения сохранены' ); if ( manual ) { showToast( 'Урок сохранён', 'success' ); } } )
			.catch( ( msg ) => { setStatus( 'Ошибка сохранения' ); showToast( msg, 'error' ); } );
	}
	function scheduleLessonSteps( lesson ) {
		setStatus( 'Изменения…' );
		clearTimeout( saveTimer );
		saveTimer = setTimeout( () => saveLessonSteps( lesson ), 800 );
	}

	function scheduleLessonMeta( lesson ) {
		setStatus( 'Изменения…' );
		clearTimeout( saveTimer );
		saveTimer = setTimeout( () => {
			ajax( acts().updateLessonMeta, { lesson_id: lesson.id, title: lesson.title, published: lesson.published ? '1' : '' } )
				.then( () => setStatus( 'Все изменения сохранены' ) )
				.catch( ( msg ) => { setStatus( 'Ошибка сохранения' ); showToast( msg, 'error' ); } );
		}, 800 );
	}
	function togglePublish( lesson ) {
		lesson.published = ! lesson.published;
		renderEditor();
		ajax( acts().updateLessonMeta, { lesson_id: lesson.id, title: lesson.title, published: lesson.published ? '1' : '' } )
			.then( () => showToast( lesson.published ? 'Урок опубликован' : 'Урок снят с публикации', 'success' ) )
			.catch( ( msg ) => showToast( msg, 'error' ) );
	}

	function structurePayload() {
		return state.course.modules.map( ( m ) => ( { id: m.id, title: m.title, lesson_ids: m.lessons.map( ( l ) => l.id ) } ) );
	}
	function saveStructure( okMsg ) {
		ajax( acts().saveCourseStructure, { course_id: courseId, modules: structurePayload() } )
			.then( () => {
				if ( okMsg ) { showToast( okMsg, 'success' ); }
				const mc = mount.querySelector( '[data-module-count]' );
				const lc = mount.querySelector( '[data-lesson-count]' );
				if ( mc ) { mc.textContent = state.course.modules.length; }
				if ( lc ) { lc.textContent = totalLessons(); }
			} )
			.catch( ( msg ) => showToast( msg, 'error' ) );
	}

	function saveCourseMeta() {
		const pub = 'publish' === state.course.status;
		ajax( acts().saveCourseMeta, { course_id: courseId, title: state.course.title, published: pub ? '1' : '' } )
			.then( () => setStatus( 'Все изменения сохранены' ) )
			.catch( ( msg ) => { setStatus( 'Ошибка сохранения' ); showToast( msg, 'error' ); } );
	}
	function scheduleCourseMeta() {
		setStatus( 'Изменения…' );
		clearTimeout( saveTimer );
		saveTimer = setTimeout( saveCourseMeta, 800 );
	}
}
