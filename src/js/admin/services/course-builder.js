import '../_types.js';
import { createStepEditor, esc, ajax, tmpKey, openPicker } from './step-editor.js';
import { createPersistence } from './course-persistence.js';
import { showToast } from '../modules/toast.js';
import { ConfirmModal } from '../modals/confirm-modal.js';

/* global jQuery, fs_lms_vars */
const $ = jQuery;

/**
 * CourseBuilder — Stepik-style конструктор курса (канон: design_handoff_course_builder/).
 * Дерево «модули → уроки» слева + редактор шагов выбранного урока справа.
 * Монтируется на #fs-lms-course-builder. Сохранение — AJAX (см. CourseBuilderCallbacks
 * + reuse SaveLessonSteps). Контент шага хранится в нашей модели LessonDTO.steps[].
 *
 * Типы шага (наш StepType): text, video, task (ссылка), work (ссылка), assessment (ссылка).
 */

// Редактор шагов (лента чипов + редактор шага + автосейв) вынесен в общий
// `step-editor.js` — единый источник UI для курс-билдера и метабокса урока.
const acts = () => fs_lms_vars.ajax_actions;

const GRIP_SVG = '<svg width="12" height="12" viewBox="0 0 12 12"><path fill="currentColor" d="M4 2.5h1v1H4zm3 0h1v1H7zM4 5.5h1v1H4zm3 0h1v1H7zM4 8.5h1v1H4zm3 0h1v1H7z"/></svg>';

export const CourseBuilder = {
	init() {
		const mount = document.getElementById( 'fs-lms-course-builder' );
		if ( mount ) {
			createApp( mount );
		}
	},
};

// ══════════════════════════════════════════════════════════════
function createApp( mount ) {
	const courseId = parseInt( mount.dataset.courseId, 10 ) || 0;
	const subject  = String( mount.dataset.subject || '' );

	const state = { course: null, activeLessonId: null, activeModuleId: null };
	let stepEditor = null; // активный экземпляр createStepEditor() для выбранного урока
	const persist  = createPersistence( { courseId, mount, state, onPublishToggle: () => renderEditor() } );

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
		renderShell();
		renderTree();
		renderEditor();
		autoHideNotices();
	}

	function autoHideNotices() {
		document.querySelectorAll( '.wrap > .notice, .wrap > .updated, .wrap > .error, .wrap > #message' )
			.forEach( ( n ) => {
				const isSave = n.id === 'message'
					|| n.classList.contains( 'notice-success' )
					|| n.classList.contains( 'notice-error' );

				if ( isSave ) {
					const type = n.classList.contains( 'notice-error' ) || n.classList.contains( 'error' )
						? 'error' : 'success';
					const text = ( n.querySelector( 'p' )?.textContent ?? n.textContent ).trim();
					if ( text ) { showToast( text, type ); }
				}

				n.remove();
			} );
	}

	function renderShell() {
		mount.innerHTML = `
			${ renderCourseStrip() }
			<div class="builder">
				<div class="tree-pane">
					<div class="tree-head">
						<span class="th-title">Структура курса</span>
						<span class="th-count" data-tree-count></span>
					</div>
					<div class="tree-scroll" data-tree></div>
					<div class="tree-add">
						<button type="button" class="button button-primary tree-add-main" data-add-lesson>
							<svg width="13" height="13" viewBox="0 0 20 20" fill="currentColor"><path d="M10 4v6H4v2h6v6h2v-6h6v-2h-6V4z"/></svg>
							Добавить урок
						</button>
						<div class="tree-add-row">
							<div class="import-wrap">
								<button type="button" class="button" data-import-toggle>
									<svg width="13" height="13" viewBox="0 0 20 20" fill="currentColor"><path d="M10 2 5 7h3v5h4V7h3l-5-5zM3 14h14v3a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1v-3z"/></svg>
									Импорт
									<svg class="import-caret" width="10" height="10" viewBox="0 0 12 12" fill="currentColor"><path d="M3 4.5 6 8l3-3.5z"/></svg>
								</button>
							</div>
							<button type="button" class="button" data-add-module>
								<svg width="13" height="13" viewBox="0 0 20 20" fill="currentColor"><path d="M3 3h6v6H3V3zm8 0h6v6h-6V3zM3 11h6v6H3v-6zm8 4h6v2h-6v-2zm2-2h2v-2h-2v2z"/></svg>
								Модуль
							</button>
						</div>
					</div>
				</div>
				<div class="editor-pane" data-editor></div>
			</div>`;

		mount.querySelector( '[data-add-lesson]' ).addEventListener( 'click', addLesson );
		mount.querySelector( '[data-add-module]' ).addEventListener( 'click', addModule );

		mount.querySelector( '[data-import-toggle]' ).addEventListener( 'click', ( e ) => {
			importLessonFlow( e.currentTarget );
		} );

		// Блокируем нативный submit формы — сохранение только через AJAX
		document.getElementById( 'post' )?.addEventListener( 'submit', ( e ) => e.preventDefault() );

		// Strip: просмотр курса на фронте
		mount.querySelector( '[data-strip-preview]' )?.addEventListener( 'click', ( e ) => {
			const id = e.currentTarget.dataset.courseId;
			window.open( `?p=${ id }&preview=true`, '_blank' );
		} );

		// Strip: "Опубликовать / Сохранить курс" → AJAX, без перезагрузки
		mount.querySelector( '[data-strip-publish]' )?.addEventListener( 'click', () => {
			const wasPublished = state.course.status === 'publish';
			state.course.status = 'publish';
			persist.saveCourseMeta().then( () => {
				const msg = wasPublished ? 'Курс сохранён' : 'Курс опубликован';
				showToast( msg, 'success' );
				const btn = mount.querySelector( '[data-strip-publish]' );
				if ( btn ) { btn.textContent = 'Сохранить курс'; }
			} );
		} );

		// Strip title: click-to-edit inline
		bindStripTitleEdit();
	}

	function bindStripTitleEdit() {
		const el = mount.querySelector( '[data-strip-title]' );
		if ( ! el ) { return; }
		el.addEventListener( 'click', startTitleEdit );
	}

	function startTitleEdit() {
		const el = mount.querySelector( '[data-strip-title]' );
		if ( ! el ) { return; }

		const prev  = state.course.title;
		const input = document.createElement( 'input' );
		input.type      = 'text';
		input.className = 'cs-title-input';
		input.value     = prev;
		el.replaceWith( input );
		input.focus();
		input.select();

		function commit() {
			const val = input.value.trim() || prev;
			state.course.title = val;

			// Keep WP native title field in sync (for form-save fallback)
			const wpTitle = document.getElementById( 'title' );
			if ( wpTitle ) { wpTitle.value = val; }

			// Restore text node
			const next = document.createElement( 'div' );
			next.className        = 'cs-title';
			next.dataset.stripTitle = '';
			next.textContent      = val;
			input.replaceWith( next );
			next.addEventListener( 'click', startTitleEdit );

			if ( val !== prev ) { persist.saveCourseMeta(); }
		}

		input.addEventListener( 'blur', commit );
		input.addEventListener( 'keydown', ( e ) => {
			if ( e.key === 'Enter' )  { e.preventDefault(); input.blur(); }
			if ( e.key === 'Escape' ) { input.value = prev; input.blur(); }
		} );
	}

	function renderCourseStrip() {
		const c         = state.course;
		const modCount  = c.modules.length;
		const lesCount  = totalLessons();
		const statusMap = { publish: 'Опубликован', draft: 'Черновик', private: 'Приватный' };
		const statusLabel = statusMap[ c.status ] || 'Черновик';

		// Thumbnail: img if available, otherwise 2-char initials badge
		const thumbHtml = c.thumbnail
			? `<img class="course-thumb-img" src="${ esc( c.thumbnail ) }" alt="">`
			: `<div class="course-thumb-initials">${ esc( c.title.slice( 0, 2 ).toUpperCase() ) }</div>`;

		return `
			<div class="course-strip">
				<div class="course-thumb">${ thumbHtml }</div>
				<div class="course-strip-main">
					<div class="cs-title" data-strip-title>${ esc( c.title ) }</div>
					<div class="cs-meta">
						<span><b data-module-count>${ modCount }</b> ${ modCount === 1 ? 'модуль' : 'модуля' }</span>
						<span><b data-lesson-count>${ lesCount }</b> ${ lesCount === 1 ? 'урок' : 'уроков' }</span>
						<span>Статус: <b>${ esc( statusLabel ) }</b></span>
						${ c.author_name ? `<span>Автор: <b>${ esc( c.author_name ) }</b></span>` : '' }
					</div>
				</div>
				<div class="course-strip-actions">
					<button type="button" class="button" data-strip-preview data-course-id="${ c.id }">
						<svg width="14" height="14" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg" style="vertical-align:-2px;margin-right:4px"><path d="M10 4C5.5 4 2 10 2 10s3.5 6 8 6 8-6 8-6-3.5-6-8-6zm0 10a4 4 0 1 1 0-8 4 4 0 0 1 0 8zm0-6a2 2 0 1 0 0 4 2 2 0 0 0 0-4z" fill="currentColor"/></svg>Просмотр
					</button>
					<button type="button" class="button button-green" data-strip-publish>
						${ 'publish' === c.status ? 'Сохранить курс' : 'Опубликовать курс' }
					</button>
				</div>
			</div>`;
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
			modEl.className = 'module' + ( mod.collapsed ? ' collapsed' : '' ) + ( mod.id === state.activeModuleId ? ' active' : '' );

			const head = document.createElement( 'div' );
			head.className = 'module-head';
			head.innerHTML = `
				<span class="mod-grip">${ GRIP_SVG }</span>
				<span class="mod-caret"><svg width="12" height="12" viewBox="0 0 12 12"><path fill="currentColor" d="M3 4.5 6 8l3-3.5z"/></svg></span>
				<span class="mod-num">${ mi + 1 }</span>
				<span class="mod-main"><span class="mod-title"></span><span class="mod-desc"></span></span>`;
			head.querySelector( '.mod-title' ).textContent = mod.title;
			const descEl = head.querySelector( '.mod-desc' );
			if ( mod.description ) { descEl.textContent = mod.description; } else { descEl.remove(); }
			head.querySelector( '.mod-caret' ).addEventListener( 'click', ( e ) => { e.stopPropagation(); mod.collapsed = ! mod.collapsed; renderTree(); } );
			head.addEventListener( 'click', () => selectModule( mod.id ) );
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
					<span class="les-grip">${ GRIP_SVG }</span>
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

	// ── drag&drop ──
	let dragLessonId = null;
	let dragModuleId = null;

	function attachDnD( el, { onStart, onEnd, isValidTarget, onDrop, containsCheck = false } ) {
		el.addEventListener( 'dragstart', ( e ) => {
			onStart();
			el.classList.add( 'dragging' );
			e.dataTransfer.effectAllowed = 'move';
			e.stopPropagation();
		} );
		el.addEventListener( 'dragend', () => {
			if ( onEnd ) { onEnd(); }
			el.classList.remove( 'dragging' );
			mount.querySelectorAll( '.drop-before,.drop-after' ).forEach( ( n ) => n.classList.remove( 'drop-before', 'drop-after' ) );
		} );
		el.addEventListener( 'dragover', ( e ) => {
			if ( ! isValidTarget() ) { return; }
			e.preventDefault(); e.stopPropagation();
			const r = el.getBoundingClientRect();
			const after = ( e.clientY - r.top ) > r.height / 2;
			el.classList.toggle( 'drop-after', after );
			el.classList.toggle( 'drop-before', ! after );
		} );
		el.addEventListener( 'dragleave', ( ev ) => {
			if ( containsCheck && el.contains( ev.relatedTarget ) ) { return; }
			el.classList.remove( 'drop-before', 'drop-after' );
		} );
		el.addEventListener( 'drop', ( e ) => {
			if ( ! isValidTarget() ) { return; }
			e.preventDefault(); e.stopPropagation();
			const r = el.getBoundingClientRect();
			const after = ( e.clientY - r.top ) > r.height / 2;
			el.classList.remove( 'drop-before', 'drop-after' );
			onDrop( after );
		} );
	}

	function attachLessonDrag( el, les, mod ) {
		attachDnD( el, {
			onStart:       () => { dragLessonId = les.id; dragModuleId = null; },
			onEnd:         () => { dragLessonId = null; },
			isValidTarget: () => ! dragModuleId && dragLessonId !== les.id,
			onDrop:        ( after ) => moveLesson( dragLessonId, mod.id, les.id, after ),
		} );
	}

	function attachModuleDrag( el, mod ) {
		attachDnD( el, {
			onStart:       () => { dragModuleId = mod.id; dragLessonId = null; },
			onEnd:         () => { dragModuleId = null; },
			isValidTarget: () => !! dragModuleId && dragModuleId !== mod.id,
			onDrop:        ( after ) => moveModule( dragModuleId, mod.id, after ),
			containsCheck: true,
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
		persist.saveStructure( 'Модуль перемещён' );
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
		persist.saveStructure( 'Урок перемещён' );
	}

	// ══════════ EDITOR ══════════
	function selectLesson( id ) {
		state.activeLessonId = id;
		state.activeModuleId = null;
		renderTree();
		renderEditor();
	}

	function selectModule( id ) {
		state.activeModuleId = id;
		state.activeLessonId = null;
		renderTree();
		renderEditor();
	}

	function renderEditor() {
		if ( stepEditor ) { stepEditor.destroy(); stepEditor = null; }
		const pane = mount.querySelector( '[data-editor]' );

		const f = state.activeLessonId ? findLesson( state.activeLessonId ) : null;
		if ( f ) { renderLessonEditor( pane, f ); return; }

		const mod = state.activeModuleId ? state.course.modules.find( ( m ) => m.id === state.activeModuleId ) : null;
		if ( mod ) { renderModuleEditor( pane, mod ); return; }

		pane.innerHTML = '<div class="editor-empty">Выберите урок или модуль слева</div>';
	}

	function renderLessonEditor( pane, f ) {
		const { lesson, module } = f;
		const mi = state.course.modules.indexOf( module ) + 1;
		const li = module.lessons.indexOf( lesson ) + 1;

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
					<button type="button" class="lesson-flag danger" data-les-del>Убрать урок</button>
				</div>
			</div>
			<div class="editor-body" data-step-mount></div>`;

		const titleInput = pane.querySelector( '[data-lesson-title]' );
		titleInput.addEventListener( 'input', () => {
			lesson.title = titleInput.value;
			mount.querySelectorAll( '.lesson.active .les-title' ).forEach( ( n ) => { n.textContent = lesson.title; } );
			persist.scheduleLessonMeta( lesson );
		} );
		pane.querySelector( '[data-toggle-publish]' ).addEventListener( 'click', () => persist.togglePublish( lesson ) );
		pane.querySelector( '[data-les-del]' ).addEventListener( 'click', () => removeLesson( lesson, module ) );

		// Единый редактор шагов (общий модуль). Статус автосейва — в подвал курс-билдера.
		stepEditor = createStepEditor( {
			mount:      pane.querySelector( '[data-step-mount]' ),
			lesson,
			subjectKey: state.course.subject_key,
			setStatus:  persist.setStatus,
		} );
	}

	// Страница модуля (как у урока, но без шагов): имя + описание + удаление.
	function renderModuleEditor( pane, mod ) {
		const mi = state.course.modules.indexOf( mod ) + 1;

		pane.innerHTML = `
			<div class="editor-top">
				<div class="editor-breadcrumb">
					<span>${ esc( state.course.title ) }</span><span>›</span>
					<b>Модуль ${ mi }</b>
				</div>
				<div class="lesson-title-row">
					<input class="lesson-title-input" data-mod-title value="${ esc( mod.title ) }" placeholder="Название модуля">
					<button type="button" class="lesson-flag danger" data-mod-del>Удалить модуль</button>
				</div>
			</div>
			<div class="editor-body">
				<div class="module-page">
					<div class="sb-label">Описание модуля (необязательно)</div>
					<textarea class="field-input module-desc" data-mod-desc rows="5" placeholder="Кратко о модуле…">${ esc( mod.description || '' ) }</textarea>
				</div>
			</div>`;

		const titleInput = pane.querySelector( '[data-mod-title]' );
		titleInput.addEventListener( 'input', () => {
			mod.title = titleInput.value;
			mount.querySelectorAll( '.module.active .mod-title' ).forEach( ( n ) => { n.textContent = mod.title; } );
			persist.scheduleStructure();
		} );
		const descInput = pane.querySelector( '[data-mod-desc]' );
		descInput.addEventListener( 'input', () => {
			mod.description = descInput.value;
			persist.scheduleStructure();
		} );
		pane.querySelector( '[data-mod-del]' ).addEventListener( 'click', () => deleteModule( mod ) );
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
		const mod = { id: tmpKey( 'm' ), title: 'Новый модуль', description: '', collapsed: false, lessons: [] };
		state.course.modules.push( mod );
		selectModule( mod.id ); // открыть страницу модуля — задать имя/описание
		persist.saveStructure( 'Модуль добавлен' );
	}

	async function removeLesson( lesson, module ) {
		try {
			await ConfirmModal.confirm( {
				title:       `Убрать урок «${ lesson.title }» из курса?`,
				message:     'Урок останется в библиотеке.',
				isDanger:    true,
				confirmText: 'Убрать',
			} );
		} catch { return; }

		module.lessons = module.lessons.filter( ( l ) => l.id !== lesson.id );
		if ( state.activeLessonId === lesson.id ) {
			const fl = firstLesson();
			state.activeLessonId = fl ? fl.id : null;
		}
		renderTree();
		renderEditor();
		persist.saveStructure( 'Урок убран из курса' );
	}

	async function deleteModule( mod ) {
		const note = mod.lessons.length
			? `Удалить модуль «${ mod.title }»? Уроки останутся в библиотеке, но будут убраны из курса.`
			: `Удалить модуль «${ mod.title }»?`;
		try {
			await ConfirmModal.confirm( { title: note, isDanger: true, confirmText: 'Удалить' } );
		} catch {
			return;
		}

		const idx = state.course.modules.findIndex( ( m ) => m.id === mod.id );
		if ( idx < 0 ) { return; }
		const removed = mod.lessons.map( ( l ) => l.id );
		state.course.modules.splice( idx, 1 );
		if ( state.activeModuleId === mod.id ) { state.activeModuleId = null; }
		if ( removed.includes( state.activeLessonId ) ) {
			const fl = firstLesson();
			state.activeLessonId = fl ? fl.id : null;
		}
		renderTree();
		renderEditor();
		persist.saveStructure( 'Модуль удалён' );
	}

	// ── импорт готового урока из библиотеки ──
	function importLessonFlow( anchor ) {
		const f   = findLesson( state.activeLessonId );
		const mod = f ? f.module : state.course.modules[ 0 ];
		if ( ! mod ) { showToast( 'Сначала добавьте модуль', 'error' ); return; }
		openLessonPicker( anchor, ( lessonId ) => importLesson( mod, lessonId ) );
	}

	function importLesson( mod, lessonId ) {
		if ( mod.lessons.some( ( l ) => l.id === lessonId ) ) {
			showToast( 'Урок уже в этом модуле', 'info' );
			return;
		}
		mod.lessons.push( { id: lessonId, title: '…', published: false, steps: [] } );
		mod.collapsed = false;
		renderTree();
		ajax( acts().saveCourseStructure, { course_id: courseId, modules: persist.structurePayload() } )
			.then( () => reloadTree( lessonId ) )
			.then( () => showToast( 'Урок добавлен в курс', 'success' ) )
			.catch( ( msg ) => showToast( msg, 'error' ) );
	}

	// Перезагрузка дерева с сервера (после импорта — чтобы подтянуть шаги урока).
	function reloadTree( selectLessonId ) {
		return ajax( acts().getCourseBuilder, { course_id: courseId } ).then( ( tree ) => {
			state.course = tree;
			if ( selectLessonId ) { state.activeLessonId = selectLessonId; }
			renderTree();
			renderEditor();
			const mc = mount.querySelector( '[data-module-count]' );
			const lc = mount.querySelector( '[data-lesson-count]' );
			if ( mc ) { mc.textContent = state.course.modules.length; }
			if ( lc ) { lc.textContent = totalLessons(); }
		} );
	}

	// ── пикер готового урока (reuse GetStepCandidates kind=lesson) ──
	function openLessonPicker( anchor, onPick ) {
		const inCourseIds = () => {
			const s = new Set();
			state.course.modules.forEach( ( m ) => m.lessons.forEach( ( l ) => s.add( l.id ) ) );
			return s;
		};
		openPicker( anchor, {
			placeholder: 'Поиск урока в библиотеке…',
			emptyText:   'Нет доступных уроков',
			placement:   'above',
			fetchFn:     ( search ) => ajax( acts().getStepCandidates, { subject_key: state.course.subject_key, kind: 'lesson', source: 'subject', search } )
				.then( ( items ) => items.filter( ( it ) => ! inCourseIds().has( parseInt( it.id, 10 ) ) ) ),
			onPick:      ( id ) => onPick( id ),
		} );
	}

}
